<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomTypeController extends Controller
{
    /**
     * Daftar tipe kamar.
     * Dipakai halaman kelola (search + pagination) dan dropdown form kamar (per_page besar).
     */
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->input('per_page', 15), 200));

        $roomTypes = RoomType::query()
            ->withCount('rooms')
            ->when($request->input('search'), function ($query, $search) {
                $query->where('name', 'like', '%' . $search . '%');
            })
            ->orderBy('name')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json($roomTypes);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules(), $this->messages());

        $roomType = RoomType::create($validated);

        AuditService::log('room_type.created', $roomType, [], $validated, $request);

        return response()->json(['data' => $roomType, 'message' => 'Tipe kamar berhasil ditambahkan'], 201);
    }

    public function show(RoomType $roomType)
    {
        return response()->json(['data' => $roomType->loadCount('rooms')]);
    }

    public function update(Request $request, RoomType $roomType)
    {
        $validated = $request->validate($this->rules($roomType), $this->messages());

        // Harga lama disimpan untuk audit. Snapshot price_per_night di booking_rooms
        // tidak ikut berubah (AGENTS.md bagian 4.3).
        $oldValues = $roomType->only(array_keys($validated));

        $roomType->update($validated);

        AuditService::log('room_type.updated', $roomType, $oldValues, $roomType->only(array_keys($validated)), $request);

        return response()->json(['data' => $roomType, 'message' => 'Tipe kamar berhasil diperbarui']);
    }

    /**
     * Soft delete. Ditolak bila tipe kamar masih dipakai kamar aktif,
     * karena papan kamar & reservasi menampilkan nama tipenya.
     */
    public function destroy(Request $request, RoomType $roomType)
    {
        $roomCount = $roomType->rooms()->count();

        if ($roomCount > 0) {
            return response()->json([
                'message' => "Tipe kamar \"{$roomType->name}\" masih dipakai oleh {$roomCount} kamar. Pindahkan atau hapus kamar-kamar tersebut terlebih dahulu.",
            ], 422);
        }

        $oldValues = $roomType->only(['name', 'price', 'facilities', 'max_person', 'description']);

        $roomType->delete();

        AuditService::log('room_type.deleted', $roomType, $oldValues, [], $request);

        return response()->json(['message' => 'Tipe kamar berhasil dihapus']);
    }

    /**
     * Aturan validasi bersama. Bila tipe kamar diberikan (update),
     * field wajib menjadi optional agar bisa diperbarui sebagian.
     */
    private function rules(?RoomType $roomType = null): array
    {
        $presence = $roomType ? 'sometimes' : 'required';

        return [
            'name'        => [
                $presence,
                'string',
                'max:255',
                Rule::unique('room_types', 'name')
                    ->whereNull('deleted_at')
                    ->ignore($roomType?->id),
            ],
            'price'       => [$presence, 'numeric', 'min:0'],
            'facilities'  => 'nullable|string',
            'max_person'  => [$presence, 'integer', 'min:1'],
            'description' => 'nullable|string',
        ];
    }

    private function messages(): array
    {
        return [
            'name.required'       => 'Nama tipe kamar wajib diisi.',
            'name.string'         => 'Nama tipe kamar harus berupa teks.',
            'name.max'            => 'Nama tipe kamar maksimal 255 karakter.',
            'name.unique'         => 'Nama tipe kamar ini sudah digunakan.',
            'price.required'      => 'Harga per malam wajib diisi.',
            'price.numeric'       => 'Harga per malam harus berupa angka.',
            'price.min'           => 'Harga per malam tidak boleh negatif.',
            'max_person.required' => 'Kapasitas tamu wajib diisi.',
            'max_person.integer'  => 'Kapasitas tamu harus berupa angka.',
            'max_person.min'      => 'Kapasitas tamu minimal 1 orang.',
        ];
    }
}
