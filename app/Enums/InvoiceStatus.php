<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent  = 'sent';
    case Paid  = 'paid';
    case Void  = 'void';

    public function label(): string
    {
        return match($this) {
            InvoiceStatus::Draft => 'Draft',
            InvoiceStatus::Sent  => 'Terkirim',
            InvoiceStatus::Paid  => 'Lunas',
            InvoiceStatus::Void  => 'Batal',
        };
    }
}
