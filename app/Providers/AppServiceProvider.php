<?php

namespace App\Providers;

use App\Services\PaymentGateway\MidtransGateway;
use App\Services\PaymentGateway\PaymentGatewayInterface;
use App\Services\PaymentGateway\SimulatorGateway;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind PaymentGatewayInterface ke implementasi yang aktif.
        // Untuk ganti provider, cukup ubah binding ini (atau gunakan factory berdasarkan config).
        $this->app->bind(PaymentGatewayInterface::class, function ($app) {
            $provider = config('services.payment_gateway.provider', 'simulator');

            // Tidak ada fallback diam-diam: salah set PG_PROVIDER harus langsung kelihatan,
            // kalau tidak payment palsu bisa tercatat sebagai pendapatan nyata.
            return match ($provider) {
                'simulator', 'sandbox' => $app->isProduction()
                    ? throw new RuntimeException(
                        'Payment gateway simulator/sandbox tidak boleh dipakai di produksi. '
                        . 'Pasang implementasi provider asli lalu set PG_PROVIDER yang sesuai.'
                    )
                    : new SimulatorGateway(),

                // Midtrans Snap (sandbox/production) — konstruktor menolak kalau server key
                // kosong; benar/tidaknya environment dicek lewat php artisan payment:test-connection
                'midtrans' => new MidtransGateway(),

                // TODO: diimplementasikan setelah klien memutuskan (AGENTS.md bagian 7 [TENTUKAN])
                'xendit'   => throw new RuntimeException('XenditGateway belum diimplementasikan; jangan set PG_PROVIDER=xendit sebelum class-nya ada.'),

                default => throw new RuntimeException(
                    "Provider payment gateway '{$provider}' tidak dikenal. Nilai yang tersedia: simulator, sandbox, midtrans."
                ),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
