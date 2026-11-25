<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function createForBooking(Booking $booking): Invoice
    {
        // Kembalikan invoice aktif jika sudah ada
        $existing = Invoice::where('booking_id', $booking->id)
            ->whereNotIn('status', [InvoiceStatus::Void->value])
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($booking) {
            $booking->load(['bookingRooms', 'bookingServices']);

            $subtotal = $booking->bookingRooms->sum('total_price')
                      + $booking->bookingServices->sum('total');

            $discount = (float) ($booking->discount_amount ?? 0);
            $setting  = Setting::first();
            $taxPct   = (float) ($setting?->tax_percentage ?? 0);
            $tax      = ($subtotal - $discount) * $taxPct / 100;
            $total    = $subtotal - $discount + $tax;

            return Invoice::create([
                'booking_id'     => $booking->id,
                'invoice_number' => 'INV-' . date('Y') . '-' . str_pad($booking->id, 5, '0', STR_PAD_LEFT),
                'subtotal'       => $subtotal,
                'discount'       => $discount,
                'tax'            => $tax,
                'total'          => $total,
                'status'         => InvoiceStatus::Draft->value,
            ]);
        });
    }

    public function recalculate(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $booking = $invoice->booking->load(['bookingRooms', 'bookingServices']);

            $subtotal = $booking->bookingRooms->sum('total_price')
                      + $booking->bookingServices->sum('total');

            $discount = (float) ($booking->discount_amount ?? 0);
            $setting  = Setting::first();
            $taxPct   = (float) ($setting?->tax_percentage ?? 0);
            $tax      = ($subtotal - $discount) * $taxPct / 100;
            $total    = $subtotal - $discount + $tax;

            $verifiedTotal = (float) $booking->payments()
                ->where('status', PaymentStatus::Verified->value)
                ->whereIn('type', [PaymentType::Dp->value, PaymentType::Final->value])
                ->sum('amount');

            $status = $invoice->status instanceof InvoiceStatus
                ? $invoice->status->value
                : $invoice->status;

            // Layanan baru dapat menaikkan tagihan setelah invoice sebelumnya lunas.
            // Status harus kembali draft agar kasir tidak menganggap sisa tambahan sudah dibayar.
            if ($status === InvoiceStatus::Paid->value && $verifiedTotal + 0.01 < $total) {
                $status = InvoiceStatus::Draft->value;
            } elseif ($status !== InvoiceStatus::Paid->value
                && $status !== InvoiceStatus::Void->value
                && $total > 0
                && $verifiedTotal + 0.01 >= $total) {
                $status = InvoiceStatus::Paid->value;
            }

            $booking->update(['total_price' => $total]);

            $invoice->update([
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax'      => $tax,
                'total'    => $total,
                'status'   => $status,
            ]);

            return $invoice->fresh();
        });
    }

    public function markAsPaid(Invoice $invoice): void
    {
        $invoice->update(['status' => InvoiceStatus::Paid->value]);
    }

    public function voidInvoice(Invoice $invoice): void
    {
        $invoice->update(['status' => InvoiceStatus::Void->value]);
    }
}
