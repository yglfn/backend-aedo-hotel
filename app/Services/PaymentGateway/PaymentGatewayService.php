<?php

namespace App\Services\PaymentGateway;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentGatewayLog;
use App\Models\Setting;
use App\Services\AuditService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Exception;

class PaymentGatewayService
{
    public function __construct(
        protected PaymentGatewayInterface $gateway,
        protected InvoiceService $invoiceService,
        protected PaymentService $paymentService
    ) {}

    /**
     * Buat permintaan pembayaran gateway (QRIS / VA / e-wallet).
     *
     * Bisa menagih SELURUH sisa (default, tipe `final`) atau SEBAGIAN saja (tipe `dp`) -
     * uang muka adalah kasus sehari-hari hotel: tamu bayar DP untuk mengunci kamar, sisanya
     * saat check-out. Tanpa opsi ini, label "DP" di layar reservasi hanya hiasan karena
     * Midtrans selalu ditagih penuh dan payment selalu tercatat `final`.
     *
     * @param array{channel?: string, type?: string, amount?: float|null} $data
     */
    public function createGatewayPayment(Booking $booking, array $data, int $userId): Payment
    {
        return DB::transaction(function () use ($booking, $data, $userId) {
            $invoice = $booking->invoice ?? $this->invoiceService->createForBooking($booking);

            if ($invoice->status->value === InvoiceStatus::Paid->value) {
                throw new Exception('Invoice sudah lunas.');
            }

            // Guard yang sama dengan pembayaran manual: selama ada transfer/QRIS manual menunggu
            // verifikasi, tagihan gateway baru ditolak. Uang yang sama jangan ditagih dua kali.
            $pendingManual = $booking->payments()
                ->where('status', PaymentStatus::Pending->value)
                ->where('method', '!=', PaymentMethod::PaymentGateway->value)
                ->first();

            if ($pendingManual) {
                throw new Exception(
                    'Masih ada pembayaran manual menunggu verifikasi admin ('
                    . ($pendingManual->reference_number ?: 'Payment #' . $pendingManual->id)
                    . '). Verifikasi atau tolak pembayaran itu sebelum menagih lewat gateway.'
                );
            }

            // Satu tagihan gateway aktif per invoice: tautan Snap/QR/VA yang masih pending dan
            // belum kedaluwarsa digantikan, supaya tidak ada dua tautan bayar hidup untuk tagihan
            // yang sama. Kalau settlement yang lama ternyata datang belakangan, sistem tetap
            // memverifikasinya lalu menandai perlu rekonsiliasi (lihat applyGatewayStatus).
            $superseded = $booking->payments()
                ->where('status', PaymentStatus::Pending->value)
                ->where('method', PaymentMethod::PaymentGateway->value)
                ->where('expires_at', '>', now())
                ->get();

            foreach ($superseded as $stale) {
                $stale->update([
                    'status' => PaymentStatus::Expired->value,
                    'notes'  => trim(((string) $stale->notes) . ' | Digantikan tagihan gateway baru'),
                ]);
            }

            $setting       = Setting::first();
            $expiryMinutes = $setting?->payment_expiry_minutes ?? 60;
            // 'auto' = tidak memilih metode di sistem; tamu memilih di overlay Midtrans
            $channel       = $data['channel'] ?? 'auto';

            // Sisa memakai rumus model (verified + pending, tipe dp/final) - bukan verified saja,
            // agar DP sebagian tidak menagih ulang uang yang sudah berkomitmen masuk.
            $summary   = $booking->fresh();
            $remaining = round((float) $summary->remaining_due, 2);

            if ($remaining <= 0) {
                throw new Exception('Tidak ada sisa tagihan yang perlu dibayar.');
            }

            $type    = (string) ($data['type'] ?? PaymentType::Final->value);
            $claimed = $data['amount'] ?? null;
            $claimed = ($claimed === null || $claimed === '') ? null : round((float) $claimed, 2);

            if ($claimed !== null) {
                // Nominal dari pengguna tidak pernah dinaikkan/diturunkan diam-diam: kalau lebih
                // besar dari sisa, minta angkanya diperbaiki daripada tamu ditagih lebih.
                if ($claimed <= 0) {
                    throw new Exception('Nominal pembayaran harus lebih dari Rp 0.');
                }

                if ($claimed > $remaining) {
                    throw new Exception(
                        'Nominal melebihi sisa tagihan Rp ' . number_format($remaining, 0, ',', '.') . '.'
                    );
                }

                $amount = $claimed;
            } elseif ($type === PaymentType::Dp->value) {
                // DP tanpa nominal = ambang minimum hotel, dibatasi sisa tagihan. Tanpa ambang,
                // sistem tidak tahu berapa besar DP yang boleh ditagih.
                $dpMin = round((float) $summary->dp_min_amount, 2);

                if ($dpMin <= 0) {
                    throw new Exception(
                        'DP minimum belum diatur. Isi "Minimal DP (%)" di Pengaturan atau tentukan nominal DP sendiri.'
                    );
                }

                $amount = min($dpMin, $remaining);
            } else {
                $amount = $remaining;
            }

            // Partial = DP, pelunasan = final. Tipe dikirim eksplisit supaya label di laporan,
            // daftar pembayaran, dan ambang konfirmasi memakai satu arti yang sama.
            $type = $amount >= $remaining ? PaymentType::Final->value : $type;

            // Panggil gateway untuk buat transaksi
            $gatewayResult = $this->gateway->createTransaction([
                'amount'          => $amount,
                'channel'         => $channel,
                'expiry_minutes'  => $expiryMinutes,
                'booking_code'    => $booking->booking_code,
                'customer_name'   => $booking->customer->name,
            ]);

            $payment = Payment::create([
                'booking_id'             => $booking->id,
                'invoice_id'             => $invoice->id,
                'created_by'             => $userId,
                'method'                 => 'payment_gateway',
                'type'                   => $type,
                'status'                 => PaymentStatus::Pending->value,
                'amount'                 => $amount,
                'provider'               => $this->gateway->getProviderName(),
                'gateway_transaction_id' => $gatewayResult['transaction_id'],
                'channel'                => $channel,
                'gateway_payload'        => $gatewayResult,
                'expires_at'             => now()->addMinutes($expiryMinutes),
            ]);

            return $payment;
        });
    }

    /**
     * Proses webhook dari gateway — idempotent & selalu log.
     */
    public function handleWebhook(string $rawPayload, array $headers): array
    {
        $signatureValid = $this->gateway->verifySignature($rawPayload, $headers);

        $decoded    = $this->decodePayload($rawPayload);
        $normalized = $this->gateway->normalizeNotification($decoded);

        // transaction_id versi kita: Midtrans mengirim order_id, simulator mengirim transaction_id
        $gatewayTransactionId = $normalized['transaction_id'];
        $eventType            = $normalized['event'];

        // Selalu catat ke log terlebih dahulu
        $log = PaymentGatewayLog::create([
            'provider'        => $this->gateway->getProviderName(),
            'event_type'      => $eventType,
            'payload'         => $rawPayload,
            'headers'         => $this->sanitizeHeaders($headers),
            'signature_valid' => $signatureValid,
            'processed'       => false,
        ]);

        // Tolak jika signature tidak valid. payment_id tetap ditautkan (bila transaction_id
        // dikenal) supaya percobaan pemalsuan bisa dilacak saat audit.
        if (!$signatureValid) {
            $log->update([
                'error_message' => 'Signature tidak valid.',
                'payment_id'    => $gatewayTransactionId
                    ? Payment::where('gateway_transaction_id', $gatewayTransactionId)->value('id')
                    : null,
            ]);

            return ['status' => 'rejected', 'message' => 'Invalid signature.'];
        }

        // Idempoten: cek apakah sudah pernah diproses
        $payment = Payment::where('gateway_transaction_id', $gatewayTransactionId)->first();

        if (!$payment) {
            $log->update(['error_message' => 'Payment dengan transaction_id tidak ditemukan.']);
            return ['status' => 'ignored', 'message' => 'Transaction not found.'];
        }

        // Update log dengan payment_id
        $log->update(['payment_id' => $payment->id]);

        // Notifikasi provider membawa transaction_id mereka sendiri - ingat sekaligus, supaya
        // lookup berikutnya ikut mengenali transaksi e-wallet yang order_id-nya tidak dikenal API.
        $this->rememberReference($payment, (string) $gatewayTransactionId, $normalized);

        $gatewayStatus = $normalized['status'];

        // NOMINAL WAJIB COCOK. Signature sah saja tidak cukup: tanpa cek ini, satu
        // settlement Rp 1 bisa melunasi tagihan ratusan ribu (dana palsu / payload bekas).
        if ($gatewayStatus === 'paid') {
            $settled = round((float) ($normalized['amount'] ?? 0), 2);
            $claimed = round((float) $payment->amount, 2);

            if ($settled <= 0 || abs($settled - $claimed) > 0.01) {
                $log->update([
                    'error_message' => "Nominal settlement ({$settled}) tidak sama dengan tagihan ({$claimed}).",
                ]);

                return ['status' => 'amount_mismatch', 'message' => 'Amount mismatch.'];
            }
        }

        // Jika sudah diproses sebelumnya (idempoten)
        if ($payment->status->value === PaymentStatus::Verified->value) {
            $log->update(['processed' => true, 'error_message' => 'Sudah diproses sebelumnya (idempoten).']);
            return ['status' => 'skipped', 'message' => 'Already processed.'];
        }

        // Proses berdasarkan event
        try {
            DB::transaction(function () use ($payment, $gatewayStatus, $log) {
                $this->applyGatewayStatus($payment, $gatewayStatus);

                $log->update(['processed' => true]);
            });

            return ['status' => 'processed', 'message' => 'Webhook processed successfully.'];
        } catch (Exception $e) {
            $log->update(['error_message' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Body notifikasi selalu JSON dari Midtrans/simulator, tapi sebagian provider ada yang
     * mengirim form-urlencoded — keduanya diterima, isinya tidak dipercaya sebelum signature lolos.
     */
    protected function decodePayload(string $rawPayload): array
    {
        $decoded = json_decode($rawPayload, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $form = [];
        parse_str($rawPayload, $form);

        return is_array($form) ? $form : [];
    }

    /**
     * Id yang layak ditanyakan ke gateway, berurutan:
     *  1. petunjuk baru (dari callback Snap JS / URL redirect provider),
     *  2. order_id kita (kunci utama - tetap utuh agar notifikasi selalu mendarat benar),
     *  3. transaction_id provider yang pernah kita ingat sebelumnya,
     *  4. token Snap dari saat transaksi dibuat.
     *
     * Kandidat ke-4 inilah yang menyelamatkan e-wallet: Midtrans tidak mencatat transaksi
     * DANA/GoPay hasil Snap di bawah order_id kita, tapi jelas mengenalinya lewat token.
     *
     * @return array<int, string>
     */
    protected function statusReferences(Payment $payment, ?string $reference = null): array
    {
        $references = [];

        $candidates = [
            $reference,
            (string) $payment->gateway_transaction_id,
            $this->providerReference($payment),
            $this->snapToken($payment),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '' && !in_array($candidate, $references, true)) {
                $references[] = $candidate;
            }
        }

        return $references;
    }

    /** transaction_id milik provider yang sudah dikenal untuk payment ini (null bila belum). */
    public function providerReference(Payment $payment): ?string
    {
        $value = trim((string) ($this->payloadOf($payment)['provider_transaction_id'] ?? ''));

        return $value !== '' ? $value : null;
    }

    /** token Snap yang dikembalikan saat transaksi dibuat — id yang dikenali endpoint Snap. */
    protected function snapToken(Payment $payment): ?string
    {
        $value = trim((string) ($this->payloadOf($payment)['token'] ?? ''));

        return $value !== '' ? $value : null;
    }

    /** gateway_payload selalu array (cast JSON bisa null pada baris lama). */
    protected function payloadOf(Payment $payment): array
    {
        return is_array($payment->gateway_payload) ? $payment->gateway_payload : [];
    }

    /**
     * Ingat id provider yang terbukti dikenali. order_id kita TIDAK pernah ditimpa:
     * notifikasi Midtrans berkunci pada order_id, jadi kunci itu harus tetap bisa cocok.
     */
    protected function rememberReference(Payment $payment, string $usedRef, array $info = []): void
    {
        // provider_transaction_id lebih dipercaya daripada id yang kita pakai bertanya
        $learned = trim((string) ($info['provider_transaction_id'] ?? $usedRef));

        if ($learned === '' || $learned === (string) $payment->gateway_transaction_id) {
            return;
        }

        $payload = $this->payloadOf($payment);

        if (($payload['provider_transaction_id'] ?? null) === $learned) {
            return;
        }

        $payload['provider_transaction_id'] = $learned;
        $payment->update(['gateway_payload' => $payload]);
    }

    /**
     * Tanya status transaksi ke gateway (self-healing untuk webhook yang hilang).
     * Verifikasi tetap berasal dari provider, bukan dari pengakuan pengguna sistem.
     *
     * `$reference` ialah id transaksi yang BARU saja diberitahukan provider/browser (mis.
     * transaction_id Midtrans yang ikut di payload Snap JS atau di URL redirect halaman hasil).
     * Nilainya hanya PETUNJUK id mana yang perlu ditanyakan - kepastian tetap dari jawaban
     * gateway dan pencocokan nominal di bawah. Id yang terbukti dikenali langsung diingat, jadi
     * sync berikutnya sudah benar sendiri tanpa ada yang mengetik apa pun.
     */
    public function syncStatus(Payment $payment, ?string $reference = null): array
    {
        $method = $payment->method->value ?? $payment->method;

        if ($method !== PaymentMethod::PaymentGateway->value) {
            return [
                'status'         => 'ignored',
                'message'        => 'Pembayaran ini bukan transaksi gateway.',
                'payment_status' => $payment->status->value,
                'payment'        => $payment,
            ];
        }

        if ($payment->status === PaymentStatus::Verified) {
            return [
                'status'         => 'skipped',
                'message'        => 'Pembayaran sudah terverifikasi.',
                'payment_status' => PaymentStatus::Verified->value,
                'payment'        => $payment,
            ];
        }

        // Sebagian metode (Midtrans e-wallet hasil Snap) tidak dikenali API lewat order_id kita,
        // tapi dikenali lewat transaction_id / token Snap mereka. Maka kandidat id dicoba satu
        // per satu sampai ada yang dikenali - otomatis, tanpa tombol "tautkan".
        $references  = $this->statusReferences($payment, $reference);
        $result      = ['status' => 'pending', 'found' => false];
        $usedRef     = (string) ($references[0] ?? '');
        $probedRefs  = [];
        $bound       = false;
        $foreign     = null;

        foreach ($references as $candidate) {
            $result     = $this->gateway->getTransactionStatus($candidate);
            $usedRef    = $candidate;
            $probedRefs[] = $candidate;

            if (($result['found'] ?? false) !== true) {
                continue;
            }

            // Jawaban gateway harus memang transaksi payment ini. Tanya lewat order_id ->
            // order_id yang kembali adalah punya kita; tanya lewat token Snap -> Midtrans juga
            // mengembalikan order_id kita; tanya lewat id provider -> yang kembali id itu
            // sendiri. Selain itu adalah transaksi orang lain (token salah tempel / payload
            // bekas) dan tidak boleh menyentuh status apa pun.
            $echoed = trim((string) ($result['order_id'] ?? ''));

            if ($echoed !== '' && !in_array($echoed, [(string) $payment->gateway_transaction_id, $candidate], true)) {
                $foreign = $echoed;
                continue;
            }

            $bound = true;
            break;
        }

        if ($foreign !== null) {
            $result = [
                'status' => 'pending',
                'found'  => false,
                'note'   => 'Jawaban gateway adalah transaksi lain (order_id ' . $foreign . ') - payment tidak diubah.',
            ];
        }

        if ($bound) {
            $this->rememberReference($payment, $usedRef, $result);
        }

        $gatewayStatus = (string) ($result['status'] ?? 'pending');

        $log = PaymentGatewayLog::create([
            'payment_id'      => $payment->id,
            'provider'        => $this->gateway->getProviderName(),
            'event_type'      => 'payment.status_sync',
            'payload'         => json_encode([
                'reference'   => $usedRef,
                'probed'      => $probedRefs,
            ] + $result),
            // Bukan tanda tangan: status ini kita jemput sendiri ke gateway memakai Server Key
            // lewat TLS, jadi sumbernya sudah terautentikasi (Midtrans tidak menandatangani
            // respons /v2/{order_id}/status).
            'signature_valid' => true,
            'processed'       => false,
        ]);

        // Aturan nominal yang sama dengan jalur webhook: jangan pernah menandai lunas
        // kalau angka yang dilaporkan gateway tidak sama dengan tagihan payment ini.
        // Sebagian provider tidak mengirim gross_amount di endpoint status -> dalam hal
        // ini kami tidak menebak; settlement tetap harus lewat notifikasi ternormalisasi.
        if ($gatewayStatus === 'paid' && array_key_exists('amount', $result) && $result['amount'] !== null) {
            $settled = round((float) $result['amount'], 2);
            $claimed = round((float) $payment->amount, 2);

            if ($settled <= 0 || abs($settled - $claimed) > 0.01) {
                $log->update([
                    'error_message' => "Nominal hasil sync ({$settled}) tidak sama dengan tagihan ({$claimed}).",
                ]);

                return [
                    'status'         => 'amount_mismatch',
                    'message'        => 'Nominal gateway tidak cocok dengan tagihan.',
                    'payment_status' => $payment->status->value,
                    'payment'        => $payment,
                ];
            }
        }

        $before = $payment->status->value;

        $this->applyGatewayStatus($payment, $gatewayStatus);

        $log->update(['processed' => true]);

        return [
            'status'         => $gatewayStatus,
            'payment_status' => $payment->fresh()->status->value,
            'changed'        => $before !== $payment->fresh()->status->value,
            // found=false berarti gateway belum mengenal transaksi ini (mis. halaman bayar
            // Snap belum dibuka) — tampilkan alasannya, jangan buat admin menduga sistem error
            'gateway_found'  => (bool) $bound,
            'gateway_note'   => $result['note'] ?? null,
            'payment'        => $payment->fresh(),
        ];
    }

    /**
     * Diagnostik read-only: tanyakan semua kandidat id payment ini ke gateway, tanpa menulis
     * apa pun. Dipakai perintah artisan saat status di aplikasi dan di dashboard provider
     * kelihatan berbeda - jawaban mana yang cocok, dan lewat id apa, terlihat dalam satu baris.
     *
     * @return array{found: bool, reference: string|null, answer: array, probed: array}
     */
    public function inspectStatus(Payment $payment): array
    {
        $answers = [];

        foreach ($this->statusReferences($payment) as $candidate) {
            $answer    = ['reference' => $candidate] + $this->gateway->getTransactionStatus($candidate);
            $answers[] = $answer;

            if (($answer['found'] ?? false) === true) {
                return ['found' => true, 'reference' => $candidate, 'answer' => $answer, 'probed' => $answers];
            }
        }

        return [
            'found'     => false,
            'reference' => null,
            'answer'    => $answers ? end($answers) : [],
            'probed'    => $answers,
        ];
    }

    /**
     * Sinkronkan semua payment gateway yang masih pending (dipakai scheduler),
     * termasuk yang sudah lewat expires_at dalam 24 jam terakhir: kalau uangnya
     * ternyata masuk, jangan sampai ditandai expired.
     */
    public function syncPendingPayments(): int
    {
        $payments = Payment::where('method', PaymentMethod::PaymentGateway->value)
            ->where('status', PaymentStatus::Pending->value)
            ->whereNotNull('gateway_transaction_id')
            ->where('created_at', '>=', now()->subDay())
            ->get();

        $changed = 0;

        foreach ($payments as $payment) {
            if (($this->syncStatus($payment)['changed'] ?? false) === true) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Satu tempat untuk memindahkan status provider -> status payment + efek turunannya.
     * Status sudah dalam bahasa internal: paid | pending | expired | failed.
     * Idempoten: pembayaran yang sudah verified tidak diturunkan/diulang.
     */
    protected function applyGatewayStatus(Payment $payment, string $gatewayStatus): void
    {
        // Hanya transaksi gateway; payment manual tidak boleh berubah lewat jalur ini
        if (($payment->method->value ?? $payment->method) !== PaymentMethod::PaymentGateway->value) {
            return;
        }

        if ($payment->status === PaymentStatus::Verified) {
            return;
        }

        $previousStatus = $payment->status->value;

        if ($gatewayStatus === 'paid') {
            $update = [
                'status'  => PaymentStatus::Verified->value,
                'paid_at' => now(),
            ];

            // Uang benar-benar masuk tapi QR-nya sudah kedaluwarsa: catat agar bisa
            // direkonsiliasi admin (jangan sampai ada dana mengendap tanpa jejak).
            if (in_array($previousStatus, [PaymentStatus::Expired->value, PaymentStatus::Failed->value], true)) {
                $statusLabel = $previousStatus === PaymentStatus::Expired->value ? 'kedaluwarsa' : 'gagal/ditolak';

                $update['notes'] = trim(((string) $payment->notes) . " | Settlement datang setelah transaksi {$statusLabel} — perlu rekonsiliasi admin");
            }

            $payment->update($update);

            AuditService::log('payment.gateway_verified', $payment, ['status' => $previousStatus], [
                'status'     => PaymentStatus::Verified->value,
                'amount'     => (float) $payment->amount,
                'provider'   => $this->gateway->getProviderName(),
                'booking_id' => $payment->booking_id,
            ]);

            $booking = $payment->booking->fresh();
            $invoice = $payment->invoice->fresh();

            if ($booking && $invoice) {
                // Satu rumus untuk lunas & konfirmasi (dipakai juga oleh pembayaran manual)
                $this->paymentService->checkAndMarkInvoicePaid($booking, $invoice);
                $this->paymentService->applyAutoConfirmation($booking, $invoice);
            }

            return;
        }

        if ($gatewayStatus === 'expired') {
            $payment->update(['status' => PaymentStatus::Expired->value]);

            return;
        }

        if ($gatewayStatus === 'failed') {
            $payment->update(['status' => PaymentStatus::Failed->value]);
        }
    }

    /**
     * Sinkronkan pembayaran gateway milik SATU reservasi, tepat sebelum keputusan
     * operasional (check-in / check-out) diambil.
     *
     * Kenapa perlu: webhook bisa tidak masuk (apalagi di lokal tanpa HTTPS publik) dan
     * scheduler bisa tidak jalan (tanpa cron). Tanpa jalur ini, kasir melihat "belum lunas"
     * padahal uangnya sudah ada di gateway, dan check-out tertahan tanpa sebab.
     *
     * Kalau gateway tidak bisa dihubungi, operasi tetap berjalan dan kesalahan dicatat:
     * meja depan tidak boleh terkunci oleh masalah jaringan.
     *
     * @return array{checked: int, changed: int, unavailable: bool}
     */
    public function reconcileBooking(Booking $booking): array
    {
        $payments = Payment::where('booking_id', $booking->id)
            ->where('method', PaymentMethod::PaymentGateway->value)
            // Expired ikut dicek: settlement bisa datang setelah batas waktu lokal
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Expired->value])
            ->whereNotNull('gateway_transaction_id')
            ->get();

        $checked     = 0;
        $changed     = 0;
        $unavailable = false;

        foreach ($payments as $payment) {
            try {
                $result = $this->syncStatus($payment);
                $checked++;

                if (($result['changed'] ?? false) === true) {
                    $changed++;
                }
            } catch (\Throwable $e) {
                $unavailable = true;
                report($e);
            }
        }

        return ['checked' => $checked, 'changed' => $changed, 'unavailable' => $unavailable];
    }

    /**
     * Tandai payment gateway yang melewati expires_at menjadi expired.
     * Dipanggil oleh scheduler.
     */
    public function expireOverduePayments(): int
    {
        return Payment::where('method', 'payment_gateway')
            ->where('status', PaymentStatus::Pending->value)
            ->where('expires_at', '<', now())
            ->update(['status' => PaymentStatus::Expired->value]);
    }

    /**
     * Hapus header sensitif sebelum disimpan ke log.
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sensitive = ['authorization', 'cookie', 'x-api-key', 'x-secret'];
        return array_filter($headers, function ($key) use ($sensitive) {
            return !in_array(strtolower($key), $sensitive);
        }, ARRAY_FILTER_USE_KEY);
    }
}
