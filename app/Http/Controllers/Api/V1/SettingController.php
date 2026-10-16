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
     * tanpa autentikasi). Secret gateway sengaja tidak pernah dikirim.
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
        'auto_verify_transfers',
        'auto_verify_max_amount',
        'payment_expiry_minutes',
        'payment_gateway_provider',
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
            // Verifikasi otomatis berbasis bukti (pengganti approval admin di depan)
            'auto_verify_transfers'    => 'nullable|boolean',
            'auto_verify_max_amount'   => 'nullable|numeric|min:0',
            'payment_expiry_minutes'   => 'required|integer|min:1',
            // Opsional: hanya diisi lewat API admin. Nilainya dienkripsi di database
            // dan tidak pernah dikembalikan oleh endpoint mana pun.
            'payment_gateway_provider' => 'nullable|string|max:100',
            'payment_gateway_config'   => 'nullable|array',
        ], [
            'hotel_name.required'           => 'Nama hotel wajib diisi.',
            'hotel_email.email'             => 'Format email hotel tidak valid.',
            'default_check_in.date_format'  => 'Jam check-in harus berformat HH:mm:ss.',
            'default_check_out.date_format' => 'Jam check-out harus berformat HH:mm:ss.',
            'tax_percentage.numeric'        => 'Persentase pajak harus berupa angka.',
            'tax_percentage.max'            => 'Persentase pajak maksimal 100.',
            'min_dp_percentage.numeric'     => 'Minimal DP harus berupa angka persen.',
            'min_dp_percentage.max'         => 'Minimal DP maksimal 100%.',
            'auto_verify_transfers.boolean' => 'Saklar verifikasi otomatis harus benar/salah.',
            'auto_verify_max_amount.numeric' => 'Plafon verifikasi otomatis harus berupa angka.',
            'payment_expiry_minutes.integer' => 'Masa berlaku pembayaran harus berupa angka.',
            'payment_gateway_config.array'  => 'Konfigurasi gateway harus berupa objek.',
        ]);

        $setting = Setting::instance();

        // Kolom tidak nullable: field kosong berarti "tidak memaksa DP", bukan NULL.
        // Bila tidak dikirim sama sekali, nilai lama dibiarkan.
        if (array_key_exists('min_dp_percentage', $validated)) {
            $validated['min_dp_percentage'] = (float) ($validated['min_dp_percentage'] ?? 0);
        }

        // Kolom tidak nullable: field yang kosong dianggap nilai default aman
        if (array_key_exists('auto_verify_transfers', $validated)) {
            $validated['auto_verify_transfers'] = $request->boolean('auto_verify_transfers');
        }

        if (array_key_exists('auto_verify_max_amount', $validated)) {
            $validated['auto_verify_max_amount'] = (float) ($validated['auto_verify_max_amount'] ?? 0);
        }

        // Kolom config jangan pernah disalin ke audit log: membacanya lewat only() akan
        // mendekripsi nilainya. Cukup catat bahwa nilainya berubah.
        $auditedKeys = array_diff(array_keys($validated), ['payment_gateway_config']);
        $changedConfig = array_key_exists('payment_gateway_config', $validated);

        $oldValues = $setting->only($auditedKeys);

        $setting->update($validated);

        $newValues = $setting->only($auditedKeys);
        if ($changedConfig) {
            $oldValues['payment_gateway_config'] = '[terenkripsi, nilai lama]';
            $newValues['payment_gateway_config'] = '[terenkripsi, diganti]';
        }

        // Perubahan pajak/jam operasional & kredensial = aksi penting yang wajib diaudit
        // (AGENTS.md bagian 6 butir 10).
        AuditService::log('settings.updated', $setting, $oldValues, $newValues, $request);

        return response()->json([
            'message' => 'Pengaturan berhasil diperbarui.',
            'data'    => $setting->only(self::PUBLIC_FIELDS),
        ]);
    }
}
