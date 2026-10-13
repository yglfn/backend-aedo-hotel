<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\HousekeepingController;
use App\Http\Controllers\Api\V1\MaintenanceController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\RoomTypeController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public routes (Auth login, hotel branding settings)
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::get('/settings', [SettingController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Shifts
        Route::prefix('shifts')->group(function () {
            Route::get('/', [ShiftController::class, 'index']);
            Route::get('/current', [ShiftController::class, 'current']);
            Route::post('/start', [ShiftController::class, 'start']);
            Route::post('/end', [ShiftController::class, 'end']);
        });

        // Admin only
        Route::middleware('role:admin')->group(function () {
            Route::put('/settings', [SettingController::class, 'update']);
            
            // Users CRUD
            Route::apiResource('users', UserController::class);
            Route::patch('/users/{user}/activate', [UserController::class, 'activate']);
            Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate']);
            Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        });

        // Room Types
        Route::apiResource('room-types', RoomTypeController::class);

        // Rooms
        Route::get('/rooms/availability', [RoomController::class, 'availability']);
        Route::apiResource('rooms', RoomController::class);
        Route::patch('/rooms/{room}/status', [RoomController::class, 'changeStatus']);

        // Services
        Route::apiResource('services', ServiceController::class);

        // Customers
        Route::apiResource('customers', CustomerController::class);

        // Bookings
        Route::get('/bookings', [\App\Http\Controllers\Api\V1\BookingController::class, 'index']);
        Route::post('/bookings', [\App\Http\Controllers\Api\V1\BookingController::class, 'store']);
        Route::get('/bookings/{booking}', [\App\Http\Controllers\Api\V1\BookingController::class, 'show']);
        Route::post('/bookings/{booking}/check-in', [\App\Http\Controllers\Api\V1\BookingController::class, 'checkIn']);
        Route::post('/bookings/{booking}/check-out', [\App\Http\Controllers\Api\V1\BookingController::class, 'checkOut']);
        Route::post('/bookings/{booking}/cancel', [\App\Http\Controllers\Api\V1\BookingController::class, 'cancel']);

        // Invoices
        Route::get('/invoices', [\App\Http\Controllers\Api\V1\InvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}', [\App\Http\Controllers\Api\V1\InvoiceController::class, 'show']);
        Route::post('/bookings/{booking}/invoice', [\App\Http\Controllers\Api\V1\InvoiceController::class, 'createForBooking']);
        Route::post('/invoices/{invoice}/recalculate', [\App\Http\Controllers\Api\V1\InvoiceController::class, 'recalculate']);

        // Payments (manual)
        Route::post('/bookings/{booking}/payments', [\App\Http\Controllers\Api\V1\PaymentController::class, 'store']);
        Route::get('/payments/{payment}', [\App\Http\Controllers\Api\V1\PaymentController::class, 'show']);
        Route::post('/payments/{payment}/proof', [\App\Http\Controllers\Api\V1\PaymentController::class, 'uploadProof']);
        Route::middleware('role:admin')->patch('/payments/{payment}/verify', [\App\Http\Controllers\Api\V1\PaymentController::class, 'verify']);
        Route::middleware('role:admin')->patch('/payments/{payment}/reject', [\App\Http\Controllers\Api\V1\PaymentController::class, 'reject']);

        // Payments (gateway)
        Route::post('/bookings/{booking}/payments/gateway', [\App\Http\Controllers\Api\V1\GatewayPaymentController::class, 'store']);

        // Payment Gateway Logs (admin only)
        Route::middleware('role:admin')->get('/payment-gateway-logs', [\App\Http\Controllers\Api\V1\PaymentGatewayLogController::class, 'index']);

        // Reports (admin only)
        Route::middleware('role:admin')->prefix('reports')->group(function () {
            Route::get('/occupancy', [\App\Http\Controllers\Api\V1\ReportController::class, 'occupancy']);
            Route::get('/revenue', [\App\Http\Controllers\Api\V1\ReportController::class, 'revenue']);
            Route::get('/reservations', [\App\Http\Controllers\Api\V1\ReportController::class, 'reservations']);
            Route::get('/transactions', [\App\Http\Controllers\Api\V1\ReportController::class, 'transactions']);
            Route::get('/payment-methods', [\App\Http\Controllers\Api\V1\ReportController::class, 'paymentMethods']);
            Route::get('/shifts', [\App\Http\Controllers\Api\V1\ReportController::class, 'shifts']);
            Route::get('/sources', [\App\Http\Controllers\Api\V1\ReportController::class, 'sources']);
            Route::get('/housekeeping', [\App\Http\Controllers\Api\V1\ReportController::class, 'housekeeping']);
        });

        // Housekeeping
        Route::get('/housekeeping-tasks', [HousekeepingController::class, 'index']);
        Route::patch('/housekeeping-tasks/{housekeepingTask}', [HousekeepingController::class, 'update']);

        // Maintenance
        Route::apiResource('maintenance', MaintenanceController::class);
        Route::patch('/maintenance/{maintenance}/status', [MaintenanceController::class, 'updateStatus']);
    });
});

// Webhook — PUBLIK, tanpa auth, tanpa CSRF
// Di api.php, prefix /api sudah otomatis dari bootstrap/app.php
// URL akhir: POST /api/webhooks/payment-gateway
Route::post('/webhooks/payment-gateway', [\App\Http\Controllers\WebhookController::class, 'handlePaymentGateway']);
