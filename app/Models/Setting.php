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
        'min_dp_percentage',
        'auto_verify_transfers',
        'auto_verify_max_amount',
        'payment_gateway_provider',
        'payment_gateway_config',
        'payment_expiry_minutes',
    ];

    /**
     * Kredensial gateway tidak boleh ikut terserialisasi ke respons API mana pun
     * (AGENTS.md bagian 7: secret jangan dikirim ke frontend).
     */
    protected $hidden = [
        'payment_gateway_config',
    ];

    protected function casts(): array
    {
        return [
            'tax_percentage'         => 'decimal:2',
            'min_dp_percentage'      => 'decimal:2',
            'auto_verify_transfers'  => 'boolean',
            'auto_verify_max_amount' => 'decimal:2',
            'payment_expiry_minutes' => 'integer',
            // Disimpan terenkripsi di database, hanya admin yang menulisnya
            'payment_gateway_config' => 'encrypted:array',
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
