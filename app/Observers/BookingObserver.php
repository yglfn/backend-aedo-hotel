<?php

namespace App\Observers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\NotificationService;

class BookingObserver
{
    public function created(Booking $booking): void
    {
        app(NotificationService::class)->bookingCreated($booking);
    }

    public function updated(Booking $booking): void
    {
        if (!$booking->wasChanged('status')) {
            return;
        }

        $previous = $booking->getOriginal('status');
        $previousStatus = $previous instanceof BookingStatus ? $previous : BookingStatus::from($previous);

        app(NotificationService::class)->bookingStatusChanged($booking, $previousStatus);
    }
}
