<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\HousekeepingController;
use App\Http\Controllers\Api\V1\MaintenanceController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\RoomTypeController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public routes (Auth login, hotel branding settings)
    // throttle: membatasi percobaan login per IP (AGENTS.md bagian 9)
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/settings', [SettingController::class, 'show']);
    Route::get('/settings/photo', [SettingController::class, 'photo']);

    // Akun yang sudah dinonaktifkan langsung kehilangan akses, termasuk sesi yang masih terbuka
    // (AGENTS.md bagian 3: is_active = false tidak boleh memakai sistem).
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index']);

        Route::get('/reviews', [ReviewController::class, 'index']);
        Route::post('/bookings/{booking}/review', [ReviewController::class, 'store']);

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

        // Room Types — lihat: semua user login, kelola (CRUD): admin saja (AGENTS.md bagian 3)
        Route::get('/room-types', [RoomTypeController::class, 'index']);
        Route::get('/room-types/{roomType}', [RoomTypeController::class, 'show']);

        Route::middleware('role:admin')->group(function () {
            Route::post('/room-types', [RoomTypeController::class, 'store']);
            Route::put('/room-types/{roomType}', [RoomTypeController::class, 'update']);
            Route::patch('/room-types/{roomType}', [RoomTypeController::class, 'update']);
            Route::delete('/room-types/{roomType}', [RoomTypeController::class, 'destroy']);
        });

        // Rooms — lihat & ubah status: semua role; tambah/ubah/hapus data kamar: admin (AGENTS.md bagian 3)
        Route::get('/rooms/availability', [RoomController::class, 'availability']);
        Route::get('/rooms', [RoomController::class, 'index']);
        Route::get('/rooms/{room}', [RoomController::class, 'show']);
        Route::patch('/rooms/{room}/status', [RoomController::class, 'changeStatus']);

        Route::middleware('role:admin')->group(function () {
            Route::post('/rooms', [RoomController::class, 'store']);
            Route::put('/rooms/{room}', [RoomController::class, 'update']);
            Route::patch('/rooms/{room}', [RoomController::class, 'update']);
            Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);
        });

        // Services — lihat: semua role (dipakai form reservasi); kelola & nonaktifkan: admin
        Route::get('/services', [ServiceController::class, 'index']);
        Route::get('/services/{service}', [ServiceController::class, 'show']);

        Route::middleware('role:admin')->group(function () {
            Route::post('/services', [ServiceController::class, 'store']);
            Route::put('/services/{service}', [ServiceController::class, 'update']);
            Route::patch('/services/{service}', [ServiceController::class, 'update']);
            Route::patch('/services/{service}/activate', [ServiceController::class, 'activate']);
            Route::delete('/services/{service}', [ServiceController::class, 'destroy']);
        });

        // Customers — semua role boleh melihat & menambah tamu;
        // penghapusan data tamu (PII) khusus admin
        Route::apiResource('customers', CustomerController::class)->except('destroy');
        Route::middleware('role:admin')->delete('/customers/{customer}', [CustomerController::class, 'destroy']);

        // Bookings
        Route::get('/bookings', [\App\Http\Controllers\Api\V1\BookingController::class, 'index']);
        Route::post('/bookings', [\App\Http\Controllers\Api\V1\BookingController::class, 'store']);
        Route::post('/bookings/{booking}/services', [\App\Http\Controllers\Api\V1\BookingController::class, 'addServices']);
        Route::get('/bookings/{booking}', [\App\Http\Controllers\Api\V1\BookingController::class, 'show']);
        Route::post('/bookings/{booking}/confirm', [\App\Http\Controllers\Api\V1\BookingController::class, 'confirm']);
        Route::post('/bookings/{booking}/check-in', [\App\Http\Controllers\Api\V1\BookingController::class, 'checkIn']);
        Route::post('/bookings/{booking}/check-out', [\App\Http\Controllers\Api\V1\BookingController::class, 'checkOut']);
        Route::post('/bookings/{booking}/cancel', [\App\Http\Controllers\Api\V1\BookingController::class, 'cancel']);

        // Invoices
        Route::get('/invoices', [\App\Http\Controllers\Api\V1\InvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}', [\App\Http\Controllers\Api\V1\InvoiceController::class, 'show']);
        Route::post('/bookings/{booking}/invoice', [\App\Http\Controllers\Api\V1\InvoiceController::class, 'createForBooking']);
        Route::post('/invoices/{invoice}/recalculate', [\App\Http\Controllers\Api\V1\InvoiceController::class, 'recalculate']);

        // Payments — daftar bisa dilihat admin & pegawai; pencatatan transaksi
        // mewajibkan shift berjalan (AGENTS.md bagian 6 butir 9)
        Route::get('/payments', [\App\Http\Controllers\Api\V1\PaymentController::class, 'index']);
        Route::get('/payments/{payment}', [\App\Http\Controllers\Api\V1\PaymentController::class, 'show']);
        Route::get('/payments/{payment}/proof', [\App\Http\Controllers\Api\V1\PaymentController::class, 'downloadProof']);

        Route::middleware('shift')->group(function () {
            Route::post('/bookings/{booking}/payments', [\App\Http\Controllers\Api\V1\PaymentController::class, 'store']);
        });

        Route::middleware('role:admin')->patch('/payments/{payment}/verify', [\App\Http\Controllers\Api\V1\PaymentController::class, 'verify']);
        Route::middleware('role:admin')->patch('/payments/{payment}/reject', [\App\Http\Controllers\Api\V1\PaymentController::class, 'reject']);
        Route::middleware('role:admin')->post('/payments/{payment}/reverse', [\App\Http\Controllers\Api\V1\PaymentController::class, 'reverse']);

        // Reports (admin only)
        Route::middleware('role:admin')->prefix('reports')->group(function () {
            Route::get('/summary', [\App\Http\Controllers\Api\V1\ReportController::class, 'summary']);
            Route::get('/occupancy', [\App\Http\Controllers\Api\V1\ReportController::class, 'occupancy']);
            Route::get('/revenue', [\App\Http\Controllers\Api\V1\ReportController::class, 'revenue']);
            Route::get('/reservations', [\App\Http\Controllers\Api\V1\ReportController::class, 'reservations']);
            Route::get('/transactions', [\App\Http\Controllers\Api\V1\ReportController::class, 'transactions']);
            Route::get('/payment-methods', [\App\Http\Controllers\Api\V1\ReportController::class, 'paymentMethods']);
            Route::get('/shifts', [\App\Http\Controllers\Api\V1\ReportController::class, 'shifts']);
            Route::get('/sources', [\App\Http\Controllers\Api\V1\ReportController::class, 'sources']);
            Route::get('/housekeeping', [\App\Http\Controllers\Api\V1\ReportController::class, 'housekeeping']);
        });

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read']);
        Route::delete('/notifications', [NotificationController::class, 'destroyAll']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

        // Housekeeping
        Route::get('/housekeeping-tasks', [HousekeepingController::class, 'index']);
        Route::patch('/housekeeping-tasks/{housekeepingTask}', [HousekeepingController::class, 'update']);

        // Maintenance
        Route::apiResource('maintenance', MaintenanceController::class);
        Route::patch('/maintenance/{maintenance}/status', [MaintenanceController::class, 'updateStatus']);
    });
});
