<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Exceptions\RoomUnavailableException;
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

        // status boleh satu nilai atau daftar terpisah koma (mis. pending,confirmed untuk tab check-in)
        if ($request->filled('status')) {
            $statuses = array_values(array_filter(array_map(
                fn ($value) => trim((string) $value),
                explode(',', (string) $request->input('status'))
            )));

            if (count($statuses) === 1) {
                $query->where('status', $statuses[0]);
            } elseif (count($statuses) > 1) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($request->filled('search')) {
            $term = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($sub) use ($term) {
                $sub->where('booking_code', 'like', $term)
                  ->orWhereHas('customer', function ($customer) use ($term) {
                      $customer->where('name', 'like', $term);
                  });
            });
        }

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));

        return response()->json([
            'data' => $query->latest()->paginate($perPage)->appends($request->query())
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
        } catch (RoomUnavailableException $e) {
            // Konflik ketersediaan kamar dibalas 409 (AGENTS.md bagian 6 butir 1)
            return response()->json([
                'message' => 'Kamar tidak tersedia pada tanggal tersebut.',
                'error'   => $e->getMessage(),
            ], 409);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Gagal membuat reservasi.',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function show(Booking $booking)
    {
        // Layar detail butuh riwayat bayar lengkap (siapa mencatat, siapa memverifikasi)
        // dan invoice aktif, bukan hanya daftar relasi inti.
        $booking->load([
            'customer',
            'bookingRooms.room.roomType',
            'bookingGuests',
            'bookingServices.service',
            'createdBy',
            'invoice',
            'payments.createdBy',
            'payments.verifiedBy',
        ]);

        return response()->json(['data' => $booking]);
    }

    public function confirm(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ], [
            'reason.max' => 'Alasan konfirmasi maksimal 255 karakter.',
        ]);

        try {
            $updatedBooking = $this->bookingService->confirm(
                $booking,
                $request->user()->id,
                $request->user()->isAdmin(),
                $validated['reason'] ?? null
            );

            return response()->json([
                'message' => 'Reservasi berhasil dikonfirmasi.',
                'data'    => $updatedBooking,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Gagal mengonfirmasi reservasi.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    public function checkIn(Request $request, Booking $booking)
    {
        // Penghuni yang belum tercatat saat reservasi boleh dikirim sekaligus di sini.
        $validated = $request->validate([
            'guests' => 'nullable|array',
            'guests.*.name' => 'required_with:guests|string|max:255',
            'guests.*.ktp' => 'nullable|string',
            'guests.*.phone' => 'nullable|string|max:20',
            'guests.*.room_id' => 'nullable|integer|exists:rooms,id',
        ], [
            'guests.*.name.required_with' => 'Nama penghuni wajib diisi.',
            'guests.*.room_id.exists' => 'Kamar penghuni harus salah satu kamar pada reservasi ini.',
        ]);

        try {
            $updatedBooking = $this->bookingService->checkIn($booking, $request->user()->id, $validated['guests'] ?? []);
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
