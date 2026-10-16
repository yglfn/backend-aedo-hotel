<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MaintenanceStatus;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Maintenance;
use App\Models\Room;
use App\Services\AuditService;
use App\Services\RoomService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class MaintenanceController extends Controller
{
    public function __construct(private RoomService $roomService)
    {
    }

    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->input('per_page', 50), 200));

        $maintenance = Maintenance::with(['room', 'reportedBy', 'assignedTo'])
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate($perPage);

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

    /**
     * Ubah isi laporan. Pemilik laporan boleh memperbaikinya selama belum dikerjakan;
     * penugasan petugas hanya untuk admin (AGENTS.md bagian 3).
     */
    public function update(Request $request, Maintenance $maintenance)
    {
        $user = $request->user();

        if (!$user->isAdmin() && $maintenance->reported_by !== $user->id) {
            return response()->json(['message' => 'Hanya pelapor atau admin yang dapat mengubah laporan ini.'], 403);
        }

        if ($maintenance->status === MaintenanceStatus::Resolved) {
            return response()->json(['message' => 'Laporan yang sudah selesai tidak dapat diubah lagi.'], 422);
        }

        $validated = $request->validate([
            'title'       => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string',
            'priority'    => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
            'assigned_to' => [$user->isAdmin() ? 'sometimes|nullable' : 'prohibited', 'integer', 'exists:users,id'],
        ], [
            'title.max'          => 'Judul laporan maksimal 255 karakter.',
            'priority.in'        => 'Prioritas harus low, medium, high, atau critical.',
            'assigned_to.exists' => 'Petugas yang ditugaskan tidak ditemukan.',
            'assigned_to.prohibited' => 'Hanya admin yang dapat menugaskan petugas.',
        ]);

        $oldValues = $maintenance->only(array_keys($validated));
        $maintenance->update($validated);

        AuditService::log('maintenance.updated', $maintenance, $oldValues, $maintenance->only(array_keys($validated)), $request);

        return response()->json([
            'data'    => $maintenance->fresh()->load(['room', 'reportedBy', 'assignedTo']),
            'message' => 'Laporan maintenance berhasil diperbarui',
        ]);
    }

    /**
     * Hapus laporan. Admin bebas menghapus; pegawai hanya selama masih berstatus reported.
     */
    public function destroy(Request $request, Maintenance $maintenance)
    {
        $user = $request->user();

        if (!$user->isAdmin()
            && ($maintenance->reported_by !== $user->id || $maintenance->status !== MaintenanceStatus::Reported)) {
            return response()->json([
                'message' => 'Laporan sudah diproses. Hanya admin yang dapat menghapusnya.',
            ], 422);
        }

        $oldValues = $maintenance->only(['room_id', 'title', 'description', 'priority', 'status']);
        $maintenance->delete();

        AuditService::log('maintenance.deleted', $maintenance, $oldValues, [], $request);

        return response()->json(['message' => 'Laporan maintenance berhasil dihapus']);
    }
}
