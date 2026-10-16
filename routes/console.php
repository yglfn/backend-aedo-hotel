<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Scheduler gateway: tanya dulu status sebenarnya ke provider, baru tandai expired (urutan itu
// ada di dalam command payment:sync --expired). QR/e-wallet yang uangnya ternyata masuk tidak
// boleh berubah jadi expired, karena expired menahan check-out tanpa sebab.
Schedule::command('payment:sync --expired')
    ->everyFiveMinutes()
    ->name('sync-then-expire-gateway-payments')
    ->withoutOverlapping();
