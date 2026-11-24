<?php

namespace App\Observers;

use App\Enums\HousekeepingStatus;
use App\Models\HousekeepingTask;
use App\Services\NotificationService;

class HousekeepingTaskObserver
{
    public function created(HousekeepingTask $task): void
    {
        app(NotificationService::class)->housekeepingCreated($task);
    }

    public function updated(HousekeepingTask $task): void
    {
        if (!$task->wasChanged('status') || $task->status !== HousekeepingStatus::Completed) {
            return;
        }

        $previous = $task->getOriginal('status');
        $previousStatus = $previous instanceof HousekeepingStatus ? $previous : HousekeepingStatus::from($previous);

        if ($previousStatus !== HousekeepingStatus::Completed) {
            app(NotificationService::class)->housekeepingCompleted($task);
        }
    }
}
