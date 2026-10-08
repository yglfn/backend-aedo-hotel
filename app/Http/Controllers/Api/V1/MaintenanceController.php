<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MaintenanceStatus;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Maintenance;
use App\Models\Room;
use App\Services\RoomService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class MaintenanceController extends Controller
{
    public function __construct(private RoomService $roomService)
    {
    }

    public function index()
    {
        $maintenance = Maintenance::with(['room', 'reportedBy', 'assignedTo'])->paginate(15);
        return response()->json($maintenance);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_id'     => 'required|exists:rooms,id',
            'description' => 'required|string',
            'priority'    => 'required|string|in:low,medium,high,critical',
        ]);

        DB::beginTransaction();
        try {
            $maintenance = Maintenance::create([
                'room_id'     => $validated['room_id'],
                'reported_by' => $request->user()->id,
                'description' => $validated['description'],
                'priority'    => $validated['priority'],
                'status'      => MaintenanceStatus::Reported,
            ]);

            $room = Room::findOrFail($validated['room_id']);
            $this->roomService->changeStatus(
                $room,
                RoomStatus::Maintenance,
                $request->user(),
                null,
                'Laporan Maintenance: ' . $validated['description']
            );
            
            DB::commit();
            return response()->json(['data' => $maintenance, 'message' => 'Laporan maintenance berhasil dibuat'], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateStatus(Request $request, Maintenance $maintenance)
    {
        $validated = $request->validate([
            'status'           => ['required', Rule::enum(MaintenanceStatus::class)],
            'resolution_notes' => 'nullable|string'
        ]);

        $newStatus = MaintenanceStatus::from($validated['status']);
        
        $data = [
            'status'           => $newStatus,
            'resolution_notes' => $validated['resolution_notes'] ?? $maintenance->resolution_notes
        ];

        if ($newStatus === MaintenanceStatus::Resolved) {
            $data['resolved_at'] = now();
        }

        DB::beginTransaction();
        try {
            $maintenance->update($data);

            if ($newStatus === MaintenanceStatus::Resolved) {
                // Default kembali ke cleaning setelah maintenance
                $this->roomService->changeStatus(
                    $maintenance->room,
                    RoomStatus::Cleaning,
                    $request->user(),
                    null,
                    'Maintenance selesai'
                );
                
                $this->roomService->createHousekeepingTask($maintenance->room, null, $request->user());
            }

            DB::commit();
            return response()->json(['data' => $maintenance->fresh(), 'message' => 'Status maintenance berhasil diperbarui']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
