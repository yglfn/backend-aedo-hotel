<?php

namespace App\Enums;

enum ShiftStatus: string
{
    case Open   = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match($this) {
            ShiftStatus::Open   => 'Berjalan',
            ShiftStatus::Closed => 'Selesai',
        };
    }
}
