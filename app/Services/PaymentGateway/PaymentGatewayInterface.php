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
     * Cek status transaksi ke gateway (untuk scheduler / tombol "Cek Status").
     * Nilai 'status' yang boleh keluar: paid | pending | expired | failed.
     */
    public function getTransactionStatus(string $gatewayTransactionId): array;

    /**
     * Normalisasi isi notifikasi provider menjadi bahasa internal sistem:
     *   transaction_id : kunci pencarian payment kita (mis. order_id Midtrans)
     *   status         : paid | pending | expired | failed
     *   amount         : nominal yang dilaporkan provider (float|null)
     *   event          : label untuk payment_gateway_logs
     *
     * Dipanggil SETELAH verifySignature() lolos, jadi tidak ada data provider
     * yang dipercaya sebelum diverifikasi.
     */
    public function normalizeNotification(array $decoded): array;

    /**
     * Nama provider (untuk logging).
     */
    public function getProviderName(): string;
}
