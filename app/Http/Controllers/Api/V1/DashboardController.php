<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Shift;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = today()->toDateString();
        $user = $request->user();
        $isAdmin = $user && $user->role === 'admin';

        // 1. Kamar
        $availableRooms = Room::where('status', 'available')->count();
        $occupiedRooms = Room::where('status', 'occupied')->count();
        $dirtyRooms = Room::where('status', 'cleaning')->count();
        $maintenanceRooms = Room::where('status', 'maintenance')->count();
        $totalRooms = Room::count();

        // Okupansi
        $occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 1) : 0;

        // 2. Operasional Hari Ini
        $arrivalsToday = Booking::whereIn('status', ['confirmed', 'pending'])
            ->where('check_in', $today)
            ->count();
            
        $departuresToday = Booking::where('status', 'checked_in')
            ->where('check_out', $today)
            ->count();

        // 3. Transaksi & Keuangan
        // Total pendapatan hari ini (semua pembayaran verified hari ini)
        $revenueToday = (float) Payment::where('status', PaymentStatus::Verified->value)
            ->whereDate('paid_at', $today)
            ->sum('amount');

        // Total pendapatan bulan ini (untuk admin)
        $revenueMonth = (float) Payment::where('status', PaymentStatus::Verified->value)
            ->whereMonth('paid_at', now()->month)
            ->whereYear('paid_at', now()->year)
            ->sum('amount');

        // Pembayaran yang butuh verifikasi admin (pending)
        $pendingPaymentsCount = Payment::where('status', PaymentStatus::Pending->value)->count();
        $pendingPaymentsAmount = (float) Payment::where('status', PaymentStatus::Pending->value)->sum('amount');

        // 4. Transaksi Shift Aktif (untuk Pegawai / Shift saat ini)
        $currentShift = Shift::where('status', 'open')
            ->when(!$isAdmin, function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->latest()
            ->first();

        $shiftSummary = null;
        if ($currentShift) {
            $shiftPayments = Payment::where('shift_id', $currentShift->id);
            $shiftSummary = [
                'shift_id'     => $currentShift->id,
                'started_at'   => $currentShift->started_at,
                'user_name'    => $currentShift->user?->name,
                'total_amount' => (float) (clone $shiftPayments)->where('status', PaymentStatus::Verified->value)->sum('amount'),
                'cash_amount'  => (float) (clone $shiftPayments)->where('status', PaymentStatus::Verified->value)->where('method', 'cash')->sum('amount'),
                'non_cash_amount' => (float) (clone $shiftPayments)->where('status', PaymentStatus::Verified->value)->where('method', '!=', 'cash')->sum('amount'),
                'transaction_count' => (clone $shiftPayments)->count(),
            ];
        }

        // 5. Data List Kedatangan & Keberangkatan Hari Ini
        $arrivalsList = Booking::with(['customer', 'bookingRooms.room'])
            ->whereIn('status', ['confirmed', 'pending'])
            ->where('check_in', $today)
            ->latest()
            ->take(5)
            ->get();

        $departuresList = Booking::with(['customer', 'bookingRooms.room'])
            ->where('status', 'checked_in')
            ->where('check_out', $today)
            ->latest()
            ->take(5)
            ->get();

        // 6. Transaksi Terbaru
        $recentTransactions = Payment::with(['booking.customer', 'createdBy'])
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'data' => [
                'rooms' => [
                    'available'   => $availableRooms,
                    'occupied'    => $occupiedRooms,
                    'cleaning'    => $dirtyRooms,
                    'maintenance' => $maintenanceRooms,
                    'total'       => $totalRooms,
                    'occupancy'   => $occupancyRate,
                ],
                'today' => [
                    'arrivals'   => $arrivalsToday,
                    'departures' => $departuresToday,
                ],
                'finance' => [
                    'revenue_today'           => $revenueToday,
                    'revenue_month'           => $revenueMonth,
                    'pending_payments_count'  => $pendingPaymentsCount,
                    'pending_payments_amount' => $pendingPaymentsAmount,
                ],
                'shift' => $shiftSummary,
                'arrivals_list'       => $arrivalsList,
                'departures_list'     => $departuresList,
                'recent_transactions' => $recentTransactions,
            ]
        ]);
    }
}

