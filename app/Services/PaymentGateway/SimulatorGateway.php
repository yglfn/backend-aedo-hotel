<?php

namespace App\Services\PaymentGateway;

use Illuminate\Support\Str;

/**
 * Simulator Gateway — untuk keperluan sandbox/testing.
 * Tidak memanggil API eksternal apapun.
 * Signature menggunakan HMAC-SHA256 dengan PG_SIMULATOR_SECRET dari .env.
 */
class SimulatorGateway implements PaymentGatewayInterface
{
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
     * Buat transaksi simulasi.
     * Mengembalikan QR string palsu atau nomor VA palsu tergantung channel.
     */
    public function createTransaction(array $params): array
    {
        $transactionId = 'SIM-' . strtoupper(Str::random(12));
        $channel       = $params['channel'] ?? 'qris';
        $amount        = $params['amount'];
        $expiresAt     = now()->addMinutes($params['expiry_minutes'] ?? 60);

        $payload = match ($channel) {
            'va'     => [
                'transaction_id' => $transactionId,
                'channel'        => 'va',
                'va_number'      => '8008' . str_pad(rand(1, 99999999), 8, '0', STR_PAD_LEFT),
                'bank'           => 'BCA',
                'amount'         => $amount,
                'expires_at'     => $expiresAt->toISOString(),
            ],
            'ewallet' => [
                'transaction_id' => $transactionId,
                'channel'        => 'ewallet',
                'deeplink'       => 'https://simulator.aedohotel.test/pay/' . $transactionId,
                'amount'         => $amount,
                'expires_at'     => $expiresAt->toISOString(),
            ],
            default => [ // qris
                'transaction_id' => $transactionId,
                'channel'        => 'qris',
                'qr_string'      => 'SIMULATOR_QR_' . $transactionId . '_AMOUNT_' . $amount,
                'amount'         => $amount,
                'expires_at'     => $expiresAt->toISOString(),
            ],
        };

        return $payload;
    }

    /**
     * Verifikasi signature webhook.
     * Format header: X-Simulator-Signature: hmac-sha256=<hash>
     */
    public function verifySignature(string $payload, array $headers): bool
    {
        $received = $headers['x-simulator-signature']
            ?? $headers['X-Simulator-Signature']
            ?? '';

        // Hapus prefix "hmac-sha256=" jika ada
        $received = str_replace('hmac-sha256=', '', $received);

        $expected = hash_hmac('sha256', $payload, $this->secret);

        return hash_equals($expected, $received);
    }

    /**
     * Cek status transaksi simulasi.
     * Untuk keperluan scheduler — di simulator selalu kembalikan pending
     * (pembayaran hanya bisa dikonfirmasi via webhook manual).
     */
    public function getTransactionStatus(string $gatewayTransactionId): array
    {
        return [
            'transaction_id' => $gatewayTransactionId,
            'status'         => 'pending',
            'provider'       => $this->getProviderName(),
        ];
    }
}
