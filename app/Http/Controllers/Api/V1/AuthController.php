<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        $user = Auth::user();

        if (!$user->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => ['Akun Anda tidak aktif. Hubungi administrator.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);

        // Regenerate session hanya jika session tersedia (stateful SPA request via Sanctum)
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        // Buat token API untuk digunakan di Postman / client non-SPA
        // Token dihapus dulu agar tidak menumpuk saat login ulang
        $user->tokens()->where('name', 'api-token')->delete();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    public function logout(Request $request)
    {
        // Cabut token API pribadi bila login memakai bearer token. Untuk SPA (session cookie),
        // currentAccessToken() mengembalikan TransientToken yang tidak punya delete() —
        // memanggilnya membuat /auth/logout error 500 dan sesi sebenarnya tidak tertutup.
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        // Logout session-based jika ada
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logout berhasil']);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $userData = $user->toArray();
        $userData['current_shift'] = $user->currentShift();

        return response()->json([
            'data' => $userData
        ]);
    }
}
