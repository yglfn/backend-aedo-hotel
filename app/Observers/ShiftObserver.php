<?php

namespace App\Observers;

use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Services\NotificationService;

class ShiftObserver
{
    public function created(Shift $shift): void
    {
        app(NotificationService::class)->shiftChanged($shift);
    }

    public function updated(Shift $shift): void
    {
        if (!$shift->wasChanged('status')) {
            return;
        }

        $previous = $shift->getOriginal('status');
        $previousStatus = $previous instanceof ShiftStatus ? $previous : ShiftStatus::from($previous);
        app(NotificationService::class)->shiftChanged($shift, $previousStatus);
    }
}
