<?php

use Illuminate\Support\Facades\Route;

Route::withoutMiddleware('web')->group(function () {
    Route::get('/', function () {
        return response()->json([
            'app' => 'Aedo Hotel API',
            'status' => 'ok',
        ]);
    });

    Route::get('/healthz', function () {
        return response()->json([
            'status' => 'ok',
        ]);
    });

    Route::get('/diagnostic', function () {
        $dbStatus = 'unknown';
        $dbError = null;
        $hasSettingsTable = false;
        $hasUsersTable = false;
        $hasSessionsTable = false;
        $settingsCount = 0;
        $usersCount = 0;

        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
            $dbStatus = 'connected';
            $hasSettingsTable = \Illuminate\Support\Facades\Schema::hasTable('settings');
            $hasUsersTable = \Illuminate\Support\Facades\Schema::hasTable('users');
            $hasSessionsTable = \Illuminate\Support\Facades\Schema::hasTable('sessions');

            if ($hasSettingsTable) {
                $settingsCount = \Illuminate\Support\Facades\DB::table('settings')->count();
            }
            if ($hasUsersTable) {
                $usersCount = \Illuminate\Support\Facades\DB::table('users')->count();
            }
        } catch (\Throwable $e) {
            $dbStatus = 'error';
            $dbError = $e->getMessage();
        }

        return response()->json([
            'app_key_set' => !empty(config('app.key')),
            'app_key_length' => strlen(config('app.key') ?? ''),
            'session_driver' => config('session.driver'),
            'session_secure' => config('session.secure'),
            'session_same_site' => config('session.same_site'),
            'database_default' => config('database.default'),
            'database_status' => $dbStatus,
            'database_error' => $dbError,
            'has_settings_table' => $hasSettingsTable,
            'has_users_table' => $hasUsersTable,
            'has_sessions_table' => $hasSessionsTable,
            'settings_count' => $settingsCount,
            'users_count' => $usersCount,
        ]);
    });
});
