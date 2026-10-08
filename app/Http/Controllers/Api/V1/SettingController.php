<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function show()
    {
        return response()->json(['data' => Setting::instance()]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'hotel_name'             => 'required|string|max:255',
            'hotel_address'          => 'nullable|string|max:255',
            'hotel_phone'            => 'nullable|string|max:255',
            'hotel_email'            => 'nullable|email|max:255',
            'default_check_in'       => 'required|date_format:H:i:s',
            'default_check_out'      => 'required|date_format:H:i:s',
            'tax_percentage'         => 'required|numeric|min:0|max:100',
            'payment_expiry_minutes' => 'required|integer|min:1',
        ]);

        $setting = Setting::instance();
        $setting->update($validated);

        return response()->json([
            'message' => 'Pengaturan berhasil diperbarui.',
            'data'    => $setting,
        ]);
    }
}
