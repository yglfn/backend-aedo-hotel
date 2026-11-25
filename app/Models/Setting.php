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
        'hotel_photo_path',
        'default_check_in',
        'default_check_out',
        'tax_percentage',
        'min_dp_percentage',
        'payment_verification_enabled',
    ];

    protected function casts(): array
    {
        return [
            'tax_percentage'               => 'decimal:2',
            'min_dp_percentage'            => 'decimal:2',
            'payment_verification_enabled' => 'boolean',
        ];
    }

    /**
     * Always get the single settings row.
     */
    public static function instance(): static
    {
        return static::firstOrCreate(
            [],
            [
                'hotel_name' => 'Aedo Hotel Syariah',
                'default_check_in' => '14:00:00',
                'default_check_out' => '12:00:00',
                'tax_percentage' => 11,
                'payment_verification_enabled' => true,
            ]
        );
    }
}
