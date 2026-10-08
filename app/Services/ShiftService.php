<?php

namespace App\Services;

use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ShiftService
{
    public function startShift(User $user, ?string $note = null): Shift
    {
        // Pastikan tidak ada shift open lain
        $existing = Shift::where('user_id', $user->id)
            ->where('status', ShiftStatus::Open)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'shift' => ['Anda masih memiliki shift yang berjalan. Tutup shift dulu sebelum memulai yang baru.'],
            ]);
        }

        return Shift::create([
            'user_id'    => $user->id,
            'started_at' => now(),
            'status'     => ShiftStatus::Open,
            'note'       => $note,
        ]);
    }

    public function endShift(User $user, ?string $note = null): Shift
    {
        $shift = Shift::where('user_id', $user->id)
            ->where('status', ShiftStatus::Open)
            ->latest()
            ->firstOrFail();

        $shift->update([
            'ended_at' => now(),
            'status'   => ShiftStatus::Closed,
            'note'     => $note ?? $shift->note,
        ]);

        return $shift->fresh();
    }

    public function currentShift(User $user): ?Shift
    {
        return Shift::where('user_id', $user->id)
            ->where('status', ShiftStatus::Open)
            ->latest()
            ->first();
    }
}
