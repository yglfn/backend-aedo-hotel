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
});
