<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('notifications:daily-operational')
    ->dailyAt('07:00')
    ->withoutOverlapping();

// Batalkan otomatis reservasi no-show (tamu tidak datang pada hari check-in).
// Dijalankan pukul 02:00 — 2 jam setelah tengah malam memberi toleransi
// keterlambatan tamu yang masih bisa check-in larut malam.
// Cocokkan --grace-hours dengan jam ini bila ingin mengubah toleransi.
Schedule::command('bookings:auto-cancel-no-show --grace-hours=2')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();
