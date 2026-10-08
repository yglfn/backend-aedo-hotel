<?php

namespace App\Enums;

enum RoomStatus: string
{
    case Available   = 'available';
    case Booked      = 'booked';
    case Occupied    = 'occupied';
    case Cleaning    = 'cleaning';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match($this) {
            RoomStatus::Available   => 'Tersedia',
            RoomStatus::Booked      => 'Dipesan',
            RoomStatus::Occupied    => 'Terisi',
            RoomStatus::Cleaning    => 'Sedang Dibersihkan',
            RoomStatus::Maintenance => 'Perbaikan',
        };
    }
}
