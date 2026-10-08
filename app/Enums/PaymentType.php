<?php

namespace App\Enums;

enum PaymentType: string
{
    case Dp     = 'dp';
    case Final  = 'final';
    case Refund = 'refund';

    public function label(): string
    {
        return match($this) {
            PaymentType::Dp     => 'Uang Muka (DP)',
            PaymentType::Final  => 'Pelunasan',
            PaymentType::Refund => 'Refund',
        };
    }
}
