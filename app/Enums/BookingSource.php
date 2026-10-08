<?php

namespace App\Enums;

enum BookingSource: string
{
    case WalkIn   = 'walk_in';
    case Phone    = 'phone';
    case Traveloka = 'traveloka';
    case Other    = 'other';

    public function label(): string
    {
        return match($this) {
            BookingSource::WalkIn    => 'Walk-In',
            BookingSource::Phone     => 'Telepon',
            BookingSource::Traveloka => 'Traveloka',
            BookingSource::Other     => 'Lainnya',
        };
    }
}
