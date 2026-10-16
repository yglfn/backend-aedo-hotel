<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentGateway\PaymentGatewayInterface;
use App\Services\PaymentGateway\PaymentGatewayService;
use App\Services\PaymentGateway\SimulatorGateway;
use Illuminate\Http\Request;

/**
 * SANDBOX payment gateway — meniru perilaku provider asli supaya alur
 * "QR tampil -> tamu bayar -> webhook -> lunas -> booking confirmed -> check-in"
 * bisa didemokan dan diuji sebelum provider sungguhan dipasang.
 *
 * Kenapa tetap aman:
 *  - 404 di produksi (app()->isProduction()) dan 404 bila provider aktif bukan sandbox
 *  - wajib login staf ATAU header X-Sandbox-Token (PG_SIMULATOR_SECRET), dibandingkan hash_equals
 *  - TIDAK mengubah status secara langsung: ia mengirim WEBHOOK BERTANDA TANGAN yang sah
 *    ke jalur pembayaran yang sama seperti provider asli, jadi cek signature, kecocokan
 *    nominal, dan idempotensi tetap berlaku (sandbox tidak kebal aturan)
 *  - hanya untuk transaction_id gateway yang benar-benar ada
 *  - di-throttle 30 request/menit per IP
 */
class GatewaySandboxController extends Controller
{
    public function __construct(
        protected PaymentGatewayService $gatewayService,
        protected PaymentGatewayInterface $gateway
    ) {}

    /**
     * POST /api/sandbox/gateway/{transactionId}/pay
     * Body (opsional): amount (untuk uji nominal salah), status: paid|settlement|expire|cancel
     */
    public function pay(Request $request, string $transactionId)
    {
        $sandbox = $this->authorizeSandbox($request);

        $payment = Payment::where('gateway_transaction_id', $transactionId)
            ->where('method', PaymentMethod::PaymentGateway->value)
            ->first();

        if (!$payment) {
            return response()->json([
                'message' => 'Transaksi sandbox tidak ditemukan.',
            ], 404);
        }

        $amount = $request->has('amount')
            ? (float) $request->input('amount')
            : (float) $payment->amount;

        $status = (string) $request->input('status', 'paid');
        $status = in_array($status, ['paid', 'settlement', 'expire', 'expired', 'cancel'], true) ? $status : 'paid';

        $webhook = $sandbox->makeSettlementWebhook($transactionId, $amount, $status);

        // Masuk lewat pintu yang sama dengan provider asli — tidak ada jalur pintas
        $result = $this->gatewayService->handleWebhook($webhook['payload'], $webhook['headers']);

        $payment->refresh();

        $resultStatus = $result['status'] ?? 'error';

        return response()->json([
            'message'        => $this->messageFor($resultStatus),
            'webhook_status' => $resultStatus,
            'payment_status' => $payment->status->value,
            'booking_status' => $payment->booking?->status->value,
            'invoice_status' => $payment->invoice?->status->value,
            'data'           => $payment,
        ], $resultStatus === 'processed' ? 200 : 422);
    }

    private function authorizeSandbox(Request $request): SimulatorGateway
    {
        $gateway = $this->gateway;

        // Sandbox tidak boleh tersentuh di produksi maupun saat provider asli terpasang
        if (app()->isProduction() || !$gateway instanceof SimulatorGateway) {
            abort(404);
        }

        $token  = (string) $request->header('X-Sandbox-Token', '');
        $secret = (string) config('services.pg_simulator.secret', '');
        $hasToken = $secret !== '' && hash_equals($secret, $token);

        // Token dipakai untuk Postman/cURL. Dari aplikasi, staf yang sudah login boleh
        // menekan tombol "Bayar (Sandbox)" tanpa membawa secret ke browser (secret tidak
        // pernah dikirim ke frontend). Semua tetap non-produksi + lewat jalur webhook.
        if (!$hasToken && !$request->user()) {
            abort(401, 'Sandbox butuh login staf atau header X-Sandbox-Token.');
        }

        return $gateway;
    }

    private function messageFor(string $resultStatus): string
    {
        return match ($resultStatus) {
            'processed'       => 'Sandbox mengirim settlement; pembayaran diproses lewat jalur webhook normal.',
            'skipped'         => 'Transaksi sudah pernah dilunasi (webhook idempoten).',
            'amount_mismatch' => 'Nominal tidak sama dengan tagihan — webhook ditolak, status pembayaran tidak diubah.',
            'rejected'        => 'Signature sandbox ditolak.',
            'ignored'         => 'Transaksi tidak dikenali.',
            default           => 'Sandbox tidak dapat memproses transaksi ini.',
        };
    }
}
