<?php

namespace App\Services\PaymentGateway;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentGatewayLog;
use App\Models\Setting;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Exception;

class PaymentGatewayService
{
    public function __construct(
        protected PaymentGatewayInterface $gateway,
        protected InvoiceService $invoiceService
    ) {}

    /**
     * Buat permintaan pembayaran gateway (QRIS / VA / e-wallet).
     */
    public function createGatewayPayment(Booking $booking, array $data, int $userId): Payment
    {
        return DB::transaction(function () use ($booking, $data, $userId) {
            $invoice = $booking->invoice ?? $this->invoiceService->createForBooking($booking);

            if ($invoice->status->value === InvoiceStatus::Paid->value) {
                throw new Exception('Invoice sudah lunas.');
            }

            $alreadyPaid = $booking->getTotalPaidAttribute();
            $remaining   = (float) $invoice->total - $alreadyPaid;

            if ($remaining <= 0) {
                throw new Exception('Tidak ada sisa tagihan yang perlu dibayar.');
            }

            $setting        = Setting::first();
            $expiryMinutes  = $setting?->payment_expiry_minutes ?? 60;
            $channel        = $data['channel'] ?? 'qris';

            // Panggil gateway untuk buat transaksi
            $gatewayResult = $this->gateway->createTransaction([
                'amount'          => $remaining,
                'channel'         => $channel,
                'expiry_minutes'  => $expiryMinutes,
                'booking_code'    => $booking->booking_code,
                'customer_name'   => $booking->customer->name,
            ]);

            $payment = Payment::create([
                'booking_id'             => $booking->id,
                'invoice_id'             => $invoice->id,
                'created_by'             => $userId,
                'method'                 => 'payment_gateway',
                'type'                   => PaymentType::Final->value,
                'status'                 => PaymentStatus::Pending->value,
                'amount'                 => $remaining,
                'provider'               => $this->gateway->getProviderName(),
                'gateway_transaction_id' => $gatewayResult['transaction_id'],
                'channel'                => $channel,
                'gateway_payload'        => $gatewayResult,
                'expires_at'             => now()->addMinutes($expiryMinutes),
            ]);

            return $payment;
        });
    }

    /**
     * Proses webhook dari gateway — idempotent & selalu log.
     */
    public function handleWebhook(string $rawPayload, array $headers): array
    {
        $signatureValid = $this->gateway->verifySignature($rawPayload, $headers);

        $decoded = json_decode($rawPayload, true) ?? [];
        $gatewayTransactionId = $decoded['transaction_id'] ?? null;
        $eventType            = $decoded['event']          ?? 'payment.unknown';

        // Selalu catat ke log terlebih dahulu
        $log = PaymentGatewayLog::create([
            'provider'        => $this->gateway->getProviderName(),
            'event_type'      => $eventType,
            'payload'         => $rawPayload,
            'headers'         => $this->sanitizeHeaders($headers),
            'signature_valid' => $signatureValid,
            'processed'       => false,
        ]);

        // Tolak jika signature tidak valid
        if (!$signatureValid) {
            $log->update(['error_message' => 'Signature tidak valid.']);
            return ['status' => 'rejected', 'message' => 'Invalid signature.'];
        }

        // Idempoten: cek apakah sudah pernah diproses
        $payment = Payment::where('gateway_transaction_id', $gatewayTransactionId)->first();

        if (!$payment) {
            $log->update(['error_message' => 'Payment dengan transaction_id tidak ditemukan.']);
            return ['status' => 'ignored', 'message' => 'Transaction not found.'];
        }

        // Update log dengan payment_id
        $log->update(['payment_id' => $payment->id]);

        // Jika sudah diproses sebelumnya (idempoten)
        if ($payment->status->value === PaymentStatus::Verified->value) {
            $log->update(['processed' => true, 'error_message' => 'Sudah diproses sebelumnya (idempoten).']);
            return ['status' => 'skipped', 'message' => 'Already processed.'];
        }

        // Proses berdasarkan event
        try {
            DB::transaction(function () use ($payment, $decoded, $log) {
                $gatewayStatus = $decoded['status'] ?? 'pending';

                if ($gatewayStatus === 'paid' || $gatewayStatus === 'settlement') {
                    $payment->update([
                        'status'      => PaymentStatus::Verified->value,
                        'paid_at'     => now(),
                    ]);

                    // Cek apakah invoice lunas
                    $booking = $payment->booking->fresh();
                    $invoice = $payment->invoice->fresh();

                    $totalPaid = (float) $booking->payments()
                        ->where('status', PaymentStatus::Verified->value)
                        ->whereIn('type', [PaymentType::Dp->value, PaymentType::Final->value])
                        ->sum('amount');

                    if ($totalPaid >= (float) $invoice->total) {
                        $this->invoiceService->markAsPaid($invoice);

                        // Konfirmasi booking jika masih pending
                        if ($booking->status->value === 'pending') {
                            $booking->update(['status' => 'confirmed']);
                        }
                    }
                } elseif ($gatewayStatus === 'expire' || $gatewayStatus === 'expired') {
                    $payment->update(['status' => PaymentStatus::Expired->value]);
                } elseif ($gatewayStatus === 'cancel' || $gatewayStatus === 'failure') {
                    $payment->update(['status' => PaymentStatus::Failed->value]);
                }

                $log->update(['processed' => true]);
            });

            return ['status' => 'processed', 'message' => 'Webhook processed successfully.'];
        } catch (Exception $e) {
            $log->update(['error_message' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Tandai payment gateway yang melewati expires_at menjadi expired.
     * Dipanggil oleh scheduler.
     */
    public function expireOverduePayments(): int
    {
        return Payment::where('method', 'payment_gateway')
            ->where('status', PaymentStatus::Pending->value)
            ->where('expires_at', '<', now())
            ->update(['status' => PaymentStatus::Expired->value]);
    }

    /**
     * Hapus header sensitif sebelum disimpan ke log.
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sensitive = ['authorization', 'cookie', 'x-api-key', 'x-secret'];
        return array_filter($headers, function ($key) use ($sensitive) {
            return !in_array(strtolower($key), $sensitive);
        }, ARRAY_FILTER_USE_KEY);
    }
}
