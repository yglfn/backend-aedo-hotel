<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

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
        return response()->json(['data' => $this->publicSettings(Setting::instance())]);
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
            'hotel_photo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:max_width=2048,max_height=2048',
                Rule::prohibitedIf(fn () => $request->boolean('remove_hotel_photo')),
            ],
            'remove_hotel_photo' => 'nullable|boolean',
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
            'hotel_photo.image' => 'Foto hotel harus berupa gambar.',
            'hotel_photo.mimes' => 'Format foto harus JPG, JPEG, PNG, atau WebP.',
            'hotel_photo.max' => 'Ukuran foto maksimal 2 MB.',
            'hotel_photo.dimensions' => 'Dimensi foto maksimal 2048 x 2048 piksel.',
            'hotel_photo.prohibited' => 'Hapus foto lama terlebih dahulu sebelum mengunggah foto baru.',
            'remove_hotel_photo.boolean' => 'Status penghapusan foto tidak valid.',
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

        $oldPhotoPath = $setting->hotel_photo_path;
        $newPhotoPath = null;

        if ($request->hasFile('hotel_photo')) {
            $newPhotoPath = $request->file('hotel_photo')->store('hotel-profile', 'local');

            if (!$newPhotoPath) {
                throw new Exception('Gagal menyimpan foto hotel.');
            }
        }

        try {
            DB::transaction(function () use ($request, $validated, $setting, $newPhotoPath): void {
                if ($request->boolean('remove_hotel_photo')) {
                    $validated['hotel_photo_path'] = null;
                } elseif ($newPhotoPath) {
                    $validated['hotel_photo_path'] = $newPhotoPath;
                }

                $oldValues = $setting->only(array_keys($validated));
                $setting->update($validated);
                $newValues = $setting->only(array_keys($validated));

                AuditService::log('settings.updated', $setting, $oldValues, $newValues, $request);
            });
        } catch (Throwable $e) {
            if ($newPhotoPath) {
                Storage::disk('local')->delete($newPhotoPath);
            }

            throw $e;
        }

        if ($oldPhotoPath && $oldPhotoPath !== $newPhotoPath) {
            Storage::disk('local')->delete($oldPhotoPath);
        }

        return response()->json([
            'message' => 'Pengaturan berhasil diperbarui.',
            'data' => $this->publicSettings($setting->fresh()),
        ]);
    }

    public function photo()
    {
        $setting = Setting::instance();
        $path = $setting->hotel_photo_path;

        if (!$path || !str_starts_with($path, 'hotel-profile/') || !Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->response($path, 'hotel-profile', [
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    private function publicSettings(Setting $setting): array
    {
        return array_merge($setting->only(self::PUBLIC_FIELDS), [
            'hotel_photo_url' => $this->photoUrl($setting),
        ]);
    }

    private function photoUrl(Setting $setting): ?string
    {
        if (!$setting->hotel_photo_path) {
            return null;
        }

        return url('/api/v1/settings/photo') . '?v=' . $setting->updated_at?->timestamp;
    }
}
