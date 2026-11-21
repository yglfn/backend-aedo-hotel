<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditService;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * Field yang boleh dibaca publik (halaman login & header SPA memakai route ini
     * tanpa autentikasi).
     */
    private const PUBLIC_FIELDS = [
        'hotel_name',
        'hotel_address',
        'hotel_phone',
        'hotel_email',
        'default_check_in',
        'default_check_out',
        'tax_percentage',
        'min_dp_percentage',
        'payment_verification_enabled',
    ];

    public function show()
    {
        return response()->json(['data' => Setting::instance()->only(self::PUBLIC_FIELDS)]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'hotel_name'               => 'required|string|max:255',
            'hotel_address'            => 'nullable|string|max:255',
            'hotel_phone'              => 'nullable|string|max:255',
            'hotel_email'              => 'nullable|email|max:255',
            'default_check_in'         => 'required|date_format:H:i:s',
            'default_check_out'        => 'required|date_format:H:i:s',
            'tax_percentage'           => 'required|numeric|min:0|max:100',
            // Ambang DP untuk otomatis/memaksa konfirmasi booking. 0 = tidak memaksa.
            'min_dp_percentage'        => 'nullable|numeric|min:0|max:100',
            // Saklar verifikasi: aktif = transfer/QRIS menunggu admin,
            // nonaktif = langsung lunas saat dicatat.
            'payment_verification_enabled' => 'nullable|boolean',
        ], [
            'hotel_name.required'           => 'Nama hotel wajib diisi.',
            'hotel_email.email'             => 'Format email hotel tidak valid.',
            'default_check_in.date_format'  => 'Jam check-in harus berformat HH:mm:ss.',
            'default_check_out.date_format' => 'Jam check-out harus berformat HH:mm:ss.',
            'tax_percentage.numeric'        => 'Persentase pajak harus berupa angka.',
            'tax_percentage.max'            => 'Persentase pajak maksimal 100.',
            'min_dp_percentage.numeric'     => 'Minimal DP harus berupa angka persen.',
            'min_dp_percentage.max'         => 'Minimal DP maksimal 100%.',
            'payment_verification_enabled.boolean' => 'Saklar verifikasi pembayaran harus benar/salah.',
        ]);

        $setting = Setting::instance();

        // Kolom tidak nullable: field kosong berarti "tidak memaksa DP", bukan NULL.
        // Bila tidak dikirim sama sekali, nilai lama dibiarkan.
        if (array_key_exists('min_dp_percentage', $validated)) {
            $validated['min_dp_percentage'] = (float) ($validated['min_dp_percentage'] ?? 0);
        }

        // Kolom tidak nullable: field yang kosong dianggap nilai default aman
        if (array_key_exists('payment_verification_enabled', $validated)) {
            $validated['payment_verification_enabled'] = $request->boolean('payment_verification_enabled');
        }

        $oldValues = $setting->only(array_keys($validated));

        $setting->update($validated);

        $newValues = $setting->only(array_keys($validated));

        // Perubahan pajak/jam operasional = aksi penting yang wajib diaudit
        // (AGENTS.md bagian 6 butir 10).
        AuditService::log('settings.updated', $setting, $oldValues, $newValues, $request);

        return response()->json([
            'message' => 'Pengaturan berhasil diperbarui.',
            'data'    => $setting->only(self::PUBLIC_FIELDS),
        ]);
    }
}
