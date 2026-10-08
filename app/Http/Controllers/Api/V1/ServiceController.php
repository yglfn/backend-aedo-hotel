<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index()
    {
        return response()->json(Service::paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'price'       => 'required|numeric|min:0',
            'unit'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active'   => 'boolean'
        ]);

        $service = Service::create($validated);

        return response()->json(['data' => $service, 'message' => 'Layanan berhasil ditambahkan'], 201);
    }

    public function show(Service $service)
    {
        return response()->json(['data' => $service]);
    }

    public function update(Request $request, Service $service)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'price'       => 'sometimes|numeric|min:0',
            'unit'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_active'   => 'boolean'
        ]);

        $service->update($validated);

        return response()->json(['data' => $service, 'message' => 'Layanan berhasil diperbarui']);
    }
}
