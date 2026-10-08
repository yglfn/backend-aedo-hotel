<?php

namespace App\Enums;

enum MaintenanceStatus: string
{
    case Reported   = 'reported';
    case InProgress = 'in_progress';
    case Resolved   = 'resolved';

    public function label(): string
    {
        return match($this) {
            MaintenanceStatus::Reported   => 'Dilaporkan',
            MaintenanceStatus::InProgress => 'Sedang Dikerjakan',
            MaintenanceStatus::Resolved   => 'Selesai',
        };
    }
}
