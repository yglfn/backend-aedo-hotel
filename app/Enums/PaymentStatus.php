<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending  = 'pending';
    case Verified = 'verified';
    case Failed   = 'failed';
    case Expired  = 'expired';

    public function label(): string
    {
        return match($this) {
            PaymentStatus::Pending  => 'Menunggu',
            PaymentStatus::Verified => 'Terverifikasi',
            PaymentStatus::Failed   => 'Gagal',
            PaymentStatus::Expired  => 'Kedaluwarsa',
        };
    }
}
