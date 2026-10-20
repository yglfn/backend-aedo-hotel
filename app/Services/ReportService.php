<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\HousekeepingTask;
use App\Models\Payment;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Validasi & normalisasi rentang tanggal.
     */
    protected function parseRange(?string $from, ?string $to): array
    {
        $from = $from ? Carbon::parse($from)->startOfDay() : now()->startOfMonth();
        $to   = $to   ? Carbon::parse($to)->endOfDay()     : now()->endOfDay();
        return [$from, $to];
    }

    // ---------------------------------------------------------------
    // 1. LAPORAN OKUPANSI
    // ---------------------------------------------------------------
    public function occupancy(?string $from, ?string $to): array
    {
        [$from, $to] = $this->parseRange($from, $to);

        $totalRooms = Room::whereNull('deleted_at')->count();
        $totalNights = $from->diffInDays($to) + 1;
        $totalRoomNights = $totalRooms * $totalNights;

        $occupiedNights = DB::table('booking_rooms')
            ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->whereNotIn('bookings.status', [BookingStatus::Cancelled->value])
            ->where('booking_rooms.check_in', '<', $to)
            ->where('booking_rooms.check_out', '>', $from)
            ->sum('booking_rooms.nights');

        $occupancyRate = $totalRoomNights > 0
            ? round(($occupiedNights / $totalRoomNights) * 100, 2)
            : 0;

        // Per kamar
        $perRoom = DB::table('booking_rooms')
            ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->join('rooms', 'booking_rooms.room_id', '=', 'rooms.id')
            ->whereNotIn('bookings.status', [BookingStatus::Cancelled->value])
            ->where('booking_rooms.check_in', '<', $to)
            ->where('booking_rooms.check_out', '>', $from)
            ->select('rooms.room_number', DB::raw('SUM(booking_rooms.nights) as occupied_nights'))
            ->groupBy('rooms.id', 'rooms.room_number')
            ->orderByDesc('occupied_nights')
            ->get();

        return [
            'period'           => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_rooms'      => $totalRooms,
            'total_nights'     => $totalNights,
            'total_room_nights' => $totalRoomNights,
            'occupied_nights'  => (int) $occupiedNights,
            'occupancy_rate'   => $occupancyRate,
            'per_room'         => $perRoom,
        ];
    }

    // ---------------------------------------------------------------
    // 2. LAPORAN PENDAPATAN
    // ---------------------------------------------------------------
    public function revenue(?string $from, ?string $to): array
    {
        [$from, $to] = $this->parseRange($from, $to);

        $payments = Payment::whereBetween('paid_at', [$from, $to])
            ->where('status', PaymentStatus::Verified->value)
            ->whereIn('type', [PaymentType::Dp->value, PaymentType::Final->value])
            ->selectRaw('
                SUM(amount) as total_revenue,
                COUNT(*) as total_transactions,
                SUM(CASE WHEN method = "cash" THEN amount ELSE 0 END) as cash_revenue,
                SUM(CASE WHEN method = "transfer" THEN amount ELSE 0 END) as transfer_revenue
            ')
            ->first();

        // Per hari
        $daily = Payment::whereBetween('paid_at', [$from, $to])
            ->where('status', PaymentStatus::Verified->value)
            ->whereIn('type', [PaymentType::Dp->value, PaymentType::Final->value])
            ->selectRaw('DATE(paid_at) as date, SUM(amount) as revenue, COUNT(*) as transactions')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'period'              => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_revenue'       => (float) ($payments->total_revenue ?? 0),
            'total_transactions'  => (int)   ($payments->total_transactions ?? 0),
            'cash_revenue'        => (float) ($payments->cash_revenue ?? 0),
            'transfer_revenue'    => (float) ($payments->transfer_revenue ?? 0),
            'daily'               => $daily,
        ];
    }

    // ---------------------------------------------------------------
    // 3. LAPORAN RESERVASI
    // ---------------------------------------------------------------
    public function reservations(?string $from, ?string $to): array
    {
        [$from, $to] = $this->parseRange($from, $to);

        $bookings = Booking::with(['customer', 'createdBy', 'bookingRooms.room'])
            ->whereBetween('check_in', [$from->toDateString(), $to->toDateString()])
            ->orderBy('check_in')
            ->get();

        $summary = [
            'total'       => $bookings->count(),
            'pending'     => $bookings->where('status.value', BookingStatus::Pending->value)->count(),
            'confirmed'   => $bookings->where('status.value', BookingStatus::Confirmed->value)->count(),
            'checked_in'  => $bookings->where('status.value', BookingStatus::CheckedIn->value)->count(),
            'checked_out' => $bookings->where('status.value', BookingStatus::CheckedOut->value)->count(),
            'cancelled'   => $bookings->where('status.value', BookingStatus::Cancelled->value)->count(),
        ];

        return [
            'period'   => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary'  => $summary,
            'bookings' => $bookings,
        ];
    }

    // ---------------------------------------------------------------
    // 4. LAPORAN TRANSAKSI PEMBAYARAN
    // ---------------------------------------------------------------
    public function transactions(?string $from, ?string $to): array
    {
        [$from, $to] = $this->parseRange($from, $to);

        $payments = Payment::with(['booking.customer', 'createdBy', 'verifiedBy'])
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->get();

        return [
            'period'   => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total'    => $payments->count(),
            'payments' => $payments,
        ];
    }

    // ---------------------------------------------------------------
    // 5. LAPORAN METODE PEMBAYARAN
    // ---------------------------------------------------------------
    public function paymentMethods(?string $from, ?string $to): array
    {
        [$from, $to] = $this->parseRange($from, $to);

        $data = Payment::whereBetween('paid_at', [$from, $to])
            ->where('status', PaymentStatus::Verified->value)
            ->selectRaw('method, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('method')
            ->orderByDesc('total')
            ->get();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'data'   => $data,
        ];
    }

    // ---------------------------------------------------------------
    // 6. LAPORAN SHIFT
    // ---------------------------------------------------------------
    public function shifts(?string $from, ?string $to): array
    {
        [$from, $to] = $this->parseRange($from, $to);

        $shifts = DB::table('shifts')
            ->join('users', 'shifts.user_id', '=', 'users.id')
            ->leftJoin('payments', 'payments.shift_id', '=', 'shifts.id')
            ->whereBetween('shifts.started_at', [$from, $to])
            ->select(
                'shifts.id',
                'shifts.status',
                'shifts.started_at',
                'shifts.ended_at',
                'users.name as pegawai',
                DB::raw('COALESCE(SUM(CASE WHEN payments.status = "verified" THEN payments.amount ELSE 0 END), 0) as total_collected'),
                DB::raw('COUNT(DISTINCT payments.id) as total_transactions')
            )
            ->groupBy('shifts.id', 'shifts.status', 'shifts.started_at', 'shifts.ended_at', 'users.name')
            ->orderBy('shifts.started_at')
            ->get();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'shifts' => $shifts,
        ];
    }

    // ---------------------------------------------------------------
    // 7. LAPORAN SUMBER RESERVASI
    // ---------------------------------------------------------------
    public function sources(?string $from, ?string $to): array
    {
        [$from, $to] = $this->parseRange($from, $to);

        $data = DB::table('bookings')
            ->whereBetween('check_in', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('status', [BookingStatus::Cancelled->value])
            ->selectRaw('source, COUNT(*) as count, SUM(total_price) as total_revenue')
            ->groupBy('source')
            ->orderByDesc('count')
            ->get();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'data'   => $data,
        ];
    }

    // ---------------------------------------------------------------
    // 8. LAPORAN HOUSEKEEPING
    // ---------------------------------------------------------------
    public function housekeeping(?string $from, ?string $to): array
    {
        [$from, $to] = $this->parseRange($from, $to);

        $tasks = HousekeepingTask::with(['room', 'assignedTo'])
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->get();

        $summary = [
            'total'       => $tasks->count(),
            'pending'     => $tasks->where('status.value', 'pending')->count(),
            'in_progress' => $tasks->where('status.value', 'in_progress')->count(),
            'completed'   => $tasks->where('status.value', 'completed')->count(),
        ];

        return [
            'period'  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => $summary,
            'tasks'   => $tasks,
        ];
    }
}
