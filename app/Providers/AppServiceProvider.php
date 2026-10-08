<?php

namespace App\Providers;

use App\Services\PaymentGateway\PaymentGatewayInterface;
use App\Services\PaymentGateway\SimulatorGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind PaymentGatewayInterface ke implementasi yang aktif.
        // Untuk ganti provider, cukup ubah binding ini (atau gunakan factory berdasarkan config).
        $this->app->bind(PaymentGatewayInterface::class, function ($app) {
            $provider = config('services.payment_gateway.provider', 'simulator');

            return match ($provider) {
                'simulator' => new SimulatorGateway(),
                // 'midtrans'  => new MidtransGateway(), // tambahkan nanti
                // 'xendit'    => new XenditGateway(),
                default     => new SimulatorGateway(),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
