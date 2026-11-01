<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingGuest extends Model
{
    protected $fillable = [
        'booking_id',
        'booking_room_id',
        'name',
        'ktp',
        'phone',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'ktp'            => 'encrypted',
            'is_primary'     => 'boolean',
            'booking_room_id' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** Kamar tempat penghuni ini menginap (reservasi multi-kamar). */
    public function bookingRoom(): BelongsTo
    {
        return $this->belongsTo(BookingRoom::class);
    }
}
