<?php

use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Scheduler: tandai pembayaran gateway yang expired setiap 5 menit
Schedule::call(function () {
    $service = app(PaymentGatewayService::class);
    $count   = $service->expireOverduePayments();
    if ($count > 0) {
        logger()->info("[Scheduler] {$count} payment gateway ditandai expired.");
    }
})->everyFiveMinutes()->name('expire-gateway-payments')->withoutOverlapping();
