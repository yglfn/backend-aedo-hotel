<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Http\Request;

/**
 * PaymentResultController — layar hasil pembayaran untuk TAMU (publik, tanpa login).
 *
 * Destinasi dari callbacks.finish Midtrans. Prinsipnya sama seperti jalur gateway lain:
 * parameter URL tidak pernah dipercaya. Status diambil dari database kami, dan bila masih
 * pending backend bertanya sekali lagi ke gateway. Respons sengaja minimal — tanpa nama
 * tamu, telepon, KTP, nomor invoice, maupun id internal.
 */
class PaymentResultController extends Controller
{
    /** Field yang dikirim Midtrans lewat notifikasi; hanya ini yang dibaca dari URL. */
    private const NOTIFICATION_KEYS = [
        'order_id', 'transaction_id', 'status_code', 'transaction_status', 'gross_amount',
        'currency', 'payment_type', 'fraud_status', 'signature_key', 'settlement_time',
        'transaction_time', 'va_number', 'vendor_channel_code', 'merchant_id', 'qr_string',
    ];

    public function __construct(protected PaymentGatewayService $gatewayService) {}

    public function show(Request $request, string $orderId)
    {
        $payment = Payment::query()
            ->where('gateway_transaction_id', $orderId)
            ->where('method', PaymentMethod::PaymentGateway->value)
            ->with('booking:id,booking_code,status')
            ->first();

        if (!$payment) {
            return response()->json([
                'found'   => false,
                'message' => 'Transaksi tidak ditemukan.',
            ], 404);
        }

        // Sekali tanya ke gateway supaya tamu yang baru saja bayar tidak melihat status lama.
        // Urutan bukti, dari yang terkuat:
        // (1) Redirect Midtrans membawa parameter notifikasi + signature_key, yaitu
        //     SHA512(order_id + status_code + gross_amount + server key). Tanda tangan ini sekuat
        //     webhook - tanpa server key tidak bisa dipalsukan - jadi diproses lewat pipeline
        //     handleWebhook() yang sudah memakai cek nominal, idempotensi, dan audit. Ini yang
        //     membuat e-wallet (DANA) terverifikasi otomatis walau API status tidak mengenalinya.
        // (2) Kalau tidak ada tanda tangan, baru tanya status ke gateway (pakai transaction_id
        //     dari URL bila ada). Keputusan tetap dari provider, tidak pernah dari parameter URL.
        if ($payment->status !== PaymentStatus::Verified) {
            $redirect = $this->redirectNotificationParams($request, $orderId);

            if ($redirect !== null) {
                $this->gatewayService->handleWebhook((string) json_encode($redirect), []);
                $payment = $payment->fresh();
            }

            if ($payment->status !== PaymentStatus::Verified) {
                $result  = $this->gatewayService->syncStatus($payment, $this->providerReferenceFromRequest($request));
                $payment = $result['payment'] ?? $payment->fresh();
            }
        }

        $paymentStatus = $payment->status instanceof PaymentStatus
            ? $payment->status->value
            : (string) $payment->status;

        $booking = $payment->booking;

        $bookingStatus = match (true) {
            $booking === null          => null,
            $booking->status instanceof BookingStatus => $booking->status->value,
            default                    => (string) $booking->status,
        };

        $payload = is_array($payment->gateway_payload) ? $payment->gateway_payload : [];

        return response()->json([
            'found'          => true,
            'booking_code'   => $booking?->booking_code,
            'amount'         => round((float) $payment->amount, 2),
            'payment_status' => $paymentStatus,
            'booking_status' => $bookingStatus,
            'paid_at'        => $payment->paid_at?->toIso8601String(),
            'provider'       => $payment->provider,
            'environment'    => $payload['environment'] ?? null,
            'message'        => match ($paymentStatus) {
                PaymentStatus::Verified->value => 'Pembayaran terkonfirmasi.',
                PaymentStatus::Pending->value  => 'Pembayaran masih menunggu konfirmasi dari gateway.',
                PaymentStatus::Expired->value  => 'Batas waktu pembayaran lewat. Silakan buat pembayaran baru.',
                default                        => 'Pembayaran tidak berhasil.',
            },
        ]);
    }

    /**
     * Parameter redirect yang boleh dianggap sebagai notifikasi: harus ada signature_key dan
     * order_id-nya memang transaksi yang dibuka. Bentuk lain diabaikan (tidak ada aksi).
     *
     * @return array<string, string>|null
     */
    private function redirectNotificationParams(Request $request, string $orderId): ?array
    {
        if (trim((string) $request->query('signature_key', '')) === '') {
            return null;
        }

        $params = [];

        foreach (self::NOTIFICATION_KEYS as $key) {
            $value = $request->query($key);

            if (is_string($value) || is_numeric($value)) {
                $params[$key] = (string) $value;
            }
        }

        if (trim($params['order_id'] ?? '') !== $orderId) {
            return null;
        }

        return $params;
    }

    /**
     * Midtrans memakai nama parameter berbeda per kanal untuk redirect-nya (transaction_id,
     * trade_no, dsb.). Diambil yang mana pun ada dan bentuknya layak; isinya tetap tidak dipercaya.
     */
    private function providerReferenceFromRequest(Request $request): ?string
    {
        foreach (['transaction_id', 'transactionId', 'trade_no', 'provider_transaction_id'] as $key) {
            $value = trim((string) $request->query($key, ''));

            if ($value !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{5,63}$/', $value) === 1) {
                return $value;
            }
        }

        return null;
    }
}
