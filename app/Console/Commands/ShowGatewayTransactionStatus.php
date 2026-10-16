<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PaymentGateway\PaymentGatewayInterface;
use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Membentangkan satu transaksi gateway: apa kata provider, apa yang tersimpan di sistem kami.
 *
 * Hanya membaca — tidak mengubah status apa pun. Dipakai saat dashboard provider bilang
 * "sudah settlement" tapi aplikasi masih bilang menunggu konfirmasi.
 *
 * Pertanyaannya tidak hanya lewat order_id: Midtrans menyimpan e-wallet hasil Snap (DANA/GoPay)
 * di bawah order_id mereka sendiri, jadi order_id kita tidak dikenal Core API walaupun
 * transaksinya sudah lunas. PaymentGatewayService::inspectStatus() mencoba semua id yang kami
 * punya untuk payment ini (order_id, transaction_id provider, token Snap) dan menunjukkan yang
 * menjawab.
 */
class ShowGatewayTransactionStatus extends Command
{
    protected $signature = 'payment:status {order_id : order_id transaksi, mis. AEDO-CPHEIPPMK8Z4M9P8}';

    protected $description = 'Tampilkan jawaban gateway untuk satu transaksi (lewat semua id yang diketahui) + payment lokal yang cocok (read-only)';

    public function handle(PaymentGatewayService $service, PaymentGatewayInterface $gateway): int
    {
        $orderId = trim((string) $this->argument('order_id'));
        $payment = Payment::where('gateway_transaction_id', $orderId)->first();

        $this->info('Provider : ' . $gateway->getProviderName());
        $this->line('Order id : ' . $orderId);

        try {
            // Payment lokal -> tanya lewat seluruh id yang kami punya; kalau tidak ada baris
            // payment, satu-satunya pegangan adalah order_id yang baru dimasukkan.
            $status = $payment
                ? $service->inspectStatus($payment)['answer']
                : $gateway->getTransactionStatus($orderId);
        } catch (Throwable $e) {
            $this->error('Gateway tidak dapat dihubungi: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line('Kata gateway : ' . json_encode($status, JSON_UNESCAPED_SLASHES));

        if (($status['note'] ?? null) !== null) {
            $this->line('Catatan      : ' . $status['note']);
        }

        if (!$payment) {
            $this->newLine();
            $this->warn('Tidak ada payment lokal dengan order_id itu.');
            $this->line('  Artinya transaksi ini "yatim" di sisi kita: uangnya ada di provider tapi');
            $this->line('  tidak ada baris payment yang menagihnya. Cek kembali apakah DB pernah');
            $this->line('  di-fresh/di-reset, atau ambil order_id dari daftar transaksi aplikasi');
            $this->line('  (Daftar Pembayaran) dan jalankan perintah ini dengan order_id itu.');

            return self::SUCCESS;
        }

        $this->line('Payment lokal: #' . $payment->id
            . ' status=' . $payment->status->value
            . ' amount=' . number_format((float) $payment->amount, 0, ',', '.')
            . ' booking=' . ($payment->booking?->booking_code ?? '-'));

        $gatewaySaysPaid = ($status['status'] ?? '') === 'paid';

        if ($gatewaySaysPaid && $payment->status->value !== 'verified') {
            $this->newLine();
            $this->comment('BELUM SINKRON: gateway bilang lunas, payment lokal masih ' . $payment->status->value . '.');
            $this->line('  Tekan "Cek Status" di Daftar Pembayaran, atau buka halaman itu — backend');
            $this->line('  akan memverifikasi ulang dan melunaskannya (nominal harus sama).');
        } elseif ($gatewaySaysPaid) {
            $this->info('Sinkron: gateway lunas dan payment sudah verified.');
        } else {
            $this->info('Sinkron: gateway belum mencatat pembayaran dan payment memang belum verified.');
        }

        return self::SUCCESS;
    }
}
