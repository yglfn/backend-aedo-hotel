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
    ];

    protected function casts(): array
    {
        return [
            'tax_percentage'         => 'decimal:2',
            'min_dp_percentage'      => 'decimal:2',
            'auto_verify_transfers'  => 'boolean',
            'auto_verify_max_amount' => 'decimal:2',
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
