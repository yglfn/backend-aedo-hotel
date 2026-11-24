<?php

namespace App\Observers;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\NotificationService;

class PaymentObserver
{
    public function created(Payment $payment): void
    {
        app(NotificationService::class)->paymentCreated($payment);
    }

    public function updated(Payment $payment): void
    {
        $service = app(NotificationService::class);

        if ($payment->wasChanged('status')) {
            $previous = $payment->getOriginal('status');
            $previousStatus = $previous instanceof PaymentStatus ? $previous : PaymentStatus::from($previous);
            $service->paymentStatusChanged($payment, $previousStatus);
        }

        if ($payment->wasChanged('proof_of_payment') && $payment->proof_of_payment) {
            $service->paymentProofUploaded($payment);
        }
    }
}
