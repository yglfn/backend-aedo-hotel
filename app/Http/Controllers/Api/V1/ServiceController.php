<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\AuditService;
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

    /**
     * Layanan memakai is_active (AGENTS.md bagian 4.2), jadi "hapus" berarti dinonaktifkan.
     * Baris booking_services yang lama tetap bisa dibaca nilainya.
     */
    public function destroy(Request $request, Service $service)
    {
        if (!$service->is_active) {
            return response()->json(['message' => 'Layanan ini sudah tidak aktif.'], 422);
        }

        $service->update(['is_active' => false]);

        AuditService::log('service.deactivated', $service, ['is_active' => true], ['is_active' => false], $request);

        return response()->json(['data' => $service, 'message' => 'Layanan berhasil dinonaktifkan']);
    }

    /**
     * Aktifkan kembali layanan yang sempat dinonaktifkan.
     */
    public function activate(Request $request, Service $service)
    {
        $service->update(['is_active' => true]);

        AuditService::log('service.activated', $service, ['is_active' => false], ['is_active' => true], $request);

        return response()->json(['data' => $service, 'message' => 'Layanan berhasil diaktifkan']);
    }
}
