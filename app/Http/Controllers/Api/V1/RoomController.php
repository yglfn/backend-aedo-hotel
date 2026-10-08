<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Services\RoomService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    public function __construct(private RoomService $roomService)
    {
    }

    public function index()
    {
        $rooms = Room::with('roomType')->paginate(15);
        return response()->json($rooms);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'room_number'  => 'required|string|max:255|unique:rooms',
            'floor'        => 'nullable|integer',
            'notes'        => 'nullable|string',
        ]);

        $room = Room::create($validated);

        return response()->json(['data' => $room, 'message' => 'Kamar berhasil ditambahkan'], 201);
    }

    public function show(Room $room)
    {
        $room->load('roomType');
        return response()->json(['data' => $room]);
    }

    public function update(Request $request, Room $room)
    {
        $validated = $request->validate([
            'room_type_id' => 'sometimes|exists:room_types,id',
            'room_number'  => ['sometimes', 'string', 'max:255', Rule::unique('rooms')->ignore($room->id)],
            'floor'        => 'nullable|integer',
            'notes'        => 'nullable|string',
        ]);

        $room->update($validated);

        return response()->json(['data' => $room, 'message' => 'Kamar berhasil diperbarui']);
    }

    public function destroy(Room $room)
    {
        $room->delete();
        return response()->json(['message' => 'Kamar berhasil dihapus']);
    }

    public function changeStatus(Request $request, Room $room)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(RoomStatus::class)],
            'reason' => 'nullable|string'
        ]);

        $this->roomService->changeStatus(
            $room, 
            RoomStatus::from($validated['status']), 
            $request->user(), 
            null, 
            $validated['reason'] ?? null
        );

        return response()->json(['data' => $room->fresh(), 'message' => 'Status kamar berhasil diperbarui']);
    }

    public function availability(Request $request)
    {
        $validated = $request->validate([
            'check_in'  => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
        ]);

        $rooms = Room::with('roomType')->get();
        
        $availableRooms = $rooms->filter(function ($room) use ($validated) {
            return $this->roomService->isAvailable($room->id, $validated['check_in'], $validated['check_out']);
        })->values();

        return response()->json(['data' => $availableRooms]);
    }
}
