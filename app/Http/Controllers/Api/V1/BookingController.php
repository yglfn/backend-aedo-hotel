<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\CancelBookingRequest;
use Illuminate\Http\Request;
use Exception;

class BookingController extends Controller
{
    protected BookingService $bookingService;

    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }

    public function index(Request $request)
    {
        $query = Booking::with(['customer', 'bookingRooms.room', 'bookingGuests', 'payments', 'invoice']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('booking_code', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($q2) use ($search) {
                      $q2->where('name', 'like', "%{$search}%");
                  });
            });
        }

        return response()->json([
            'data' => $query->latest()->paginate(15)
        ]);
    }

    public function store(StoreBookingRequest $request)
    {
        try {
            $booking = $this->bookingService->createBooking($request->validated(), $request->user()->id);

            return response()->json([
                'message' => 'Reservasi berhasil dibuat.',
                'data' => $booking
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Gagal membuat reservasi.',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function show(Booking $booking)
    {
        $booking->load(['customer', 'bookingRooms.room.roomType', 'bookingGuests', 'bookingServices.service', 'createdBy']);
        return response()->json(['data' => $booking]);
    }

    public function checkIn(Request $request, Booking $booking)
    {
        try {
            $updatedBooking = $this->bookingService->checkIn($booking, $request->user()->id);
            return response()->json([
                'message' => 'Check-In berhasil.',
                'data' => $updatedBooking
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Gagal melakukan Check-In.',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function checkOut(Request $request, Booking $booking)
    {
        try {
            $updatedBooking = $this->bookingService->checkOut($booking, $request->user()->id);
            return response()->json([
                'message' => 'Check-Out berhasil.',
                'data' => $updatedBooking
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Gagal melakukan Check-Out.',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function cancel(CancelBookingRequest $request, Booking $booking)
    {
        try {
            $updatedBooking = $this->bookingService->cancel($booking, $request->cancel_reason, $request->user()->id);
            return response()->json([
                'message' => 'Reservasi berhasil dibatalkan.',
                'data' => $updatedBooking
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Gagal membatalkan reservasi.',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}
