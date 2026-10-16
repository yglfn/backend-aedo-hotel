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

    public function index(Request $request)
    {
        // Papan kamar butuh seluruh kamar sekaligus; default pagination dibuat lebih longgar
        $perPage = max(1, min((int) $request->input('per_page', 50), 200));

        $rooms = Room::with('roomType')->orderBy('room_number')->paginate($perPage);
        return response()->json($rooms);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'room_number'  => ['required', 'string', 'max:255', Rule::unique('rooms')->whereNull('deleted_at')],
            'floor'        => 'nullable|integer',
            'status'       => ['nullable', Rule::enum(RoomStatus::class)],
            'notes'        => 'nullable|string',
        ]);

        if (empty($validated['status'])) {
            $validated['status'] = RoomStatus::Available->value;
        }

        $room = Room::create($validated);
        $room->load('roomType');

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
            'room_number'  => ['sometimes', 'string', 'max:255', Rule::unique('rooms')->ignore($room->id)->whereNull('deleted_at')],
            'floor'        => 'nullable|integer',
            'status'       => ['nullable', Rule::enum(RoomStatus::class)],
            'notes'        => 'nullable|string',
        ]);

        $room->update($validated);
        $room->load('roomType');

        return response()->json(['data' => $room, 'message' => 'Kamar berhasil diperbarui']);
    }

    public function destroy(Room $room)
    {
        if ($room->status === RoomStatus::Occupied) {
            return response()->json([
                'message' => 'Kamar sedang terisi (occupied) dan tidak dapat dihapus.'
            ], 422);
        }

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
        $isToday = \Carbon\Carbon::parse($validated['check_in'])->isToday();
        
        $availableRooms = $rooms->filter(function ($room) use ($validated, $isToday) {
            // Kamar yang sedang dalam perbaikan (maintenance) tidak boleh direservasi
            if ($room->status === RoomStatus::Maintenance || (is_object($room->status) && $room->status->value === 'maintenance')) {
                return false;
            }

            // Jika check-in hari ini, kamar yang saat ini berstatus occupied tidak boleh dipilih
            if ($isToday && ($room->status === RoomStatus::Occupied || (is_object($room->status) && $room->status->value === 'occupied'))) {
                return false;
            }

            return $this->roomService->isAvailable($room->id, $validated['check_in'], $validated['check_out']);
        })->values();

        return response()->json(['data' => $availableRooms]);
    }
}
