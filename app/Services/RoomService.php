<?php

namespace App\Services;

use App\Enums\HousekeepingStatus;
use App\Enums\RoomStatus;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\User;

class RoomService
{
    /**
     * Change room status and log the change.
     */
    public function changeStatus(
        Room $room,
        RoomStatus $newStatus,
        ?User $changedBy = null,
        ?int $bookingId = null,
        ?string $reason = null
    ): Room {
        $oldStatus = $room->status;

        $room->update(['status' => $newStatus]);

        RoomStatusLog::create([
            'room_id'     => $room->id,
            'from_status' => $oldStatus,
            'to_status'   => $newStatus,
            'changed_by'  => $changedBy?->id,
            'booking_id'  => $bookingId,
            'reason'      => $reason,
            'created_at'  => now(),
        ]);

        // Jika kamar selesai dibersihkan -> tersedia
        if ($newStatus === RoomStatus::Available) {
            // Tandai housekeeping task terkait sebagai selesai
            HousekeepingTask::where('room_id', $room->id)
                ->where('status', HousekeepingStatus::InProgress)
                ->update([
                    'status'       => HousekeepingStatus::Completed,
                    'completed_at' => now(),
                ]);
        }

        return $room->fresh();
    }

    /**
     * Create housekeeping task for a room after checkout.
     */
    public function createHousekeepingTask(Room $room, ?int $bookingId = null, ?User $createdBy = null): HousekeepingTask
    {
        return HousekeepingTask::create([
            'room_id'    => $room->id,
            'booking_id' => $bookingId,
            'created_by' => $createdBy?->id ?? auth()->id(),
            'status'     => HousekeepingStatus::Pending,
        ]);
    }

    /**
     * Check if a room is available for a date range.
     * Uses booking_rooms dates for availability check.
     */
    public function isAvailable(int $roomId, string $checkIn, string $checkOut, ?int $excludeBookingId = null): bool
    {
        $query = \App\Models\BookingRoom::where('room_id', $roomId)
            ->whereHas('booking', function ($q) {
                $q->whereNotIn('status', ['cancelled', 'checked_out']);
            })
            ->where('check_in', '<', $checkOut)
            ->where('check_out', '>', $checkIn);

        if ($excludeBookingId) {
            $query->where('booking_id', '!=', $excludeBookingId);
        }

        return !$query->exists();
    }
}
