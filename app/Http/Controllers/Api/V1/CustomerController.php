<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\AuditService;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::withCount(['bookings' => function ($q) {
            $q->where('status', '!=', \App\Enums\BookingStatus::Cancelled->value);
        }]);
        
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));

            // Dibungkus closure agar operator OR tidak melonggarkan filter lain
            $query->where(function ($sub) use ($search) {
                $term = '%' . $search . '%';
                $sub->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));

        $customers = $query->orderByDesc('created_at')->paginate($perPage);
        $customers->getCollection()->transform(function (Customer $customer) {
            if ($customer->ktp && strlen($customer->ktp) >= 8) {
                $customer->ktp = substr($customer->ktp, 0, 4)
                    . str_repeat('•', strlen($customer->ktp) - 8)
                    . substr($customer->ktp, -4);
            }

            return $customer;
        });

        return response()->json($customers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'phone'   => 'nullable|string|max:50',
            'email'   => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'ktp'     => 'nullable|string'
        ]);

        $customer = Customer::create($validated);

        return response()->json(['data' => $customer, 'message' => 'Customer berhasil ditambahkan'], 201);
    }

    public function show(Customer $customer)
    {
        return response()->json(['data' => $customer]);
    }

    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name'    => 'sometimes|string|max:255',
            'phone'   => 'nullable|string|max:50',
            'email'   => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'ktp'     => 'nullable|string'
        ]);

        $customer->update($validated);

        return response()->json(['data' => $customer, 'message' => 'Customer berhasil diperbarui']);
    }

    /**
     * Data tamu hanya boleh dihapus selama belum pernah bertransaksi.
     * Riwayat tamu harus tetap tersimpan untuk laporan & audit (AGENTS.md bagian 4.2).
     */
    public function destroy(Request $request, Customer $customer)
    {
        $bookingCount = $customer->bookings()->count();

        if ($bookingCount > 0) {
            return response()->json([
                'message' => "Data tamu tidak dapat dihapus karena memiliki {$bookingCount} reservasi. Riwayat tamu harus disimpan untuk laporan dan audit.",
            ], 422);
        }

        // Jangan pernah menyalin KTP ke audit log (AGENTS.md bagian 4.3)
        $oldValues = $customer->only(['name', 'phone', 'email', 'address']);
        $customer->delete();

        AuditService::log('customer.deleted', $customer, $oldValues, [], $request);

        return response()->json(['message' => 'Data tamu berhasil dihapus']);
    }
}
