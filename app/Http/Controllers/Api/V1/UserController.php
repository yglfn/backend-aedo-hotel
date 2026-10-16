<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\HousekeepingTask;
use App\Models\Maintenance;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->input('per_page', 15), 100));

        $users = User::query()
            // open_shifts_count dipakai halaman pegawai untuk menjelaskan akun yang belum bisa dinonaktifkan
            ->withCount(['shifts as open_shifts_count' => function ($query) {
                $query->where('status', ShiftStatus::Open);
            }])
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%' . trim((string) $request->input('search')) . '%';
                $query->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->when($request->input('role'), fn ($query, $role) => $query->where('role', $role))
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            })
            ->orderBy('role')
            ->orderBy('name')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules(), $this->messages());

        $validated['is_active'] = $request->boolean('is_active', true);

        $user = User::create($validated);

        AuditService::log('user.created', $user, [], $user->only(['name', 'email', 'role', 'is_active']), $request);

        return response()->json(['data' => $user, 'message' => 'User berhasil ditambahkan'], 201);
    }

    public function show(User $user)
    {
        return response()->json(['data' => $user->loadCount([
            'shifts as open_shifts_count' => fn ($query) => $query->where('status', ShiftStatus::Open),
        ])]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate($this->rules($user), $this->messages());

        if (array_key_exists('is_active', $validated)) {
            $validated['is_active'] = $request->boolean('is_active');
        }

        $losesAdminAccess = $user->isAdmin() && (
            (isset($validated['role']) && $validated['role'] !== UserRole::Admin->value)
            || (array_key_exists('is_active', $validated) && !$validated['is_active'])
        );

        if ($losesAdminAccess) {
            if ($request->user()->is($user)) {
                return response()->json([
                    'message' => 'Anda tidak dapat menurunkan role atau menonaktifkan akun Anda sendiri.',
                ], 422);
            }

            if ($this->otherActiveAdmins($user->id) === 0) {
                return response()->json([
                    'message' => 'Sisa satu admin aktif. Aktifkan admin lain dulu sebelum mengubah akun ini.',
                ], 422);
            }
        }

        $oldValues = $user->only(array_keys($validated));
        $user->update($validated);

        AuditService::log('user.updated', $user, $oldValues, $user->only(array_keys($validated)), $request);

        return response()->json(['data' => $user, 'message' => 'User berhasil diperbarui']);
    }

    /**
     * Akun pengguna tidak dihapus bila sudah dipakai transaksi (AGENTS.md bagian 4.2).
     * Yang boleh dihapus hanya akun tes/kosong tanpa jejak operasional.
     */
    public function destroy(Request $request, User $user)
    {
        if ($request->user()->is($user)) {
            return response()->json(['message' => 'Anda tidak dapat menghapus akun Anda sendiri.'], 422);
        }

        if ($user->isAdmin() && $this->otherActiveAdmins($user->id) === 0) {
            return response()->json([
                'message' => 'Sisa satu admin aktif. Akun admin terakhir tidak boleh dihapus.',
            ], 422);
        }

        $usedBy = $this->transactionReferences($user);

        if (!empty($usedBy)) {
            return response()->json([
                'message' => 'Akun tidak dapat dihapus karena sudah tercatat pada ' . implode(', ', $usedBy)
                    . '. Nonaktifkan saja akun tersebut.',
            ], 422);
        }

        $oldValues = $user->only(['name', 'email', 'role', 'is_active']);
        $user->delete();

        AuditService::log('user.deleted', $user, $oldValues, [], $request);

        return response()->json(['message' => 'User berhasil dihapus']);
    }

    public function activate(Request $request, User $user)
    {
        $user->update(['is_active' => true]);

        AuditService::log('user.activated', $user, ['is_active' => false], ['is_active' => true], $request);

        return response()->json(['data' => $user, 'message' => 'User berhasil diaktifkan']);
    }

    /**
     * Nonaktif akun. Dijaga agar shift tidak menggantung dan admin terakhir tidak terkunci.
     */
    public function deactivate(Request $request, User $user)
    {
        if ($request->user()->is($user)) {
            return response()->json([
                'message' => 'Anda tidak dapat menonaktifkan akun Anda sendiri.',
            ], 422);
        }

        if ($user->isAdmin() && $this->otherActiveAdmins($user->id) === 0) {
            return response()->json([
                'message' => 'Sisa satu admin aktif. Minimal satu admin harus tetap aktif.',
            ], 422);
        }

        if ($this->hasOpenShift($user)) {
            return response()->json([
                'message' => 'Akun masih memiliki shift berjalan. Minta pegawai tersebut menutup shift dulu sebelum dinonaktifkan.',
            ], 422);
        }

        $user->update(['is_active' => false]);

        // Cabut token API agar sesi lama tidak bisa dipakai lagi
        $user->tokens()->delete();

        AuditService::log('user.deactivated', $user, ['is_active' => true], ['is_active' => false], $request);

        return response()->json(['data' => $user, 'message' => 'User berhasil dinonaktifkan']);
    }

    public function resetPassword(Request $request, User $user)
    {
        $validated = $request->validate([
            'password' => 'required|string|min:8',
        ], [
            'password.required' => 'Password wajib diisi.',
            'password.min'      => 'Password minimal 8 karakter.',
        ]);

        // Kolom password memakai cast 'hashed', jadi plain text aman disimpan
        $user->update(['password' => $validated['password']]);

        AuditService::log('user.password_reset', $user, [], [], $request);

        return response()->json(['message' => 'Password berhasil direset']);
    }

    private function rules(?User $user = null): array
    {
        $presence = $user ? 'sometimes' : 'required';

        return [
            'name'     => [$presence, 'string', 'max:255'],
            'email'    => [
                $presence,
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'password' => [$presence, 'string', 'min:8'],
            'role'     => [$presence, Rule::enum(UserRole::class)],
            // create: default aktif bila tidak dikirim; update: hanya diubah bila dikirim
            'is_active' => $user ? 'sometimes|boolean' : 'nullable|boolean',
        ];
    }

    private function messages(): array
    {
        return [
            'name.required'        => 'Nama wajib diisi.',
            'name.max'             => 'Nama maksimal 255 karakter.',
            'email.required'       => 'Email wajib diisi.',
            'email.email'          => 'Format email tidak valid.',
            'email.unique'         => 'Email ini sudah digunakan.',
            'password.required'    => 'Password wajib diisi.',
            'password.min'         => 'Password minimal 8 karakter.',
            'role.required'        => 'Role wajib dipilih.',
            'role.in'              => 'Role harus admin atau pegawai.',
            'is_active.boolean'    => 'Status akun aktif tidak valid.',
        ];
    }

    private function otherActiveAdmins(int $excludeUserId): int
    {
        return User::query()
            ->where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->whereKeyNot($excludeUserId)
            ->count();
    }

    private function hasOpenShift(User $user): bool
    {
        return $user->shifts()->where('status', ShiftStatus::Open)->exists();
    }

    /**
     * Modul apa saja yang sudah memakai akun ini. Dipakai untuk menolak hard delete.
     *
     * @return array<int, string>
     */
    private function transactionReferences(User $user): array
    {
        $references = [];

        if (Booking::where('created_by', $user->id)->exists()) {
            $references[] = 'reservasi';
        }
        if (Payment::where('created_by', $user->id)->orWhere('verified_by', $user->id)->exists()) {
            $references[] = 'pembayaran';
        }
        if ($user->shifts()->exists()) {
            $references[] = 'shift';
        }
        if (HousekeepingTask::where('created_by', $user->id)->orWhere('assigned_to', $user->id)->exists()) {
            $references[] = 'housekeeping';
        }
        if (Maintenance::where('reported_by', $user->id)->orWhere('assigned_to', $user->id)->exists()) {
            $references[] = 'maintenance';
        }

        return $references;
    }
}
