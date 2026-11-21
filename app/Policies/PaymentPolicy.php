<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function view(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $user->isPegawai();
    }

    public function downloadProof(User $user, Payment $payment): bool
    {
        return $user->isAdmin() || $user->isPegawai();
    }
}
