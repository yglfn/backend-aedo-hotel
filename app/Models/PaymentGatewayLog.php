<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentGatewayLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payment_id',
        'provider',
        'event_type',
        'payload',
        'headers',
        'signature_valid',
        'processed',
        'error_message',
        'received_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'headers'         => 'array',
            'signature_valid' => 'boolean',
            'processed'       => 'boolean',
            'received_at'     => 'datetime',
            'created_at'      => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
