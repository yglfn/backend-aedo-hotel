<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureShiftIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Admin tidak perlu shift
        if ($user && $user->role === UserRole::Admin) {
            return $next($request);
        }

        if ($user && !$user->currentShift()) {
            return response()->json([
                'message' => 'Anda harus memulai shift terlebih dahulu sebelum mencatat transaksi.',
            ], 403);
        }

        return $next($request);
    }
}
