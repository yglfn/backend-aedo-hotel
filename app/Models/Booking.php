<?php

namespace App\Models;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_code',
        'customer_id',
        'created_by',
        'source',
        'guest_count',
        'check_in',
        'check_out',
        'status',
        'discount_amount',
        'total_price',
        'cancel_reason',
        'cancelled_at',
        'notes',
    ];

    /**
     * Ringkasan pembayaran dihitung di SATU tempat (model ini) supaya layar reservasi,
     * layar pembayaran, dan validasi backend memakai angka yang sama.
     *
     * Masalah lama: frontend hanya menghitung payment `verified` sehingga transfer/QRIS
     * yang masih `pending` dianggap belum bayar dan staf menagih ulang saat check-in.
     */
    protected $appends = [
        'paid_verified',
        'paid_pending',
        'invoice_total',
        'remaining_due',
        'dp_min_amount',
        'dp_shortfall',
        'has_pending_payment',
        'blocks_manual_payment',
    ];

    protected function casts(): array
    {
        return [
            'check_in'        => 'date',
            'check_out'       => 'date',
            'status'          => BookingStatus::class,
            'source'          => BookingSource::class,
            'discount_amount' => 'decimal:2',
            'total_price'     => 'decimal:2',
            'guest_count'     => 'integer',
            'cancelled_at'    => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bookingRooms(): HasMany
    {
        return $this->hasMany(BookingRoom::class);
    }

    public function rooms()
    {
        return $this->belongsToMany(Room::class, 'booking_rooms');
    }

    public function bookingGuests(): HasMany
    {
        return $this->hasMany(BookingGuest::class);
    }

    public function bookingServices(): HasMany
    {
        return $this->hasMany(BookingService::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->whereNotIn('status', ['void']);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * Get total verified payment amount.
     */
    public function getTotalPaidAttribute(): float
    {
        return (float) $this->payments()
            ->where('status', 'verified')
            ->whereIn('type', ['dp', 'final'])
            ->sum('amount');
    }

    public function getPaidVerifiedAttribute(): float
    {
        return $this->sumByStatus(PaymentStatus::Verified);
    }

    public function getPaidPendingAttribute(): float
    {
        return $this->sumByStatus(PaymentStatus::Pending);
    }

    /**
     * Tagihan aktif; invoice yang di-void tidak dihitung (relasi sudah menyaringnya).
     */
    public function getInvoiceTotalAttribute(): float
    {
        return (float) ($this->invoice?->total ?? $this->total_price);
    }

    /**
     * Sisa yang masih boleh ditagih = total - verified - pending.
     */
    public function getRemainingDueAttribute(): float
    {
        return round(max(0, $this->invoice_total - $this->paid_verified - $this->paid_pending), 2);
    }

    /**
     * Nominal DP minimum menurut pengaturan hotel (settings.min_dp_percentage).
     * 0 berarti DP tidak diwajibkan. Dihitung di sini supaya layar reservasi, layar
     * pembayaran, dan validasi backend menagih angka yang sama.
     */
    public function getDpMinAmountAttribute(): float
    {
        $percent = (float) (Setting::first()?->min_dp_percentage ?? 0);

        return $percent > 0 ? round($this->invoice_total * $percent / 100, 2) : 0.0;
    }

    /**
     * Kekurangan DP terhadap ambang minimum (0 bila sudah terpenuhi / tidak diwajibkan).
     * Sama seperti applyAutoConfirmation: uang masuk terverifikasi (dp maupun final) yang dinilai.
     */
    public function getDpShortfallAttribute(): float
    {
        $min = $this->dp_min_amount;

        return $min > 0 ? round(max(0, $min - $this->paid_verified), 2) : 0.0;
    }

    public function getHasPendingPaymentAttribute(): bool
    {
        return $this->payments->contains(
            fn (Payment $payment) => $payment->status === PaymentStatus::Pending
        );
    }

    /**
     * True bila ada pembayaran MANUAL (tunai/transfer/QRIS non-gateway) yang menunggu
     * verifikasi. Selama itu ada, penagihan baru harus ditolak supaya tidak bayar dobel.
     * QR gateway yang belum di-scan tidak memblokir (akan kedaluwarsa sendiri).
     */
    public function getBlocksManualPaymentAttribute(): bool
    {
        return $this->payments->contains(fn (Payment $payment) => $payment->status === PaymentStatus::Pending
            && $payment->method !== PaymentMethod::PaymentGateway);
    }

    private function sumByStatus(PaymentStatus $status): float
    {
        return round($this->payments
            ->filter(fn (Payment $payment) => $payment->status === $status
                && in_array($payment->type, [PaymentType::Dp, PaymentType::Final], true))
            ->sum('amount'), 2);
    }
}
