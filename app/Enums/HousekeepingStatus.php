<?php

namespace App\Enums;

enum HousekeepingStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Completed  = 'completed';

    public function label(): string
    {
        return match($this) {
            HousekeepingStatus::Pending    => 'Menunggu',
            HousekeepingStatus::InProgress => 'Sedang Dikerjakan',
            HousekeepingStatus::Completed  => 'Selesai',
        };
    }
}
