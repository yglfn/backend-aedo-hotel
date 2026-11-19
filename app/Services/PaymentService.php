<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Shift;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

                // GUARD 1 — satu klaim pembayaran dalam satu waktu.
                // Selama masih ada transfer/QRIS menunggu verifikasi admin,
                // pencatatan pembayaran baru ditolak supaya tamu
                // tidak ditagih dua kali untuk uang yang sama.
                $pendingManual = $booking->payments()
                    ->where('status', PaymentStatus::Pending->value)
                    ->first();

                if ($pendingManual) {
                    throw new Exception(
                        'Masih ada pembayaran menunggu verifikasi admin ('
                        . ($pendingManual->reference_number ?: 'Payment #' . $pendingManual->id)
                        . ' sebesar Rp ' . number_format((float) $pendingManual->amount, 0, ',', '.')
                        . '). Verifikasi atau tolak pembayaran itu sebelum mencatat pembayaran baru.'
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

            // Tunai = langsung verified. Transfer / QRIS manual = pending, lalu dicoba
            // diverifikasi OTOMATIS bila buktinya lengkap (pengganti approval admin).
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

            // Transfer/QRIS yang referensinya sudah tercatat lengkap tidak perlu menunggu
            // admin membuka sistem; kalau belum lengkap tetap pending untuk di-approve.
            if ($status === PaymentStatus::Pending->value) {
                $this->autoVerifyIfEligible($payment);
            }

            // Cek apakah invoice sudah lunas (hanya hitung verified)
            $this->checkAndMarkInvoicePaid($booking->fresh(), $invoice->fresh());

            // Konfirmasi otomatis dengan SATU aturan ambang DP (lihat helper di bawah)
            if ($payment->fresh()->status->value === PaymentStatus::Verified->value) {
                $this->applyAutoConfirmation($booking->fresh(), $invoice->fresh());
            }

            return $payment->fresh()->load(['createdBy', 'verifiedBy', 'shift']);
        });
    }

    /**
     * Upload bukti pembayaran.
     */
    public function uploadProof(Payment $payment, UploadedFile $file): Payment
    {
        $oldPath = $payment->proof_of_payment;
        $path = $file->store('payment-proofs', 'local');
        $payment->update(['proof_of_payment' => $path]);

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }

        $payment = $payment->fresh();

        // Momen bukti tiba adalah momen verifikasi otomatis bekerja
        $this->autoVerifyIfEligible($payment);

        return $payment->fresh()->load(['createdBy', 'verifiedBy', 'booking']);
    }

    /**
     * VERIFIKASI OTOMATIS berbasis bukti — pengganti persetujuan admin di depan.
     *
     * Semua syarat harus terpenuhi; kalau ada satu yang kurang pembayaran tetap
     * pending supaya admin bisa approve/reject seperti sebelumnya.
     *  - settings.auto_verify_transfers aktif
     *  - metode transfer / QRIS (tunai sudah otomatis, refund tidak pernah diverifikasi otomatis)
     *  - nomor referensi diisi DAN belum dipakai pembayaran lain (anti bukti daur ulang)
     *  - file bukti pembayaran sudah diunggah
     *  - nominal tidak membuat verified + pending melebihi tagihan
     *  - nominal tidak melewati plafon auto_verify_max_amount (0 = tanpa plafon)
     *
     * verified_by dibiarkan NULL sebagai penanda "sistem", dan setiap keputusan
     * otomatis ditulis ke audit log supaya masih bisa diaudit/dibatalkan admin.
     */
    public function autoVerifyIfEligible(Payment $payment): bool
    {
        $setting = Setting::first();

        $isAutoOn     = (bool) ($setting->auto_verify_transfers ?? false);
        $isManualBank = in_array($payment->method->value ?? $payment->method, [
            PaymentMethod::Transfer->value,
            PaymentMethod::Qris->value,
        ], true);
        $isDeposit    = in_array($payment->type->value ?? $payment->type, [
            PaymentType::Dp->value,
            PaymentType::Final->value,
        ], true);

        if (!$isAutoOn || $payment->status !== PaymentStatus::Pending || !$isManualBank || !$isDeposit) {
            return false;
        }

        $reference = trim((string) $payment->reference_number);

        if ($reference === '' || !$payment->proof_of_payment) {
            return false;
        }

        // Satu nomor referensi hanya boleh menjadi bukti satu pembayaran
        $referenceUsed = Payment::where('reference_number', $reference)
            ->whereKeyNot($payment->id)
            ->whereIn('status', [PaymentStatus::Verified->value, PaymentStatus::Pending->value])
            ->exists();

        if ($referenceUsed) {
            return false;
        }

        $ceiling = (float) ($setting->auto_verify_max_amount ?? 0);

        if ($ceiling > 0 && (float) $payment->amount > $ceiling) {
            return false;
        }

        $booking = $payment->booking;
        $invoice = $payment->invoice;

        if (!$booking || !$invoice) {
            return false;
        }

        $committedOthers = (float) $booking->payments()
            ->whereKeyNot($payment->id)
            ->whereIn('status', [PaymentStatus::Verified->value, PaymentStatus::Pending->value])
            ->whereIn('type', [PaymentType::Dp->value, PaymentType::Final->value])
            ->sum('amount');

        if ((float) $payment->amount + $committedOthers > (float) $invoice->total + 0.01) {
            return false;
        }

        $payment->update([
            'status'      => PaymentStatus::Verified->value,
            'paid_at'     => now(),
            'verified_by' => null,
            'notes'       => trim((string) $payment->notes) !== ''
                ? $payment->notes . ' | Diverifikasi otomatis (bukti + nomor referensi lengkap)'
                : 'Diverifikasi otomatis (bukti + nomor referensi lengkap)',
        ]);

        AuditService::log('payment.auto_verified', $payment, ['status' => PaymentStatus::Pending->value], [
            'status'    => PaymentStatus::Verified->value,
            'amount'    => (float) $payment->amount,
            'reference' => $reference,
        ]);

        $this->checkAndMarkInvoicePaid($booking->fresh(), $invoice->fresh());
        $this->applyAutoConfirmation($booking->fresh(), $invoice->fresh());

        return true;
    }

    /**
     * Reversal (post-audit): admin membatalkan pembayaran yang sudah terverifikasi.
     * Ini kontrol pengganti "approve di depan": sistem jalan otomatis, manusia mengawasi
     * pengecualian. Tidak bisa dilakukan bila tamu sudah check-out/dibatalkan.
     */
    public function reverse(Payment $payment, int $adminId, string $reason): Payment
    {
        if ($payment->status !== PaymentStatus::Verified) {
            throw new Exception('Hanya pembayaran berstatus verified yang bisa dibatalkan.');
        }

        $booking = $payment->booking;
        $bookingStatus = $booking?->status->value ?? null;

        if (in_array($bookingStatus, [BookingStatus::CheckedOut->value, BookingStatus::Cancelled->value], true)) {
            throw new Exception(
                'Reservasi sudah selesai/dibatalkan, pembayaran tidak bisa dibatalkan lewat sistem. Gunakan koreksi manual yang tercatat di audit.'
            );
        }

        return DB::transaction(function () use ($payment, $adminId, $reason, $booking) {
            $oldValues = [
                'status'  => PaymentStatus::Verified->value,
                'paid_at' => $payment->paid_at?->toDateTimeString(),
            ];

            $payment->update([
                'status'      => PaymentStatus::Failed->value,
                'paid_at'     => null,
                'verified_by' => $adminId,
                'notes'       => trim((string) $payment->notes) . ' | Dibatalkan admin: ' . $reason,
            ]);

            // Bila tagihan jadi kurang bayar, turunkan status invoice dari paid
            $invoice = $payment->invoice;

            if ($invoice && $invoice->status->value === InvoiceStatus::Paid->value && $booking) {
                $verifiedTotal = (float) $booking->payments()
                    ->where('status', PaymentStatus::Verified->value)
                    ->whereIn('type', [PaymentType::Dp->value, PaymentType::Final->value])
                    ->sum('amount');

                if ($verifiedTotal < (float) $invoice->total) {
                    $invoice->update(['status' => InvoiceStatus::Draft->value]);
                }
            }

            AuditService::log('payment.reversed', $payment, $oldValues, [
                'status' => PaymentStatus::Failed->value,
                'reason' => $reason,
            ]);

            return $payment->fresh(['createdBy', 'verifiedBy', 'shift']);
        });
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

            // Verifikasi transfer/QRIS manual juga tunduk ambang DP yang sama
            $this->applyAutoConfirmation($booking, $invoice);

            // Verifikasi pembayaran = aksi penting, wajib audit log (AGENTS.md bagian 6 butir 10)
            AuditService::log('payment.verified', $payment, ['status' => PaymentStatus::Pending->value], [
                'status'     => PaymentStatus::Verified->value,
                'amount'     => (float) $payment->amount,
                'booking_id' => $payment->booking_id,
                'invoice_id' => $payment->invoice_id,
            ]);

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

        AuditService::log('payment.rejected', $payment, ['status' => PaymentStatus::Pending->value], [
            'status'     => PaymentStatus::Failed->value,
            'amount'     => (float) $payment->amount,
            'booking_id' => $payment->booking_id,
            'reason'     => $reason,
        ]);

        return $payment->fresh(['createdBy', 'verifiedBy']);
    }

    /**
     * Naikkan reservasi pending -> confirmed bila pembayaran terverifikasi sudah memenuhi
     * ambang DP hotel (settings.min_dp_percentage; 0 berarti cukup ada pembayaran masuk).
     * Aturan ini dipakai SEMUA jalur pembayaran supaya status tidak bergantung pada metode bayar.
     */
    public function applyAutoConfirmation(Booking $booking, Invoice $invoice): void
    {
        if (!$booking || $booking->status->value !== BookingStatus::Pending->value) {
            return;
        }

        $minDpPct = (float) (Setting::first()?->min_dp_percentage ?? 0);
        $threshold = $minDpPct > 0 ? round((float) $invoice->total * $minDpPct / 100, 2) : 0.0;

        $paidVerified = (float) $booking->payments()
            ->where('status', PaymentStatus::Verified->value)
            ->whereIn('type', [PaymentType::Dp->value, PaymentType::Final->value])
            ->sum('amount');

        if ($paidVerified >= $threshold && $paidVerified > 0) {
            $booking->update(['status' => BookingStatus::Confirmed->value]);
        }
    }

    /**
     * Cek apakah total pembayaran verified sudah menutup tagihan invoice.
     */
    public function checkAndMarkInvoicePaid(Booking $booking, Invoice $invoice): void
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
