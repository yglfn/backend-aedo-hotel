<?php

namespace App\Console\Commands;

use App\Services\PaymentGateway\MidtransGateway;
use App\Services\PaymentGateway\PaymentGatewayInterface;
use Illuminate\Console\Command;

/**
 * Menguji koneksi payment gateway yang AKTIF, tanpa menebak-nebak dari prefiks kunci.
 *
 * Untuk Midtrans ini memanggil API sungguhan (probe kredensial) sehingga jawaban
 * "kunci salah" vs "kunci benar tapi environment salah" datang dari server Midtrans,
 * bukan dari asumsi kode kita. Berguna saat pindah sandbox -> production.
 */
class TestPaymentGatewayConnection extends Command
{
    protected $signature = 'payment:test-connection';

    protected $description = 'Uji koneksi/kredensial payment gateway yang aktif (midtrans: probe ke server Midtrans)';

    public function handle(PaymentGatewayInterface $gateway): int
    {
        $this->info('Provider aktif: ' . $gateway->getProviderName());

        if (!$gateway instanceof MidtransGateway) {
            $this->comment('Provider bukan midtrans — tidak ada koneksi keluar yang bisa diuji.');
            $this->line('Isi PG_MIDTRANS_SERVER_KEY di .env lalu set PG_PROVIDER=midtrans untuk memakai Midtrans.');

            return self::SUCCESS;
        }

        $result = $gateway->testConnection();

        // Sering salah konfigurasi: tanpa finish_url, Midtrans memakai default dashboard
        // (https://example.com) dan tamu berakhir di halaman mati setelah membayar.
        $finish = trim((string) config('services.midtrans.finish_url', ''));

        $this->line('Base URL : ' . $result['base_url']);
        $this->line('HTTP     : ' . ($result['status'] ?? '-'));
        $this->line('Hasil    : ' . $result['message']);
        $this->line('Halaman hasil: ' . ($finish !== '' ? $finish : '(KOSONG)'));

        if ($finish === '') {
            $this->warn('PG_MIDTRANS_FINISH_URL/FRONTEND_URL kosong: tamu akan diarahkan Midtrans ke '
                . 'default dashboard (https://example.com), bukan ke aplikasi Anda.');
        } elseif (!filter_var($finish, FILTER_VALIDATE_URL)) {
            $this->warn('Nilai halaman hasil bukan URL yang sah: ' . $finish);
        }

        if ($result['ok']) {
            $this->info('OK — kredensial Midtrans diterima. Pembayaran gateway siap dipakai.');

            return self::SUCCESS;
        }

        $this->error('GAGAL — perbaiki .env sebelum demo. PG_MIDTRANS_IS_PRODUCTION='
            . var_export(config('services.midtrans.is_production'), true));

        return self::FAILURE;
    }
}
