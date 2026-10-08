<?php

namespace App\Models;

use App\Enums\RoomStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomStatusLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'room_id',
        'from_status',
        'to_status',
        'changed_by',
        'booking_id',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => RoomStatus::class,
            'to_status'   => RoomStatus::class,
            'created_at'  => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
