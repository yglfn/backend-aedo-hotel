<?php

namespace App\Console\Commands;

use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Console\Command;
use Throwable;

/**
 * payment:sync — jemput status payment gateway yang masih pending langsung ke provider.
 *
 * Ini jalur pemulihan untuk webhook yang tidak masuk (dev lokal tanpa HTTPS publik, provider
 * sedang lambat, tunnel mati). Urutan kerjanya sengaja sync dulu, baru expire: QR/e-wallet yang
 * uangnya ternyata sudah settlement tidak boleh berubah jadi expired, karena expired menahan
 * check-out tanpa sebab.
 *
 * Command yang sama dipakai scheduler (routes/console.php), jadi cron/manual dan terjadwal
 * memakai satu implementasi.
 */
class SyncGatewayPayments extends Command
{
    protected $signature = 'payment:sync {--expired : Tandai payment gateway yang lewat batas waktu sebagai expired}';

    protected $description = 'Sinkronkan payment gateway pending ke provider (webhook yang hilang), lalu opsional tandai yang kedaluwarsa';

    public function handle(PaymentGatewayService $service): int
    {
        try {
            $changed = $service->syncPendingPayments();
        } catch (Throwable $e) {
            // Gateway tidak bisa dihubungi bukan alasan untuk menghentikan pekerjaan meja depan.
            $this->error('Gateway tidak dapat dihubungi: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info($changed . ' payment gateway tersinkron otomatis.');

        if ($this->option('expired')) {
            $this->line($service->expireOverduePayments() . ' payment ditandai expired.');
        }

        return self::SUCCESS;
    }
}
