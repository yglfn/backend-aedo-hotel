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
        'provider',
        'gateway_transaction_id',
        'channel',
        'gateway_payload',
        'expires_at',
        'paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'method'          => PaymentMethod::class,
            'type'            => PaymentType::class,
            'status'          => PaymentStatus::class,
            'amount'          => 'decimal:2',
            'gateway_payload' => 'array',
            'expires_at'      => 'datetime',
            'paid_at'         => 'datetime',
        ];
    }

    protected $appends = [
        'gateway_token',
        'client_key',
        'snap_js_url',
        'redirect_url',
    ];

    public function getGatewayTokenAttribute(): ?string
    {
        return $this->gateway_payload['token'] ?? null;
    }

    public function getClientKeyAttribute(): ?string
    {
        return $this->gateway_payload['client_key'] ?? null;
    }

    public function getSnapJsUrlAttribute(): ?string
    {
        return $this->gateway_payload['snap_js_url'] ?? null;
    }

    public function getRedirectUrlAttribute(): ?string
    {
        return $this->gateway_payload['redirect_url'] ?? null;
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
