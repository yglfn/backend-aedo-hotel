<?php

namespace App\Services;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Enums\ShiftStatus;
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

    /**
     * Jumlah hari dalam periode (inklusif) — dipakai untuk rata-rata harian & malam kamar.
     */
    protected function rangeDays(Carbon $from, Carbon $to): int
    {
        return (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
    }

    /**
     * Periode sebelumnya dengan panjang yang sama, dipakai sebagai pembanding angka.
     */
    protected function previousRange(Carbon $from, Carbon $to): array
    {
        $days     = $this->rangeDays($from, $to);
        $toPrev   = $from->copy()->startOfDay()->subDay()->endOfDay();
        $fromPrev = $toPrev->copy()->subDays($days - 1)->startOfDay();

        return [$fromPrev, $toPrev];
    }

    /**
     * Perubahan persen terhadap pembanding. Null bila pembanding nol, supaya UI tidak
     * menampilkan "0%" atau "tak terhingga" yang menyesatkan pada periode kosong.
     */
    protected function changePercent(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Query pembayaran yang dihitung sebagai pendapatan (DP + pelunasan, sudah terverifikasi).
     */
    protected function revenueQuery(Carbon $from, Carbon $to)
    {
        return Payment::whereBetween('paid_at', [$from, $to])
            ->where('status', PaymentStatus::Verified->value)
            ->whereIn('type', [PaymentType::Dp->value, PaymentType::Final->value]);
    }

    /**
     * Reservasi per status + nilainya untuk satu rentang check-in.
     * Dipakai laporan reservasi dan ringkasan, termasuk periode pembanding.
     */
    protected function bookingAggregate(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('bookings')
            ->whereBetween('check_in', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('status, COUNT(*) as count, SUM(total_price) as total_value')
            ->groupBy('status')
            ->get();

        // Semua status selalu ada, walau nol, supaya grafik tidak perlu menebak.
        $byStatus = [];
        foreach (BookingStatus::cases() as $case) {
            $byStatus[$case->value] = ['count' => 0, 'value' => 0.0];
        }

        $total = 0;
        $value = 0.0;
        foreach ($rows as $row) {
            $byStatus[$row->status] = [
                'count' => (int) $row->count,
                'value' => round((float) $row->total_value, 2),
            ];
            $total += (int) $row->count;

            if ($row->status !== BookingStatus::Cancelled->value) {
                $value += (float) $row->total_value;
            }
        }

        return ['total' => $total, 'total_value' => round($value, 2), 'by_status' => $byStatus];
    }

    /**
     * Malam kamar terisi pada periode (kamar x malam), tanpa menghitung booking dibatalkan.
     */
    protected function occupiedNights(Carbon $from, Carbon $to): int
    {
        return (int) DB::table('booking_rooms')
            ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->whereNotIn('bookings.status', [BookingStatus::Cancelled->value])
            ->where('booking_rooms.check_in', '<', $to)
            ->where('booking_rooms.check_out', '>', $from)
            ->sum('booking_rooms.nights');
    }

    /**
     * Laporan tidak memerlukan PII lengkap: KTP tamu serta email/terakhir login staf
     * disembunyikan dari payload (AGENTS.md bagian 9).
     */
    protected function stripPii(Collection $rows): Collection
    {
        $rows->each(function ($row) {
            if ($row instanceof Booking) {
                $row->customer?->makeHidden(['ktp']);
                $row->createdBy?->makeHidden(['email', 'last_login_at']);
            }

            if ($row instanceof Payment) {
                $row->booking?->customer?->makeHidden(['ktp']);
                $row->createdBy?->makeHidden(['email', 'last_login_at']);
                $row->verifiedBy?->makeHidden(['email', 'last_login_at']);
            }

            if ($row instanceof HousekeepingTask) {
                $row->assignedTo?->makeHidden(['email', 'last_login_at']);
            }
        });

        return $rows;
    }

    // ---------------------------------------------------------------
    // 1. LAPORAN OKUPANSI
    // ---------------------------------------------------------------
    public function occupancy(?string $from, ?string $to): array
    {
        [$from, $to] = $this->parseRange($from, $to);

        $totalRooms        = Room::whereNull('deleted_at')->count();
        $totalNights       = $this->rangeDays($from, $to);
        $totalRoomNights   = $totalRooms * $totalNights;
        $occupiedNights    = $this->occupiedNights($from, $to);
        $occupancyRate     = $totalRoomNights > 0
            ? round(($occupiedNights / $totalRoomNights) * 100, 2)
            : 0;

        // Per kamar: tipe kamar ikut dikirim agar mudah dibaca, bukan sekadar nomor
        $perRoom = DB::table('booking_rooms')
            ->join('bookings', 'booking_rooms.booking_id', '=', 'bookings.id')
            ->join('rooms', 'booking_rooms.room_id', '=', 'rooms.id')
            ->leftJoin('room_types', 'rooms.room_type_id', '=', 'room_types.id')
            ->whereNotIn('bookings.status', [BookingStatus::Cancelled->value])
            ->where('booking_rooms.check_in', '<', $to)
            ->where('booking_rooms.check_out', '>', $from)
            ->select(
                'rooms.id as room_id',
                'rooms.room_number',
                'rooms.floor',
                'room_types.name as room_type',
                DB::raw('SUM(booking_rooms.nights) as occupied_nights')
            )
            ->groupBy('rooms.id', 'rooms.room_number', 'rooms.floor', 'room_types.name')
            ->orderByDesc('occupied_nights')
            ->get()
            ->map(function ($row) use ($totalNights) {
                $row->occupied_nights = (int) $row->occupied_nights;
                $row->room_rate       = $totalNights > 0
                    ? round($row->occupied_nights / $totalNights * 100, 1)
                    : 0.0;

                return $row;
            });

        // Pembanding periode sebelumnya (panjang rentang sama)
        [$pFrom, $pTo]           = $this->previousRange($from, $to);
        $prevRoomNights          = $totalRooms * $this->rangeDays($pFrom, $pTo);
        $previousOccupancyRate   = $prevRoomNights > 0
            ? round($this->occupiedNights($pFrom, $pTo) / $prevRoomNights * 100, 2)
            : 0;

        return [
            'period'            => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_rooms'       => $totalRooms,
            'total_nights'      => $totalNights,
            'total_room_nights' => $totalRoomNights,
            'occupied_nights'   => $occupiedNights,
            'empty_room_nights' => max(0, $totalRoomNights - $occupiedNights),
            'occupancy_rate'    => $occupancyRate,
            'rooms_with_stay'   => $perRoom->count(),
            'idle_rooms'        => max(0, $totalRooms - $perRoom->count()),
            'busiest_room'      => $perRoom->first() ? [
                'room_number'      => $perRoom->first()->room_number,
                'occupied_nights'  => $perRoom->first()->occupied_nights,
            ] : null,
            'comparison'        => [
                'period'         => ['from' => $pFrom->toDateString(), 'to' => $pTo->toDateString()],
                'occupancy_rate' => $previousOccupancyRate,
                'change_points'  => round($occupancyRate - $previousOccupancyRate, 2),
            ],
            'per_room'          => $perRoom,
        ];
    }

    // ---------------------------------------------------------------
    // 2. LAPORAN PENDAPATAN
    // ---------------------------------------------------------------
    public function revenue(?string $from, ?string $to): array
    {
        [$from, $to] = $this->parseRange($from, $to);

        $totals = $this->revenueQuery($from, $to)
            ->selectRaw('SUM(amount) as total_revenue, COUNT(*) as total_transactions')
            ->first();

        // Rincian metode bayar dibaca dari enum, tidak dari literal string di SQL
        $byMethod = $this->revenueQuery($from, $to)
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        $pending = Payment::whereBetween('created_at', [$from, $to])
            ->where('status', PaymentStatus::Pending->value)
            ->selectRaw('COUNT(*) as total_count, COALESCE(SUM(amount), 0) as total_amount')
            ->first();

        $refundTotal = round((float) Payment::whereBetween('paid_at', [$from, $to])
            ->where('status', PaymentStatus::Verified->value)
            ->where('type', PaymentType::Refund->value)
            ->sum('amount'), 2);

        // Per hari
        $daily = $this->revenueQuery($from, $to)
            ->selectRaw('DATE(paid_at) as date, SUM(amount) as revenue, COUNT(*) as transactions')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($row) {
                $row->revenue      = (float) $row->revenue;
                $row->transactions = (int) $row->transactions;

                return $row;
            });

        $totalRevenue      = round((float) ($totals->total_revenue ?? 0), 2);
        $transactionCount  = (int) ($totals->total_transactions ?? 0);
        $days              = $this->rangeDays($from, $to);
        $bestDay           = $daily->sortByDesc('revenue')->first();

        // Pembanding periode sebelumnya
        [$pFrom, $pTo]   = $this->previousRange($from, $to);
        $previousTotals  = $this->revenueQuery($pFrom, $pTo)
            ->selectRaw('SUM(amount) as total_revenue, COUNT(*) as total_transactions')
            ->first();
        $previousRevenue = round((float) ($previousTotals->total_revenue ?? 0), 2);

        return [
            'period'              => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'days'                => $days,
            'total_revenue'       => $totalRevenue,
            'total_transactions'  => $transactionCount,
            'cash_revenue'        => round((float) ($byMethod['cash'] ?? 0), 2),
            'transfer_revenue'    => round((float) ($byMethod['transfer'] ?? 0), 2),
            'qris_revenue'        => round((float) ($byMethod['qris'] ?? 0), 2),
            'refund_total'        => $refundTotal,
            'pending_count'       => (int) ($pending->total_count ?? 0),
            'pending_amount'      => round((float) ($pending->total_amount ?? 0), 2),
            'average_per_day'     => $days > 0 ? round($totalRevenue / $days, 2) : 0,
            'average_per_txn'     => $transactionCount > 0 ? round($totalRevenue / $transactionCount, 2) : 0,
            'best_day'            => $bestDay ? ['date' => $bestDay->date, 'revenue' => $bestDay->revenue] : null,
            'comparison'          => [
                'period'             => ['from' => $pFrom->toDateString(), 'to' => $pTo->toDateString()],
                'total_revenue'      => $previousRevenue,
                'total_transactions' => (int) ($previousTotals->total_transactions ?? 0),
                'change_percent'     => $this->changePercent($totalRevenue, $previousRevenue),
            ],
            'daily'               => $daily,
        ];
    }

    // ---------------------------------------------------------------
    // 3. LAPORAN RESERVASI
    // ---------------------------------------------------------------
    public function reservations(?string $from, ?string $to): array
    {
        [$from, $to] = $this->parseRange($from, $to);

        // payments + invoice ikut dimuat agar kolom terbayar/sisa tidak memicu N+1
        $bookings = Booking::with(['customer', 'createdBy', 'bookingRooms.room', 'payments', 'invoice'])
            ->whereBetween('check_in', [$from->toDateString(), $to->toDateString()])
            ->orderBy('check_in')
            ->get();

        $active = $bookings->reject(fn (Booking $booking) => $booking->status === BookingStatus::Cancelled);

        $nights = $active->map(fn (Booking $booking) => max(
            1, (int) $booking->check_in->diffInDays($booking->check_out)
        ));

        $summary = [
            'total'             => $bookings->count(),
            'pending'           => $bookings->where('status.value', BookingStatus::Pending->value)->count(),
            'confirmed'         => $bookings->where('status.value', BookingStatus::Confirmed->value)->count(),
            'checked_in'        => $bookings->where('status.value', BookingStatus::CheckedIn->value)->count(),
            'checked_out'       => $bookings->where('status.value', BookingStatus::CheckedOut->value)->count(),
            'cancelled'         => $bookings->where('status.value', BookingStatus::Cancelled->value)->count(),
            'total_value'       => round((float) $active->sum('total_price'), 2),
            'average_value'     => $active->count() > 0
                ? round((float) $active->sum('total_price') / $active->count(), 2)
                : 0,
            'average_nights'    => $nights->count() > 0 ? round($nights->avg(), 1) : 0,
            'total_guests'      => (int) $active->sum('guest_count'),
            'collected'         => round((float) $active->sum('paid_verified'), 2),
            'outstanding'       => round((float) $active->sum('remaining_due'), 2),
            'cancellation_rate' => $bookings->count() > 0
                ? round($bookings->where('status.value', BookingStatus::Cancelled->value)->count() / $bookings->count() * 100, 1)
                : 0,
        ];

        [$pFrom, $pTo] = $this->previousRange($from, $to);
        $previous      = $this->bookingAggregate($pFrom, $pTo);

        return [
            'period'     => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary'    => $summary,
            'comparison' => [
                'period'         => ['from' => $pFrom->toDateString(), 'to' => $pTo->toDateString()],
                'total'          => $previous['total'],
                'total_value'    => $previous['total_value'],
                'change_percent' => $this->changePercent((float) $summary['total'], (float) $previous['total']),
            ],
            'bookings'   => $this->stripPii($bookings),
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

        $byStatus = $this->paymentBreakdown($payments, 'status.value');
        $byMethod = $this->paymentBreakdown($payments, 'method.value');

        $verified = $payments->where('status.value', PaymentStatus::Verified->value);
        $pending  = $payments->where('status.value', PaymentStatus::Pending->value);
        $failed   = $payments->where('status.value', PaymentStatus::Failed->value);

        return [
            'period'   => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total'    => $payments->count(),
            'summary'  => [
                'total_amount'    => round((float) $payments->sum('amount'), 2),
                'verified_count'  => $verified->count(),
                'verified_amount' => round((float) $verified->sum('amount'), 2),
                'pending_count'   => $pending->count(),
                'pending_amount'  => round((float) $pending->sum('amount'), 2),
                'failed_count'    => $failed->count(),
                'failed_amount'   => round((float) $failed->sum('amount'), 2),
                'refund_amount'   => round((float) $payments
                    ->where('type.value', PaymentType::Refund->value)
                    ->sum('amount'), 2),
            ],
            'by_status' => $byStatus,
            'by_method' => $byMethod,
            'payments'  => $this->stripPii($payments),
        ];
    }

    /**
     * Rincian transaksi per kunci (mis. status.value / method.value) sebagai daftar
     * berurutan — bentuk yang paling mudah dipakai grafik di frontend.
     */
    protected function paymentBreakdown(Collection $payments, string $keyAttribute): array
    {
        return $payments->groupBy($keyAttribute)
            ->map(fn ($group, $key) => [
                'key'    => (string) $key,
                'count'  => $group->count(),
                'amount' => round((float) $group->sum('amount'), 2),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    // ---------------------------------------------------------------
    // 5. LAPORAN METODE PEMBAYARAN
    // ---------------------------------------------------------------
    public function paymentMethods(?string $from, ?string $to): array
    {
        [$from, $to] = $this->parseRange($from, $to);

        // Query builder (bukan Eloquent) agar `method` tetap string, bukan objek enum
        $data = DB::table('payments')
            ->whereBetween('paid_at', [$from, $to])
            ->where('status', PaymentStatus::Verified->value)
            ->selectRaw('method, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('method')
            ->orderByDesc('total')
            ->get();

        $grandTotal = round((float) $data->sum('total'), 2);
        $totalCount = (int) $data->sum('count');

        $data = $data->map(function ($row) use ($grandTotal, $totalCount) {
            // Label dipakai view PDF, Excel, dan aplikasi supaya tidak ada yang menebak sendiri
            $row->label = PaymentMethod::tryFrom((string) $row->method)?->label() ?? (string) $row->method;

            $row->total          = round((float) $row->total, 2);
            $row->count          = (int) $row->count;
            $row->share_percent  = $grandTotal > 0 ? round($row->total / $grandTotal * 100, 1) : 0;
            $row->count_percent  = $totalCount > 0 ? round($row->count / $totalCount * 100, 1) : 0;
            $row->average        = $row->count > 0 ? round($row->total / $row->count, 2) : 0;

            return $row;
        });

        return [
            'period'       => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_amount' => $grandTotal,
            'total_count'  => $totalCount,
            'data'         => $data,
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
            ->selectRaw(
                "shifts.id, shifts.status, shifts.started_at, shifts.ended_at, users.name as pegawai,
                 COALESCE(SUM(CASE WHEN payments.status = ? THEN payments.amount ELSE 0 END), 0) as total_collected,
                 COUNT(DISTINCT payments.id) as total_transactions",
                [PaymentStatus::Verified->value]
            )
            ->groupBy('shifts.id', 'shifts.status', 'shifts.started_at', 'shifts.ended_at', 'users.name')
            ->orderBy('shifts.started_at')
            ->get()
            ->map(function ($row) {
                $row->id                = (int) $row->id;
                $row->total_collected   = round((float) $row->total_collected, 2);
                $row->total_transactions = (int) $row->total_transactions;
                // Shift yang masih buka tidak punya durasi akhir (UI menandainya "berjalan")
                $row->duration_hours    = $row->ended_at
                    ? round(Carbon::parse($row->started_at)->diffInHours(Carbon::parse($row->ended_at)), 1)
                    : null;

                return $row;
            });

        $closed = $shifts->where('status', ShiftStatus::Closed->value);

        return [
            'period'  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => [
                'total_shifts'       => $shifts->count(),
                'open_shifts'        => $shifts->count() - $closed->count(),
                'closed_shifts'      => $closed->count(),
                'total_collected'    => round((float) $shifts->sum('total_collected'), 2),
                'total_transactions' => (int) $shifts->sum('total_transactions'),
                'average_collected'  => $shifts->count() > 0
                    ? round((float) $shifts->sum('total_collected') / $shifts->count(), 2)
                    : 0,
            ],
            'shifts'  => $shifts,
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

        $grandTotal = round((float) $data->sum('total_revenue'), 2);
        $totalCount = (int) $data->sum('count');

        $data = $data->map(function ($row) use ($grandTotal, $totalCount) {
            $row->label         = BookingSource::tryFrom((string) $row->source)?->label() ?? (string) $row->source;
            $row->count         = (int) $row->count;
            $row->total_revenue = round((float) $row->total_revenue, 2);
            $row->share_percent   = $totalCount > 0 ? round($row->count / $totalCount * 100, 1) : 0;
            $row->revenue_percent = $grandTotal > 0 ? round($row->total_revenue / $grandTotal * 100, 1) : 0;
            $row->average_value   = $row->count > 0 ? round($row->total_revenue / $row->count, 2) : 0;

            return $row;
        });

        return [
            'period'       => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_count'  => $totalCount,
            'total_revenue' => $grandTotal,
            'data'         => $data,
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

        $completed = $tasks->where('status.value', HousekeepingStatus::Completed->value);

        // Durasi pengerjaan hanya dihitung dari tugas selesai (created_at -> completed_at)
        $durations = $completed
            ->filter(fn (HousekeepingTask $task) => $task->completed_at)
            ->map(fn (HousekeepingTask $task) => $task->created_at->diffInHours($task->completed_at));

        $byStaff = $tasks->groupBy(fn (HousekeepingTask $task) => $task->assignedTo?->name ?? 'Belum ditugaskan')
            ->map(fn ($group, $name) => [
                'staff'     => (string) $name,
                'total'     => $group->count(),
                'completed' => $group->where('status.value', HousekeepingStatus::Completed->value)->count(),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        $summary = [
            'total'           => $tasks->count(),
            'pending'         => $tasks->where('status.value', HousekeepingStatus::Pending->value)->count(),
            'in_progress'     => $tasks->where('status.value', HousekeepingStatus::InProgress->value)->count(),
            'completed'       => $completed->count(),
            'unassigned'      => $tasks->whereNull('assigned_to')->count(),
            'completion_rate' => $tasks->count() > 0
                ? round($completed->count() / $tasks->count() * 100, 1)
                : 0,
            'average_hours'   => $durations->count() > 0 ? round($durations->avg(), 1) : null,
        ];

        return [
            'period'  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => $summary,
            'by_staff' => $byStaff,
            'tasks'   => $this->stripPii($tasks),
        ];
    }

    // ---------------------------------------------------------------
    // 9. RINGKASAN (dipakai tab Ringkasan pada Pusat Laporan)
    // ---------------------------------------------------------------

    /**
     * Satu respons berisi angka penting semua laporan + pembanding periode sebelumnya,
     * supaya admin bisa membaca kondisi hotel tanpa membuka tiap laporan satu per satu.
     */
    public function summary(?string $from, ?string $to): array
    {
        [$from, $to]     = $this->parseRange($from, $to);
        [$pFrom, $pTo]   = $this->previousRange($from, $to);

        $revenue    = $this->revenue($from, $to);
        $occupancy  = $this->occupancy($from, $to);
        $methods    = $this->paymentMethods($from, $to);
        $sources    = $this->sources($from, $to);
        $housekeep  = $this->housekeeping($from, $to);
        $shiftsData = $this->shifts($from, $to);

        $bookings   = $this->bookingAggregate($from, $to);
        $previous   = $this->bookingAggregate($pFrom, $pTo);

        // Kondisi kamar saat ini (bukan rekap periode) — penanda operasional
        $roomStatus = Room::selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
        $rooms      = [];
        foreach (RoomStatus::cases() as $case) {
            $rooms[$case->value] = (int) ($roomStatus[$case->value] ?? 0);
        }
        $rooms['total'] = array_sum($rooms);

        return [
            'period'          => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'days'            => $this->rangeDays($from, $to),
            'previous_period' => ['from' => $pFrom->toDateString(), 'to' => $pTo->toDateString()],
            'revenue'         => [
                'total_revenue'      => $revenue['total_revenue'],
                'total_transactions' => $revenue['total_transactions'],
                'average_per_day'    => $revenue['average_per_day'],
                'cash_revenue'       => $revenue['cash_revenue'],
                'transfer_revenue'   => $revenue['transfer_revenue'],
                'qris_revenue'       => $revenue['qris_revenue'],
                'refund_total'       => $revenue['refund_total'],
                'pending_count'      => $revenue['pending_count'],
                'pending_amount'     => $revenue['pending_amount'],
                'previous_revenue'   => $revenue['comparison']['total_revenue'],
                'change_percent'     => $revenue['comparison']['change_percent'],
            ],
            'occupancy'       => [
                'occupancy_rate'    => $occupancy['occupancy_rate'],
                'occupied_nights'   => $occupancy['occupied_nights'],
                'total_room_nights' => $occupancy['total_room_nights'],
                'total_rooms'       => $occupancy['total_rooms'],
                'idle_rooms'        => $occupancy['idle_rooms'],
                'previous_rate'     => $occupancy['comparison']['occupancy_rate'],
                'change_points'     => $occupancy['comparison']['change_points'],
            ],
            'reservations'    => [
                'total'          => $bookings['total'],
                'total_value'    => $bookings['total_value'],
                'by_status'      => $bookings['by_status'],
                'previous_total' => $previous['total'],
                'change_percent' => $this->changePercent((float) $bookings['total'], (float) $previous['total']),
            ],
            'housekeeping'    => $housekeep['summary'],
            'shifts'          => $shiftsData['summary'],
            'rooms'           => $rooms,
            'revenue_trend'   => $revenue['daily'],
            'payment_mix'     => $methods['data'],
            'booking_sources' => $sources['data'],
            'highlights'      => [
                'best_day'   => $revenue['best_day'],
                'best_room'  => $occupancy['busiest_room'],
                'top_source' => $sources['data']->first() ? [
                    'source'  => $sources['data']->first()->source,
                    'count'   => $sources['data']->first()->count,
                ] : null,
            ],
        ];
    }
}
