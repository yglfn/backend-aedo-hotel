<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(protected InvoiceService $invoiceService) {}

    public function index(Request $request)
    {
        $query = Invoice::with(['booking.customer'])->latest();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(['data' => $query->paginate(15)]);
    }

    public function show(Invoice $invoice)
    {
        $invoice->load([
            'booking.customer',
            'booking.bookingRooms.room.roomType',
            'booking.bookingServices.service',
            'booking.bookingGuests',
            'payments.createdBy',
            'payments.verifiedBy',
        ]);

        return response()->json(['data' => $invoice]);
    }

    /**
     * Buat atau ambil invoice yang sudah ada untuk booking tertentu.
     */
    public function createForBooking(Booking $booking)
    {
        $invoice = $this->invoiceService->createForBooking($booking);
        $invoice->load(['booking.customer', 'payments']);

        return response()->json([
            'message' => 'Invoice berhasil dibuat.',
            'data'    => $invoice,
        ], 201);
    }

    /**
     * Hitung ulang total invoice berdasarkan data booking terbaru.
     */
    public function recalculate(Invoice $invoice)
    {
        if ($invoice->status->value === 'paid') {
            return response()->json([
                'message' => 'Invoice yang sudah lunas tidak bisa dihitung ulang.',
            ], 422);
        }

        $updated = $this->invoiceService->recalculate($invoice);

        return response()->json([
            'message' => 'Invoice berhasil dihitung ulang.',
            'data'    => $updated,
        ]);
    }
}
