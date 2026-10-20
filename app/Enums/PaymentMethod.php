<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash     = 'cash';
    case Transfer = 'transfer';
    case Qris     = 'qris';

    public function label(): string
    {
        return match($this) {
            PaymentMethod::Cash     => 'Tunai',
            PaymentMethod::Transfer => 'Transfer Bank',
            PaymentMethod::Qris     => 'QRIS',
        };
    }
}
