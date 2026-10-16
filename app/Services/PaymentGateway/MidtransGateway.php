<?php

namespace App\Services\PaymentGateway;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * MidtransGateway — integrasi Snap API Midtrans (sandbox atau production).
 *
 * Spesifikasi yang diikuti (docs.midtrans.com) DAN diverifikasi langsung ke server Midtrans:
 *  - Buat transaksi : POST {snapBase}/snap/v1/transactions  (Basic auth = server key),
 *    body: transaction_details.order_id (unik, maks 50 char) + gross_amount (integer IDR)
 *  - Cek status     : GET {apiBase}/v2/{order_id}/status
 *                     GET {snapBase}/snap/v1/transactions/{token}/status
 *    Midtrans memisahkan host: /snap/* hidup di app(.sandbox), /v2/* hidup di api(.sandbox).
 *    Memanggil /v2 di host Snap dijawab HTTP 404 (endpoint tidak ada di sana).
 *  - Notifikasi     : body JSON berisi order_id, status_code, gross_amount,
 *    transaction_status, signature_key = SHA512(order_id + status_code + gross_amount + server key)
 *
 * Perilaku Midtrans yang sudah diobservasi langsung (bukan asumsi):
 *  - order id yang tidak dikenal -> HTTP 200 + body {"status_code":"404","status_message":
 *    "Transaction doesn't exist."}. Transaksi Snap baru masuk ledger setelah tamu membuka
 *    halaman bayarnya, jadi "belum ditemukan" adalah kondisi normal, bukan kegagalan.
 *  - e-wallet hasil Snap (DANA/GoPay/Ovo) TIDAK bisa dicari lewat order_id kita di Core API;
 *    jawabannya tetap "Transaction doesn't exist" walau statusnya settlement. Lookup lewat
 *    token Snap mengenalinya dan mengembalikan order_id kita — lihat getTransactionStatus().
 *  - kredensial salah di environment ini -> HTTP 401/403.
 *
 * Keamanan (best practice resmi Midtrans yang diterapkan di sini):
 *  - signature_key SELALU dicocokkan dengan hash hitungan kita (hash_equals) sebelum
 *    isinya dipercaya; tanpa itu siapa pun bisa mengirim "settlement" palsu
 *  - dianggap LUNAS hanya kalau transaction_status settlement/capture DAN status_code 200/201
 *    (endpoint status memakai kode respons pembuatan transaksi) DAN fraud_status accept
 *  - kecocokan nominal, idempotensi, dan penulisan log tetap dilakukan PaymentGatewayService;
 *    jawaban lookup juga selalu membawa order_id milik transaksi yang ditemukan, agar service
 *    bisa memastikan transaksi itu memang milik payment yang sedang dicek
 *  - environment (sandbox/production) ditentukan dari konfigurasi, tidak bisa dipilih
 *    oleh pemanggil API; salah lingkungan ketahuan lewat testConnection(), bukan tebakan prefiks kunci
 */
class MidtransGateway implements PaymentGatewayInterface
{
    // Host Snap = halaman/redirect pembayaran. Host Core API = lookup status & notifikasi.
    private const SNAP_SANDBOX    = 'https://app.sandbox.midtrans.com';
    private const SNAP_PRODUCTION = 'https://app.midtrans.com';
    private const API_SANDBOX     = 'https://api.sandbox.midtrans.com';
    private const API_PRODUCTION  = 'https://api.midtrans.com';

    /**
     * Channel internal sistem => [nama produk Midtrans, enabled_payments Snap, aksi VA].
     *
     * enabled_payments = null artinya JANGAN membatasi metode: Snap menampilkan semua kanal
     * yang aktif di Merchant Account (QRIS, VA, e-wallet, kartu kredit) dan tamu memilih
     * sendiri di overlay. Ini channel default ('auto') sesuai kebutuhan kasir.
     */
    private const CHANNELS = [
        'auto'    => ['snap', null, null],
        'qris'    => ['qris', ['qris'], null],
        'va'      => ['bca_va', ['bca_va'], 'BILLING'],
        'ewallet' => ['dana', ['dana'], null],
    ];

    private string $serverKey;

    private string $signatureKey;

    /** Client key itu PUBLIC (dipakai Snap.js di browser). Server key tidak pernah dikirim keluar. */
    private string $clientKey;

    private bool $isProduction;

    public function __construct()
    {
        $this->serverKey    = trim((string) config('services.midtrans.server_key', ''));
        $this->clientKey    = trim((string) config('services.midtrans.client_key', ''));
        $this->isProduction = (bool) config('services.midtrans.is_production', false);

        // Midtrans menghitung signature dengan SERVER key; kolom signature_key hanya untuk
        // merchant yang memakai verification key terpisah di MAP settings.
        $this->signatureKey = trim((string) config('services.midtrans.signature_key', '')) ?: $this->serverKey;

        if ($this->serverKey === '' || $this->signatureKey === '') {
            throw new RuntimeException(
                'PG_MIDTRANS_SERVER_KEY belum diisi. Ambil Server Key dari Midtrans Merchant Account '
                . '(Development/Sandbox Keys) lalu isi .env — provider tidak boleh aktif tanpa kredensial.'
            );
        }
    }

    /**
     * Uji koneksi nyata ke Midtrans dengan environment yang terkonfigurasi.
     *
     * Prefiks kunci TIDAK dipakai sebagai penanda environment: Midtrans punya lebih dari satu
     * gaya penamaan (SB-Mid-server-... dan Mid-server-...) sehingga menebak dari string bisa
     * mengunci merchant yang justru benar. Yang diuji adalah jawaban servernya.
     *
     * Probe memakai order id fiktif di host Core API. Hasil yang diamati langsung:
     *  - HTTP 200 (body status_code 404 "Transaction doesn't exist") -> kredensial DITERIMA => OK
     *  - HTTP 401/403 -> server key salah atau environment salah (kunci production ke sandbox)
     *  - HTTP 404     -> endpoint lookup tidak ada di host ini (base URL/host yang salah)
     */
    public function testConnection(): array
    {
        $probeUrl = $this->apiBase() . '/v2/AEDO-CONNECTION-PROBE/status';

        try {
            $response = $this->client()->get($probeUrl);
        } catch (\Throwable $e) {
            return [
                'ok'         => false,
                'provider'   => $this->getProviderName(),
                'base_url'   => $this->apiBase(),
                'snap_url'   => $this->snapBase(),
                'status'     => null,
                'environment' => $this->isProduction ? 'production' : 'sandbox',
                'message'    => 'Midtrans tidak dapat dijangkau: ' . $e->getMessage(),
            ];
        }

        $status = $response->status();
        $body   = $response->json() ?? [];

        // HTTP 200 + status_code 404 di body adalah cara Midtrans bilang "order id ini tidak
        // ada" — artinya autentikasi kita lolos. Itu succeeding probe, bukan kegagalan.
        $accepted = $status === 200;

        return [
            'ok'          => $accepted,
            'provider'    => $this->getProviderName(),
            'base_url'    => $this->apiBase(),
            'snap_url'    => $this->snapBase(),
            'status'      => $status,
            'environment' => $this->isProduction ? 'production' : 'sandbox',
            'message'     => match (true) {
                $accepted => 'Kredensial Midtrans diterima di environment '
                    . ($this->isProduction ? 'production' : 'sandbox') . ' (' . $this->apiBase() . ').',

                $status === 401 || $status === 403 => 'Midtrans menolak kredensial (' . $status . ') di environment '
                    . ($this->isProduction ? 'production' : 'sandbox') . '. Cek PG_MIDTRANS_SERVER_KEY dan '
                    . 'PG_MIDTRANS_IS_PRODUCTION — kunci sandbox tidak berlaku di production, dan sebaliknya.',

                $status === 404 => 'Endpoint lookup tidak tersedia di ' . $this->apiBase() . ' (HTTP 404). '
                    . 'Kemungkinan besar host/URL Core API Midtrans berubah; periksa PG_MIDTRANS_* / konfigurasi base URL.',

                default => 'Jawaban Midtrans tidak dikenal (HTTP ' . $status . '): '
                    . substr((string) json_encode($body), 0, 200),
            },
        ];
    }

    public function getProviderName(): string
    {
        return 'midtrans';
    }

    public function createTransaction(array $params): array
    {
        // Default 'auto': biarkan Midtrans menampilkan semua metode bayar yang aktif di
        // Merchant Account; tamu memilih sendiri di overlay Snap (QRIS / VA / e-wallet / kartu).
        $channel = (string) ($params['channel'] ?? 'auto');
        [$product, $enabled, $vaAction] = self::CHANNELS[$channel] ?? self::CHANNELS['auto'];

        // order_id adalah kunci transaksi kita: unik per permintaan, karakter dibatasi alnum - _ ~ .
        $orderId = 'AEDO-' . strtoupper(Str::random(16));
        $amount  = (int) round((float) $params['amount']);

        if ($amount < 1) {
            throw new RuntimeException('Nominal pembayaran tidak valid untuk Midtrans.');
        }

        $payload = [
            'transaction_details' => [
                'order_id'     => $orderId,
                'gross_amount' => $amount,
            ],
            // (object) wajib: array kosong dijson_encode jadi [] (array), dan Midtrans
            // menolaknya dengan pesan "Metadata must be a json" yang menyesatkan.
            'customer_details' => (object) array_filter([
                'first_name' => (string) ($params['customer_name'] ?? 'Tamu Aedo Hotel'),
            ]),
            'metadata' => (object) array_filter([
                'booking_code' => (string) ($params['booking_code'] ?? ''),
            ]),
        ];

        // Field enabled_payments hanya dikirim kalau sistem perlu memaksa satu kanal.
        // Tidak mengirimnya = Midtrans memakai daftar kanal yang aktif di akun merchant.
        if ($enabled !== null) {
            $payload['enabled_payments'] = $enabled;
        }

        if ($vaAction !== null) {
            $payload[$product] = ['action' => $vaAction];
        }

        // callbacks.finish meng-override "Finish Redirect URL" di dashboard Midtrans, yang
        // default-nya https://example.com — tanpa ini tamu dibuang ke domain contoh setelah
        // selesai bayar. Isi URL TIDAK pernah dipercaya: halaman hasilnya tetap bertanya ke
        // backend, dan backend bertanya ke Midtrans.
        $finishUrl = $this->finishUrl($orderId);

        if ($finishUrl !== null) {
            $payload['callbacks'] = (object) ['finish' => $finishUrl];
        }

        $response = $this->client()->post($this->snapBase() . '/snap/v1/transactions', $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'Midtrans menolak permintaan transaksi (' . $response->status() . '): '
                . (string) ($response->json('error_code') ?: $response->json('message') ?: $response->body())
            );
        }

        // Snap QRIS/VA/e-wallet dibayar lewat halaman redirect_url Midtrans.
        // QR string langsung untuk ditampilkan di layar kasir memakai BI-SNAP /v1/qris
        // (butuh aktivasi produk QRIS di Merchant Account) — lihat dokumentasi proyek.
        $redirectUrl = (string) $response->json('redirect_url', '');

        return [
            'transaction_id' => $orderId,
            'order_id'       => $orderId,
            'channel'        => $product,
            'amount'         => $amount,
            'currency'       => 'IDR',
            'status'         => 'pending',
            'token'          => (string) $response->json('token', ''),
            'redirect_url'   => $redirectUrl,
            'qr_string'      => $redirectUrl,
            // Snap.js dibuka di browser supaya halaman bayar muncul otomatis (overlay di
            // dalam halaman, jadi tidak terkena popup blocker). Kedua field ini aman untuk
            // browser: client key memang dirancang publik, server key tidak pernah ikut.
            'snap_js_url'    => $this->snapBase() . '/snap/snap.js',
            'client_key'     => $this->clientKey,
            'environment'    => $this->isProduction ? 'production' : 'sandbox',
            'provider'       => $this->getProviderName(),
        ];
    }

    /**
     * Signature Midtrans datang DI BODY (field signature_key), bukan di header.
     */
    public function verifySignature(string $payload, array $headers): bool
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            $form = [];
            parse_str($payload, $form);
            $decoded = $form;
        }

        if (!is_array($decoded) || ($decoded['order_id'] ?? null) === null) {
            return false;
        }

        $received = (string) ($decoded['signature_key'] ?? '');

        if ($received === '') {
            return false;
        }

        $expected = hash(
            'sha512',
            (string) $decoded['order_id']
            . (string) ($decoded['status_code'] ?? '')
            . (string) ($decoded['gross_amount'] ?? '')
            . $this->signatureKey
        );

        return hash_equals($expected, $received);
    }

    /**
     * Tanya status ke Midtrans.
     *
     * Midtrans menjawab dari DUA tempat, dan tidak semua kanal tercatat di keduanya
     * (diperoleh dari probe langsung ke merchant account proyek ini):
     *  - Core API  GET {apiBase}/v2/{id}/status                          -> mengenal order_id
     *    kita untuk QRIS / VA / kartu.
     *  - Snap API  GET {snapBase}/snap/v1/transactions/{token}/status    -> mengenal TOKEN
     *    transaksi.
     *
     * E-wallet hasil Snap (DANA/GoPay/Ovo) masuk ke ledger Core dengan order_id MILIK Midtrans
     * (A12026...), jadi /v2/{order_id_kita}/status dijawab "Transaction doesn't exist"
     * walaupun transaksinya sudah settlement. Itulah sebabnya DANA terlihat "menunggu
     * konfirmasi" selamanya padahal virtual account langsung lunas: token adalah satu-satunya
     * id yang kita punya dan yang mereka kenali untuk kanal ini.
     */
    public function getTransactionStatus(string $gatewayTransactionId): array
    {
        // Token Snap selalu UUID; id lain tidak layak ditanyakan ke endpoint token.
        $endpoints = self::looksLikeSnapToken($gatewayTransactionId) ? ['snap', 'core'] : ['core'];

        $missing = [
            'transaction_id' => $gatewayTransactionId,
            'status'         => 'pending',
            'found'          => false,
            'provider'       => $this->getProviderName(),
            'note'           => 'Status tidak berhasil ditanyakan ke Midtrans.',
        ];

        foreach ($endpoints as $endpoint) {
            $result = $endpoint === 'snap'
                ? $this->parseStatusResponse(
                    $this->client()->get($this->snapBase() . '/snap/v1/transactions/' . rawurlencode($gatewayTransactionId) . '/status'),
                    $gatewayTransactionId,
                    $endpoint
                )
                : $this->parseStatusResponse(
                    $this->client()->get($this->apiBase() . '/v2/' . rawurlencode($gatewayTransactionId) . '/status'),
                    $gatewayTransactionId,
                    $endpoint
                );

            if (($result['found'] ?? false) === true) {
                return $result;
            }

            $missing = $result;
        }

        return $missing;
    }

    /**
     * Satu parser untuk kedua endpoint status: bentuk jawabannya sama (transaction_status,
     * status_code, gross_amount, order_id, transaction_id, fraud_status).
     */
    private function parseStatusResponse(\Illuminate\Http\Client\Response $response, string $reference, string $endpoint): array
    {
        $body = $response->json() ?? [];

        if ($response->failed()) {
            // HTTP 404 {"error_messages":["token not found"]} untuk token asing, atau kegagalan
            // server. Keduanya BUKAN pembayaran; jangan ubah status apa pun.
            return [
                'transaction_id' => $reference,
                'status'         => 'pending',
                'found'          => false,
                'endpoint'       => $endpoint,
                'provider'       => $this->getProviderName(),
                'lookup_status'  => $response->status(),
                'note'           => 'Midtrans menjawab HTTP ' . $response->status() . '; status payment tidak diubah.',
            ];
        }

        // Perilaku Midtrans yang ditemukan saat pengujian langsung: order id yang belum ada di
        // ledger dijawab HTTP 200 dengan body status_code 404 ("Transaction doesn't exist").
        // Transaksi Snap baru tercatat setelah tamu membuka halaman bayarnya, jadi ini kondisi
        // normal — dan jelas bukan pembayaran.
        if (!isset($body['transaction_status'])) {
            return [
                'transaction_id' => $reference,
                'status'         => 'pending',
                'found'          => false,
                'endpoint'       => $endpoint,
                'provider'       => $this->getProviderName(),
                'lookup_status'  => $response->status(),
                'note'           => 'Belum tercatat di Midtrans - kemungkinan halaman bayar belum dibuka.',
            ];
        }

        $normalized = $this->normalizeNotification($body);

        return [
            'transaction_id' => $reference,
            'status'         => $normalized['status'],
            'amount'         => $normalized['amount'],
            'found'          => true,
            'endpoint'       => $endpoint,
            // order_id yang dikembalikan provider. Service memakainya untuk memastikan transaksi
            // yang baru saja kita temukan memang milik payment ini sebelum status diubah —
            // penting karena lookup lewat token bisa mengembalikan transaksi merchant lain
            // bila token salah tempel.
            'order_id'       => (string) ($body['order_id'] ?? ''),
            'payment_type'   => (string) ($body['payment_type'] ?? ''),
            // Midtrans mengenal transaksinya lewat id mereka sendiri (mis. A12026...). Untuk
            // e-wallet hasil Snap, inilah id yang berhasil dipakai bertanya status, jadi kita
            // bawa terus supaya service bisa mengingatnya otomatis.
            'provider_transaction_id' => (string) ($body['transaction_id'] ?? $reference),
            'provider'       => $this->getProviderName(),
            'lookup_status'  => $response->status(),
        ];
    }

    /** Bentuk token Snap: UUID 8-4-4-4-12 (transaction_id QRIS juga berbentuk sama). */
    private static function looksLikeSnapToken(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * Petakan transaction_status Midtrans ke status internal sistem.
     */
    public function normalizeNotification(array $decoded): array
    {
        $status     = strtolower(trim((string) ($decoded['transaction_status'] ?? '')));
        $statusCode = (string) ($decoded['status_code'] ?? '');
        $fraud      = strtolower(trim((string) ($decoded['fraud_status'] ?? 'accept')));

        $mapped = match (true) {
            // Uang benar-benar masuk: status settlement/capture dengan status_code 200/201 dan fraud_status accept.
            in_array($status, ['settlement', 'capture'], true)
                && in_array($statusCode, ['200', '201'], true)
                && in_array($fraud, ['accept', ''], true) => 'paid',

            in_array($status, ['expire', 'expired'], true) => 'expired',

            in_array($status, ['cancel', 'failed', 'fail', 'deny', 'refund'], true) => 'failed',

            default => 'pending',
        };

        $amount = isset($decoded['gross_amount']) ? round((float) $decoded['gross_amount'], 2) : null;

        return [
            'transaction_id' => isset($decoded['order_id']) ? (string) $decoded['order_id'] : null,
            'provider_transaction_id' => isset($decoded['transaction_id']) ? (string) $decoded['transaction_id'] : null,
            'status'         => $mapped,
            'amount'         => $amount,
            'event'          => 'payment.' . ($status !== '' ? $status : 'unknown'),
        ];
    }

    /**
     * Host untuk pemanggilan Snap (create transaction + halaman bayar).
     */
    private function snapBase(): string
    {
        return $this->isProduction ? self::SNAP_PRODUCTION : self::SNAP_SANDBOX;
    }

    /**
     * Host Core API (lookup status). Beda host dari Snap — lihat catatan di docblock kelas.
     */
    private function apiBase(): string
    {
        return $this->isProduction ? self::API_PRODUCTION : self::API_SANDBOX;
    }

    /**
     * URL halaman hasil pembayaran milik aplikasi, lengkap dengan order id transaksi ini.
     * Mengembalikan null bila PG_MIDTRANS_FINISH_URL/FRONTEND_URL tidak dipasang.
     */
    private function finishUrl(string $orderId): ?string
    {
        $base = trim((string) config('services.midtrans.finish_url', ''));

        if ($base === '') {
            return null;
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . http_build_query(['order_id' => $orderId]);
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        // HTTP Basic: username = server key, password kosong (sesuai dokumentasi Midtrans)
        return Http::withBasicAuth($this->serverKey, '')
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 200, throw: false);
    }
}
