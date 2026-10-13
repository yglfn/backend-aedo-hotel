<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\ShiftService;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function __construct(private ShiftService $shiftService)
    {
    }

    public function index()
    {
        $shifts = Shift::with('user')->latest()->paginate(15);
        return response()->json($shifts);
    }

    public function start(Request $request)
    {
        $validated = $request->validate([
            'note' => 'nullable|string',
        ]);

        $shift = $this->shiftService->startShift($request->user(), $validated['note'] ?? null);

        return response()->json([
            'message' => 'Shift berhasil dimulai.',
            'data'    => $shift,
        ], 201);
    }

    public function end(Request $request)
    {
        $validated = $request->validate([
            'note' => 'nullable|string',
        ]);

        $shift = $this->shiftService->endShift($request->user(), $validated['note'] ?? null);

        return response()->json([
            'message' => 'Shift berhasil diakhiri.',
            'data'    => $shift,
        ]);
    }

    public function current(Request $request)
    {
        $shift = $this->shiftService->currentShift($request->user());

        return response()->json([
            'data' => $shift,
            'message' => $shift ? 'Shift aktif ditemukan.' : 'Tidak ada shift yang berjalan.'
        ]);
    }
}
