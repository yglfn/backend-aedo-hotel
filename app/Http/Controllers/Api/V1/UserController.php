<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $users = User::paginate(15);
        return response()->json($users);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role'     => ['required', Rule::in(['admin', 'pegawai'])],
        ]);

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);

        return response()->json(['data' => $user, 'message' => 'User berhasil ditambahkan'], 201);
    }

    public function show(User $user)
    {
        return response()->json(['data' => $user]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name'  => 'sometimes|string|max:255',
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role'  => ['sometimes', Rule::in(['admin', 'pegawai'])],
        ]);

        $user->update($validated);

        return response()->json(['data' => $user, 'message' => 'User berhasil diperbarui']);
    }

    public function activate(User $user)
    {
        $user->update(['is_active' => true]);
        return response()->json(['message' => 'User berhasil diaktifkan']);
    }

    public function deactivate(User $user)
    {
        $user->update(['is_active' => false]);
        return response()->json(['message' => 'User berhasil dinonaktifkan']);
    }

    public function resetPassword(Request $request, User $user)
    {
        $validated = $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $user->update([
            'password' => Hash::make($validated['password'])
        ]);

        return response()->json(['message' => 'Password berhasil direset']);
    }
}
