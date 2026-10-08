<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = today()->toDateString();
        
        $availableRooms = Room::where('status', 'available')->count();
        $occupiedRooms = Room::where('status', 'occupied')->count();
        $dirtyRooms = Room::where('status', 'cleaning')->count();
        
        $arrivalsToday = Booking::where('status', 'confirmed')
            ->where('check_in', $today)
            ->count();
            
        $departuresToday = Booking::where('status', 'checked_in')
            ->where('check_out', $today)
            ->count();

        return response()->json([
            'data' => [
                'rooms' => [
                    'available' => $availableRooms,
                    'occupied'  => $occupiedRooms,
                    'cleaning'  => $dirtyRooms,
                    'total'     => Room::count(),
                ],
                'today' => [
                    'arrivals'   => $arrivalsToday,
                    'departures' => $departuresToday,
                ]
            ]
        ]);
    }
}
