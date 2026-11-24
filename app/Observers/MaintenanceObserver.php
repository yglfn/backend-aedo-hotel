<?php

namespace App\Observers;

use App\Enums\MaintenanceStatus;
use App\Models\Maintenance;
use App\Services\NotificationService;

class MaintenanceObserver
{
    public function created(Maintenance $maintenance): void
    {
        app(NotificationService::class)->maintenanceCreated($maintenance);
    }

    public function updated(Maintenance $maintenance): void
    {
        $service = app(NotificationService::class);

        if ($maintenance->wasChanged('assigned_to') && $maintenance->assigned_to) {
            $service->maintenanceAssigned($maintenance);
        }

        if (!$maintenance->wasChanged('status')) {
            return;
        }

        $previous = $maintenance->getOriginal('status');
        $previousStatus = $previous instanceof MaintenanceStatus ? $previous : MaintenanceStatus::from($previous);
        $service->maintenanceStatusChanged($maintenance, $previousStatus);
    }
}
