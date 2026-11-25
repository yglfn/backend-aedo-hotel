<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\AuditService;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Service::query();

        if ($request->filled('search')) {
            $term = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('unit', 'like', $term)
                  ->orWhere('description', 'like', $term);
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));

        return response()->json($query->latest()->paginate($perPage));
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

        AuditService::log('service.created', $service, [], $service->only([
            'name', 'price', 'unit', 'description', 'is_active',
        ]), $request);

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

        $oldValues = $service->only(['name', 'price', 'unit', 'description', 'is_active']);
        $service->update($validated);

        AuditService::log('service.updated', $service, $oldValues, $service->only([
            'name', 'price', 'unit', 'description', 'is_active',
        ]), $request);

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
        $oldValues = ['is_active' => $service->is_active];
        $service->update(['is_active' => true]);

        AuditService::log('service.activated', $service, $oldValues, ['is_active' => true], $request);

        return response()->json(['data' => $service, 'message' => 'Layanan berhasil diaktifkan']);
    }
}
