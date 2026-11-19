<?php

namespace App\Models;

use App\Enums\HousekeepingStatus;
use App\Enums\UserRole;
use App\Notifications\RoomNeedsCleaning;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HousekeepingTask extends Model
{
    protected static function booted(): void
    {
        static::created(function (HousekeepingTask $task): void {
            User::query()
                ->where('role', UserRole::Pegawai->value)
                ->where('is_active', true)
                ->get()
                ->each(fn (User $user) => $user->notify(new RoomNeedsCleaning($task)));
        });
    }

    protected $fillable = [
        'room_id',
        'booking_id',
        'assigned_to',
        'created_by',
        'status',
        'notes',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'       => HousekeepingStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
