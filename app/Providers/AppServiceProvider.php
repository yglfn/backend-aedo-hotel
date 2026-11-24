<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\HousekeepingTask;
use App\Models\Maintenance;
use App\Models\Payment;
use App\Models\Shift;
use App\Observers\BookingObserver;
use App\Observers\HousekeepingTaskObserver;
use App\Observers\MaintenanceObserver;
use App\Observers\PaymentObserver;
use App\Observers\ShiftObserver;
use App\Policies\PaymentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Payment::class, PaymentPolicy::class);

        Booking::observe(BookingObserver::class);
        Payment::observe(PaymentObserver::class);
        HousekeepingTask::observe(HousekeepingTaskObserver::class);
        Maintenance::observe(MaintenanceObserver::class);
        Shift::observe(ShiftObserver::class);
    }
}
