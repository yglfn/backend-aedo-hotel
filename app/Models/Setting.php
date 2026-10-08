<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'hotel_name',
        'hotel_address',
        'hotel_phone',
        'hotel_email',
        'default_check_in',
        'default_check_out',
        'tax_percentage',
        'payment_gateway_provider',
        'payment_gateway_config',
        'payment_expiry_minutes',
    ];

    protected function casts(): array
    {
        return [
            'tax_percentage'         => 'decimal:2',
            'payment_expiry_minutes' => 'integer',
        ];
    }

    /**
     * Always get the single settings row.
     */
    public static function instance(): static
    {
        return static::firstOrFail();
    }
}
