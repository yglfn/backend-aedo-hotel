<?php

namespace App\Services\PaymentGateway;

use App\Models\PaymentGatewayLog;
use Illuminate\Support\Str;

/**
 * Simulator / SANDBOX gateway — pengganti provider asli selama keputusan
 * [TENTUKAN] soal provider di AGENTS.md bagian 7 belum ditutup.
 *
 * Perilakunya sengaja disamakan dengan provider asli supaya tidak ada kejutan saat dipasang yang nyata:
 *  - transaksi punya transaction_id, QR/VA/deeplink, nominal, dan masa berlaku
 *  - pelunasan hanya diberitahukan lewat webhook ber-signature HMAC-SHA256
 *    (kanonik "<timestamp>.<body>" + jendela waktu anti-replay)
 *  - status bisa ditanya: getTransactionStatus() (dipakai tombol "Cek Status" & scheduler)
 *
 * Yang TIDAK dilakukan: memanggil API luar, dan menandai lunas tanpa bukti webhook.
 * Sandbox tidak boleh dipakai di produksi (dijaga di AppServiceProvider).
 */
class SimulatorGateway implements PaymentGatewayInterface
{
    /** Berapa lama webhook masih diterima (detik). Menahan replay dari log yang bocor. */
    public const SIGNATURE_TOLERANCE = 300;

    protected string $secret;

    public function __construct()
    {
        $this->secret = config('services.pg_simulator.secret', 'simulator-secret-key');
    }

    public function getProviderName(): string
    {
        return 'simulator';
    }

    /**
     * Buat transaksi simulasi: QR string / nomor VA / deeplink palsu tapi bentuknya seperti asli.
     */
    public function createTransaction(array $params): array
    {
        $transactionId = 'SIM-' . strtoupper(Str::random(12));
        $channel       = $params['channel'] ?? 'qris';
        $amount        = (float) $params['amount'];
        $expiresAt     = now()->addMinutes($params['expiry_minutes'] ?? 60);

        $base = [
            'transaction_id' => $transactionId,
            'channel'        => $channel,
            'amount'         => $amount,
            'currency'       => 'IDR',
            'status'         => 'pending',
            'expires_at'     => $expiresAt->toISOString(),
            'environment'    => 'sandbox',
        ];

        return match ($channel) {
            'auto' => $base + [
                // Mock perilaku Snap "pilih sendiri": metode mana saja yang akan ditawarkan
                'methods_available' => ['qris', 'bca_va', 'dana'],
                'qr_string'         => 'SIMULATOR_QR_' . $transactionId . '_AMOUNT_' . $amount,
            ],
            'va' => $base + [
                'va_number' => '8008' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
                'bank'      => 'BCA (sandbox)',
            ],
            'ewallet' => $base + [
                'deeplink' => 'https://sandbox.aedohotel.test/pay/' . $transactionId,
            ],
            default => $base + [
                'qr_string' => 'SIMULATOR_QR_' . $transactionId . '_AMOUNT_' . $amount,
            ],
        };
    }

    /**
     * Verifikasi signature webhook.
     *
     * Header: X-Simulator-Signature: hmac-sha256=<hash>  (prefix opsional)
     *         X-Simulator-Timestamp: <unix detik>          (opsional, tapi selalu dikirim sandbox)
     *
     * Bila timestamp ada: kanonik = "<timestamp>.<body>" dan webhook terlalu basi ditolak.
     * Bila tidak ada: fallback ke HMAC atas body saja supaya tool lama (Postman collection)
     * tetap bisa dipakai — idempotensi di service tetap mencegah replay mengubah status.
     */
    public function verifySignature(string $payload, array $headers): bool
    {
        $received = $this->headerValue($headers, 'x-simulator-signature');

        if ($received === '') {
            return false;
        }

        $received  = str_replace('hmac-sha256=', '', $received);
        $timestamp = $this->headerValue($headers, 'x-simulator-timestamp');

        if ($timestamp !== '' && abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $canonical = $timestamp !== '' ? $timestamp . '.' . $payload : $payload;

        return hash_equals(hash_hmac('sha256', $canonical, $this->secret), $received);
    }

    /**
     * Bangun webhook settlement yang sah (dipakai endpoint sandbox & automated test),
     * sehingga jalur "tandai lunas" selalu lewat verifikasi signature — tidak ada jalur pintas.
     */
    public function makeSettlementWebhook(string $transactionId, float $amount, string $status = 'paid'): array
    {
        $timestamp = (string) time();

        $payload = json_encode([
            'transaction_id' => $transactionId,
            'event'          => in_array($status, ['paid', 'settlement'], true) ? 'payment.settled' : 'payment.expired',
            'status'         => $status,
            'amount'         => $amount,
            'currency'       => 'IDR',
            'provider'       => $this->getProviderName(),
            'settled_at'     => now()->toIso8601String(),
        ]);

        return [
            'payload' => $payload,
            'headers' => [
                'X-Simulator-Signature' => 'hmac-sha256=' . hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret),
                'X-Simulator-Timestamp' => $timestamp,
                'Content-Type'          => 'application/json',
            ],
        ];
    }

    /**
     * Cek status transaksi (dipakai polling/scheduler).
     *
     * Satu-satunya "bukti bayar" di sandbox adalah webhook yang sudah masuk dan lolos
     * signature — polling hanya MEMBACA ULANG bukti itu (self-healing kalau webhook-nya
     * tertinggal). Tidak ada kondisi di mana polling membuat transaksi jadi lunas sendiri.
     */
    public function getTransactionStatus(string $gatewayTransactionId): array
    {
        $evidence = PaymentGatewayLog::query()
            ->where('provider', $this->getProviderName())
            ->where('signature_valid', true)
            ->where('payload', 'like', '%' . $gatewayTransactionId . '%')
            ->orderByDesc('id')
            ->first();

        $status = 'pending';

        if ($evidence) {
            $decoded = json_decode((string) $evidence->payload, true) ?? [];

            if (($decoded['transaction_id'] ?? null) === $gatewayTransactionId
                && in_array($decoded['status'] ?? '', ['paid', 'settlement'], true)) {
                $status = 'paid';
            } elseif (($decoded['transaction_id'] ?? null) === $gatewayTransactionId
                && in_array($decoded['status'] ?? '', ['expire', 'expired', 'cancel', 'failure'], true)) {
                $status = 'expired';
            }
        }

        return [
            'transaction_id' => $gatewayTransactionId,
            'status'         => $status,
            'provider'       => $this->getProviderName(),
        ];
    }

    /**
     * Notifikasi simulator sudah memakai bahasa internal sistem, jadi normalisasinya
     * hanya pemetaan status + pembacaan nominal.
     */
    public function normalizeNotification(array $decoded): array
    {
        $status = (string) ($decoded['status'] ?? 'pending');

        $mapped = match (true) {
            in_array($status, ['paid', 'settlement'], true) => 'paid',
            in_array($status, ['expire', 'expired'], true)  => 'expired',
            in_array($status, ['cancel', 'failure', 'failed'], true) => 'failed',
            default => 'pending',
        };

        return [
            'transaction_id' => isset($decoded['transaction_id']) ? (string) $decoded['transaction_id'] : null,
            // Simulator tidak punya id kedua; form ini tetap dipenuhi supaya service seragam
            'provider_transaction_id' => isset($decoded['transaction_id']) ? (string) $decoded['transaction_id'] : null,
            'status'         => $mapped,
            'amount'         => isset($decoded['amount']) ? round((float) $decoded['amount'], 2) : null,
            'event'          => (string) ($decoded['event'] ?? 'payment.' . $status),
        ];
    }

    /** Ambil nilai header tanpa peduli kapitalisasi (Symfony menormalkan ke lowercase). */
    private function headerValue(array $headers, string $key): string
    {
        $value = $headers[$key] ?? $headers[str_replace(' ', '-', ucwords(str_replace('-', ' ', $key)))] ?? '';

        if (is_array($value)) {
            $value = $value[0] ?? '';
        }

        return (string) $value;
    }
}
