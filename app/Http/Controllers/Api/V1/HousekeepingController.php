<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HousekeepingStatus;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\HousekeepingTask;
use App\Services\RoomService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HousekeepingController extends Controller
{
    public function __construct(private RoomService $roomService)
    {
    }

    public function index()
    {
        $tasks = HousekeepingTask::with(['room', 'assignedTo', 'createdBy'])->paginate(15);
        return response()->json($tasks);
    }

    public function update(Request $request, HousekeepingTask $housekeepingTask)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(HousekeepingStatus::class)],
            'notes'  => 'nullable|string'
        ]);

        $newStatus = HousekeepingStatus::from($validated['status']);
        
        $data = [
            'status' => $newStatus,
            'notes'  => $validated['notes'] ?? $housekeepingTask->notes
        ];

        if ($newStatus === HousekeepingStatus::Completed) {
            $data['completed_at'] = now();
        }

        $housekeepingTask->update($data);

        // Jika selesai, update status kamar
        if ($newStatus === HousekeepingStatus::Completed) {
            $this->roomService->changeStatus(
                $housekeepingTask->room,
                RoomStatus::Available,
                $request->user(),
                $housekeepingTask->booking_id,
                'Housekeeping selesai'
            );
        }

        return response()->json(['data' => $housekeepingTask->fresh(), 'message' => 'Tugas housekeeping berhasil diperbarui']);
    }
}
