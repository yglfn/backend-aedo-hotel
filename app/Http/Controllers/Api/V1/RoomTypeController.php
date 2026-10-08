<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use Illuminate\Http\Request;

class RoomTypeController extends Controller
{
    public function index()
    {
        return response()->json(RoomType::paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'price'       => 'required|numeric|min:0',
            'facilities'  => 'nullable|string',
            'max_person'  => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $roomType = RoomType::create($validated);

        return response()->json(['data' => $roomType, 'message' => 'Tipe kamar berhasil ditambahkan'], 201);
    }

    public function show(RoomType $roomType)
    {
        return response()->json(['data' => $roomType]);
    }

    public function update(Request $request, RoomType $roomType)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'price'       => 'sometimes|numeric|min:0',
            'facilities'  => 'nullable|string',
            'max_person'  => 'sometimes|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $roomType->update($validated);

        return response()->json(['data' => $roomType, 'message' => 'Tipe kamar berhasil diperbarui']);
    }

    public function destroy(RoomType $roomType)
    {
        $roomType->delete();
        return response()->json(['message' => 'Tipe kamar berhasil dihapus']);
    }
}
