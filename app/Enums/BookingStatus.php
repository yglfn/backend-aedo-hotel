<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Pending    = 'pending';
    case Confirmed  = 'confirmed';
    case CheckedIn  = 'checked_in';
    case CheckedOut = 'checked_out';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match($this) {
            BookingStatus::Pending    => 'Menunggu',
            BookingStatus::Confirmed  => 'Dikonfirmasi',
            BookingStatus::CheckedIn  => 'Check-In',
            BookingStatus::CheckedOut => 'Check-Out',
            BookingStatus::Cancelled  => 'Dibatalkan',
        };
    }
}
