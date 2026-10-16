<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Http\Request;
use Exception;

class GatewayPaymentController extends Controller
{
    public function __construct(protected PaymentGatewayService $gatewayService) {}

    /**
     * Buat permintaan pembayaran via gateway.
     * Channel 'auto' (default) = Midtrans menampilkan semua metode bayar yang aktif di
     * Merchant Account dan tamu memilih sendiri di overlay Snap. Kanal spesifik (qris/va/
     * ewallet) tetap tersedia bila kasir perlu memaksa satu metode.
     *
     * `type` (dp|final) dan `amount` menentukan BAGIAN mana yang ditagih: tanpa keduanya
     * sistem menagih seluruh sisa (perilaku lama). `amount` hanya memilih nominal, tidak pernah
     * membuat keputusan lunas - pelunasan tetap berasal dari jawaban gateway.
     */
    public function store(Request $request, Booking $booking)
    {
        $request->validate(
            [
                'channel' => ['required', 'in:auto,qris,va,ewallet'],
                'type'    => ['nullable', 'in:dp,final'],
                'amount'  => ['nullable', 'numeric', 'min:1'],
            ],
            [
                'channel.required' => 'Metode pembayaran gateway wajib dipilih.',
                'channel.in'       => 'Metode pembayaran gateway tidak dikenal (auto, qris, va, ewallet).',
                'type.in'          => 'Tipe pembayaran gateway hanya dp (uang muka) atau final (pelunasan).',
                'amount.numeric'   => 'Nominal pembayaran harus berupa angka.',
                'amount.min'       => 'Nominal pembayaran harus lebih dari Rp 0.',
            ]
        );

        try {
            $payment = $this->gatewayService->createGatewayPayment(
                $booking,
                $request->only(['channel', 'type', 'amount']),
                $request->user()->id
            );

            return response()->json([
                'message'        => 'Permintaan pembayaran berhasil dibuat.',
                'data'           => $payment,
                'payment_info'   => $payment->gateway_payload,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Gagal membuat permintaan pembayaran.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Tanya status transaksi ke gateway. Dipakai staf meja, halaman daftar, scheduler, dan
     * otomatis oleh browser: body `transaction_id` dipakai saat Snap JS / halaman hasil membawa
     * transaction_id Midtrans. Nilai itu hanya petunjuk id mana yang ditanyakan - keputusan tetap
     * dari jawaban provider (status lunas + nominal harus sama dengan tagihan).
     */
    public function sync(Request $request, Payment $payment)
    {
        $request->validate([
            'transaction_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_\-]*$/'],
        ]);

        $reference = $request->input('transaction_id');

        try {
            $result = $this->gatewayService->syncStatus($payment, $reference);

            $message = match ($result['status'] ?? 'pending') {
                'paid', 'settlement' => 'Pembayaran terkonfirmasi oleh gateway, status diperbarui.',
                'expired'            => 'Transaksi di gateway sudah kedaluwarsa.',
                'cancel', 'failure', 'failed' => 'Transaksi dibatalkan/gagal di sisi gateway.',
                'amount_mismatch'    => 'Gateway melaporkan nominal yang tidak sama dengan tagihan — periksa manual.',
                'skipped'            => 'Pembayaran sudah terverifikasi sebelumnya.',
                'ignored'            => $result['message'] ?? 'Tidak perlu sinkronisasi.',
                // Transaksi belum tercatat di gateway (Mis. tamu belum membuka halaman Snap):
                // jelaskan alasannya, jangan biarkan staf mengira sistemnya error.
                default              => ($result['gateway_found'] ?? true) === false
                    ? (string) ($result['gateway_note'] ?? 'Transaksi belum tercatat di gateway.')
                    : 'Belum ada konfirmasi pembayaran dari gateway.',
            };

            return response()->json([
                'message'         => $message,
                'data'            => $result['payment'] ?? $payment,
                'gateway_status'  => $result['status'] ?? 'pending',
                'gateway_found'   => (bool) ($result['gateway_found'] ?? true),
                'payment_status'  => $result['payment_status'] ?? null,
                'changed'         => (bool) ($result['changed'] ?? false),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Gagal mengecek status ke gateway.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Sinkronkan semua payment gateway yang masih pending.
     *
     * Dipakai saat halaman Pembayaran/Reservasi dibuka dan oleh scheduler. Penting di
     * lingkungan tanpa cron dan tanpa HTTPS tunnel (mis. dev Laragon): tanpa ini status
     * hanya berubah kalau ada orang menekan tombol Cek Status. Keputusan tetap datang dari
     * gateway, bukan dari pengakuan pemanggil.
     */
    public function syncAll()
    {
        // Urutan sama seperti scheduler: tanya status sebenarnya DULU, baru tandai expired.
        // QR yang uangnya ternyata masuk tidak boleh berubah jadi expired.
        $changed = $this->gatewayService->syncPendingPayments();
        $expired = $this->gatewayService->expireOverduePayments();

        return response()->json([
            'message' => $changed > 0
                ? "{$changed} pembayaran gateway diperbarui dari status sebenarnya."
                : 'Tidak ada pembayaran gateway yang berubah status.',
            'changed' => $changed,
            'expired' => $expired,
        ]);
    }
}
