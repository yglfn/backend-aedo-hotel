<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shift;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Exception;

class PaymentService
{
    public function __construct(protected InvoiceService $invoiceService) {}

    /**
     * Catat pembayaran manual (tunai/transfer/QRIS).
     */
    public function recordManualPayment(Booking $booking, array $data, int $userId): Payment
    {
        return DB::transaction(function () use ($booking, $data, $userId) {
            // Pastikan invoice ada
            $invoice = $booking->invoice ?? $this->invoiceService->createForBooking($booking);

            if ($invoice->status === InvoiceStatus::Void) {
                throw new Exception('Invoice sudah void, tidak bisa menambahkan pembayaran.');
            }

            $amount = (float) $data['amount'];
            $method = $data['method'];
            $type   = $data['type'] ?? PaymentType::Final->value;

            if ($type !== PaymentType::Refund->value) {

                // GUARD 1 — tolak jika sudah ada pembayaran pending (manual) bertipe sama
                // Mencegah duplikasi transfer/QRIS saat salah satu masih menunggu verifikasi admin.
                $existingPending = $booking->payments()
                    ->where('status', PaymentStatus::Pending->value)
                    ->where('type', $type)
                    ->where('method', '!=', PaymentMethod::PaymentGateway->value)
                    ->exists();

                if ($existingPending) {
                    $typeLabel = $type === PaymentType::Dp->value ? 'DP' : 'pelunasan';
                    throw new Exception(
                        "Sudah ada pembayaran {$typeLabel} berstatus pending untuk reservasi ini. " .
                        'Verifikasi atau tolak pembayaran tersebut sebelum mencatat pembayaran baru.'
                    );
                }

                // GUARD 2 — gunakan committed total (verified + pending) agar cash
                // tidak bisa menumpuk di atas transfer/QRIS yang masih pending.
                $committed = (float) $booking->payments()
                    ->whereIn('status', [PaymentStatus::Verified->value, PaymentStatus::Pending->value])
                    ->whereIn('type', [PaymentType::Dp->value, PaymentType::Final->value])
                    ->sum('amount');

                $availableRemaining = (float) $invoice->total - $committed;

                if ($amount > $availableRemaining) {
                    throw new Exception(
                        'Nominal melebihi sisa tagihan (sudah memperhitungkan pembayaran pending). ' .
                        'Sisa tersedia: Rp ' . number_format(max($availableRemaining, 0), 0, ',', '.')
                    );
                }
            }

            // Ambil shift aktif milik user (jika pegawai)
            $shiftId = Shift::where('user_id', $userId)
                ->where('status', 'open')
                ->value('id');

            // Tunai = langsung verified (tidak perlu verifikasi admin)
            // Transfer / QRIS manual = pending (menunggu verifikasi admin)
            $isCash     = $method === PaymentMethod::Cash->value;
            $status     = $isCash ? PaymentStatus::Verified->value : PaymentStatus::Pending->value;
            $paidAt     = $isCash ? now() : null;
            $verifiedBy = $isCash ? $userId : null;

            $payment = Payment::create([
                'booking_id'       => $booking->id,
                'invoice_id'       => $invoice->id,
                'shift_id'         => $shiftId,
                'created_by'       => $userId,
                'verified_by'      => $verifiedBy,
                'method'           => $method,
                'type'             => $type,
                'status'           => $status,
                'amount'           => $amount,
                'reference_number' => $data['reference_number'] ?? null,
                'notes'            => $data['notes'] ?? null,
                'paid_at'          => $paidAt,
            ]);

            // Cek apakah invoice sudah lunas (hanya hitung verified)
            $this->checkAndMarkInvoicePaid($booking->fresh(), $invoice->fresh());

            // Jika booking masih pending dan sudah ada pembayaran verified, konfirmasi otomatis
            $booking->refresh();
            if ($booking->status->value === 'pending' && $status === PaymentStatus::Verified->value) {
                $booking->update(['status' => 'confirmed']);
            }

            return $payment->load(['createdBy', 'verifiedBy', 'shift']);
        });
    }

    /**
     * Upload bukti pembayaran.
     */
    public function uploadProof(Payment $payment, UploadedFile $file): Payment
    {
        $path = $file->store('payment-proofs', 'public');
        $payment->update(['proof_of_payment' => $path]);
        return $payment->fresh();
    }

    /**
     * Verifikasi pembayaran (admin only).
     */
    public function verify(Payment $payment, int $adminId): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            throw new Exception('Hanya pembayaran berstatus Pending yang bisa diverifikasi.');
        }

        return DB::transaction(function () use ($payment, $adminId) {
            $payment->update([
                'status'      => PaymentStatus::Verified->value,
                'verified_by' => $adminId,
                'paid_at'     => now(),
            ]);

            $booking = $payment->booking->fresh();
            $invoice = $payment->invoice->fresh();

            $this->checkAndMarkInvoicePaid($booking, $invoice);

            // Konfirmasi booking jika masih pending
            if ($booking->status->value === 'pending') {
                $booking->update(['status' => 'confirmed']);
            }

            return $payment->fresh(['createdBy', 'verifiedBy']);
        });
    }

    /**
     * Tolak / batalkan pembayaran pending (admin only).
     * Set status → failed agar guard tidak lagi memblokir pencatatan baru.
     */
    public function reject(Payment $payment, int $adminId, ?string $reason = null): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            throw new Exception('Hanya pembayaran berstatus Pending yang bisa ditolak.');
        }

        $payment->update([
            'status'      => PaymentStatus::Failed->value,
            'verified_by' => $adminId,
            'notes'       => ($payment->notes ? $payment->notes . ' | ' : '') . 'Ditolak admin: ' . ($reason ?? 'tanpa alasan'),
        ]);

        return $payment->fresh(['createdBy', 'verifiedBy']);
    }

    /**
     * Cek apakah total pembayaran verified sudah menutup tagihan invoice.
     */
    protected function checkAndMarkInvoicePaid(Booking $booking, Invoice $invoice): void
    {
        $totalPaid = (float) $booking->payments()
            ->where('status', PaymentStatus::Verified->value)
            ->whereIn('type', [PaymentType::Dp->value, PaymentType::Final->value])
            ->sum('amount');

        $invoiceStatus = $invoice->status instanceof InvoiceStatus
            ? $invoice->status->value
            : $invoice->status;

        if ($totalPaid >= (float) $invoice->total && $invoiceStatus !== InvoiceStatus::Paid->value) {
            $this->invoiceService->markAsPaid($invoice);
        }
    }
}
