<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'booking_id',
        'invoice_id',
        'shift_id',
        'created_by',
        'verified_by',
        'method',
        'type',
        'status',
        'amount',
        'reference_number',
        'proof_of_payment',
        'paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'method'     => PaymentMethod::class,
            'type'       => PaymentType::class,
            'status'     => PaymentStatus::class,
            'amount'     => 'decimal:2',
            'paid_at'    => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
