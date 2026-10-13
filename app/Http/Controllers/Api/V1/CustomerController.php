<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::withCount(['bookings' => function ($q) {
            $q->where('status', '!=', \App\Enums\BookingStatus::Cancelled->value);
        }]);
        
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
        }
        
        return response()->json($query->paginate(15));
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
}
