<?php

namespace App\Services\PaymentGateway;

interface PaymentGatewayInterface
{
    /**
     * Buat permintaan pembayaran ke gateway.
     * Mengembalikan array berisi info transaksi (transaction_id, qr_code/va_number, dll).
     */
    public function createTransaction(array $params): array;

    /**
     * Verifikasi signature dari webhook/callback payload.
     */
    public function verifySignature(string $payload, array $headers): bool;

    /**
     * Cek status transaksi ke gateway (untuk scheduler).
     */
    public function getTransactionStatus(string $gatewayTransactionId): array;

    /**
     * Nama provider (untuk logging).
     */
    public function getProviderName(): string;
}
