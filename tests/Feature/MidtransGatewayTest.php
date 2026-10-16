<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\PaymentGateway\MidtransGateway;
use App\Services\PaymentGateway\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Integrasi Midtrans (Snap API) — diuji dengan Http::fake() memakai kontrak resmi Midtrans:
 * create = POST /snap/v1/transactions, status = GET /v2/{order_id}/status,
 * notifikasi = JSON body + signature_key SHA512(order_id + status_code + gross_amount + server key).
 * Tidak ada request keluar ke Midtrans sungguhan saat test.
 */
class MidtransGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-testkey123';

    protected User $admin;
    protected Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        $this->admin = User::where('role', UserRole::Admin)->first();
        $this->useMidtransSandbox();

        $customer = Customer::create(['name' => 'Tamu Midtrans', 'phone' => '081236660001']);
        $roomType = RoomType::create(['name' => 'Midtrans Room', 'price' => 300000, 'max_person' => 2]);
        $room     = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'MTX1']);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/bookings', [
            'customer_id' => $customer->id,
            'source'      => 'walk_in',
            'check_in'    => now()->format('Y-m-d'),
            'check_out'   => now()->addDay()->format('Y-m-d'),
            'guest_count' => 1,
            'rooms'       => [$room->id],
            'guests'      => [['name' => 'Tamu Midtrans', 'is_primary' => true]],
        ]);

        $response->assertStatus(201);

        $this->booking = Booking::find($response->json('data.id'));
    }

    protected function useMidtransSandbox(string $serverKey = self::SERVER_KEY, bool $isProduction = false): void
    {
        config([
            'services.payment_gateway.provider' => 'midtrans',
            'services.midtrans.server_key'      => $serverKey,
            'services.midtrans.client_key'      => 'Mid-client-testkey',
            'services.midtrans.signature_key'   => '',
            'services.midtrans.is_production'   => $isProduction,
        ]);
    }

    /** Instance gateway aktif (dipakai test yang memanggil gateway langsung, bukan via HTTP API). */
    protected function gateway(): MidtransGateway
    {
        $this->useMidtransSandbox();

        $gateway = app(PaymentGatewayInterface::class);
        $this->assertInstanceOf(MidtransGateway::class, $gateway);

        return $gateway;
    }

    /** Tagihan booking = 300.000 + 11% = 333.000 */
    protected function invoiceTotal(): float
    {
        return round((float) $this->booking->invoice->total, 2);
    }

    protected function fakeSnapCreate(): void
    {
        Http::fake([
            '*/snap/v1/transactions' => Http::response([
                'token'       => 'tok-midtrans-123',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/tok-midtrans-123',
            ], 200),
        ]);
    }

    protected function createGatewayPayment(string $channel = 'qris'): array
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/payments/gateway", ['channel' => $channel])
            ->assertStatus(201)
            ->json('data');
    }

    /** Bangun body notifikasi Midtrans + signature_key yang sah */
    protected function notification(string $orderId, string $status, string $grossAmount, string $statusCode = '200'): array
    {
        $body = [
            'transaction_id'     => 'mt-' . strtoupper(Str::random(8)),
            'order_id'           => $orderId,
            'gross_amount'       => $grossAmount,
            'currency'           => 'IDR',
            'payment_type'       => 'qris',
            'transaction_status' => $status,
            'status_code'        => $statusCode,
            'status_message'     => 'notification',
            'merchant_id'        => 'G123456',
        ];

        $body['signature_key'] = hash(
            'sha512',
            $orderId . $statusCode . $grossAmount . self::SERVER_KEY
        );

        return $body;
    }

    /**
     * Prefiks kunci TIDAK lagi dipakai untuk menolak konfigurasi: Midtrans punya lebih dari satu
     * gaya penamaan sandbox key (SB-Mid-server-... dan Mid-server-...), jadi penolakan berbasis
     * string mengunci merchant yang justru benar. Environment diverifikasi lewat testConnection().
     */
    public function test_provider_resolves_to_midtrans_for_either_key_style(): void
    {
        $this->assertInstanceOf(MidtransGateway::class, app(PaymentGatewayInterface::class));

        foreach ([self::SERVER_KEY, 'SB-Mid-server-abcdef'] as $key) {
            $this->useMidtransSandbox($key, false);
            $this->assertInstanceOf(MidtransGateway::class, app(PaymentGatewayInterface::class));
        }
    }

    public function test_production_flag_memakai_host_production(): void
    {
        $this->useMidtransSandbox(self::SERVER_KEY, true);

        $gateway = app(PaymentGatewayInterface::class);
        $this->assertInstanceOf(MidtransGateway::class, $gateway);

        Http::fake([
            'api.midtrans.com/v2/*/status' => Http::response(
                ['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 200
            ),
        ]);

        $result = $gateway->testConnection();

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('https://api.midtrans.com', $result['base_url']);
        $this->assertSame('https://app.midtrans.com', $result['snap_url']);
        $this->assertSame('production', $result['environment']);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.midtrans.com/'));
    }

    public function test_server_key_kosong_ditolak(): void
    {
        $this->useMidtransSandbox('', false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PG_MIDTRANS_SERVER_KEY belum diisi');

        app(PaymentGatewayInterface::class);
    }

    // ── Uji koneksi (testConnection) ─────────────────────────────────────────

    public function test_test_connection_lolos_saat_kredensial_diterima(): void
    {
        // Perilaku Midtrans sesungguhnya (diuji langsung ke sandbox): order id yang tidak dikenal
        // dijawab HTTP 200 + body status_code 404. Itu bukti kredensial lolos, bukan kegagalan.
        Http::fake([
            'api.sandbox.midtrans.com/v2/*/status' => Http::response(
                ['status_code' => '404', 'status_message' => "Transaction doesn't exist.", 'id' => 'abc-123'], 200
            ),
        ]);

        $result = $this->gateway()->testConnection();

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('https://api.sandbox.midtrans.com', $result['base_url']);
        $this->assertSame('https://app.sandbox.midtrans.com', $result['snap_url']);
        $this->assertSame('sandbox', $result['environment']);
    }

    public function test_test_connection_gagal_saat_kredensial_ditolak(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/v2/*/status' => Http::response(['status_code' => '401'], 401),
        ]);

        $result = $this->gateway()->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('menolak kredensial', $result['message']);
    }

    /**
     * Dulu HTTP 404 dianggap "koneksi OK" — itu salah dan menyembunyikan host yang keliru:
     * Midtrans menjawab 404 datar kalau endpoint-nya memang tidak ada di host tersebut.
     */
    public function test_test_connection_gagal_saat_endpoint_tidak_ada(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/v2/*/status' => Http::response('', 404),
        ]);

        $result = $this->gateway()->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Endpoint lookup tidak tersedia', $result['message']);
    }

    public function test_test_connection_lapor_saat_midtrans_terputus(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/*' => fn () => throw new ConnectionException('connection timeout'),
        ]);

        $result = $this->gateway()->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertNull($result['status']);
        $this->assertStringContainsString('tidak dapat dijangkau', $result['message']);
    }

    public function test_create_transaction_memakai_host_snap(): void
    {
        $this->fakeSnapCreate();

        $this->createGatewayPayment();

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://app.sandbox.midtrans.com/snap/v1/transactions'));
    }

    /**
     * Frontend membuka halaman bayar otomatis lewat Snap.js, jadi payload harus membawa
     * URL Snap JS + client key (keduanya publik) — dan tetap tanpa server key.
     */
    public function test_payment_info_membawa_kredensial_publik_snap_js(): void
    {
        $this->fakeSnapCreate();

        $payment = $this->createGatewayPayment();
        $payload = (array) ($payment['gateway_payload'] ?? []);

        $this->assertSame('https://app.sandbox.midtrans.com/snap/snap.js', $payload['snap_js_url'] ?? null);
        $this->assertSame('Mid-client-testkey', $payload['client_key'] ?? null);
        $this->assertSame('tok-midtrans-123', $payload['token'] ?? null);
        $this->assertStringNotContainsString(self::SERVER_KEY, (string) json_encode($payload));
    }

    /**
     * Channel 'auto': permintaan Snap dikirim TANPA enabled_payments, supaya Midtrans
     * menampilkan semua metode bayar yang aktif di Merchant Account dan tamu memilih
     * sendiri di overlay-nya (tidak perlu dipilihkan dari sistem).
     */
    public function test_channel_auto_tidak_mengirim_enabled_payments(): void
    {
        $this->fakeSnapCreate();

        $payment = $this->createGatewayPayment('auto');

        $this->assertSame('auto', $payment['channel'] ?? null);
        $this->assertSame('snap', $payment['gateway_payload']['channel'] ?? null);

        Http::assertSent(function ($request) use ($payment) {
            return str_contains($request->url(), 'app.sandbox.midtrans.com/snap/v1/transactions')
                && $request['transaction_details']['order_id'] === $payment['gateway_transaction_id']
                && !isset($request['enabled_payments'])
                && !str_contains((string) $request->body(), 'enabled_payments');
        });
    }

    /** Kanal spesifik masih bisa dipaksa kasir: QRIS saja, VA saja, e-wallet saja. */
    public function test_channel_spesifik_tetap_membatasi_enabled_payments(): void
    {
        $this->fakeSnapCreate();

        $payment = $this->createGatewayPayment('qris');

        $this->assertSame('qris', $payment['channel'] ?? null);

        Http::assertSent(function ($request) use ($payment) {
            return $request['transaction_details']['order_id'] === $payment['gateway_transaction_id']
                && $request['enabled_payments'] === ['qris'];
        });
    }

    public function test_channel_asing_ditolak_dengan_pesan_bahasa_indonesia(): void
    {
        $this->fakeSnapCreate();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/payments/gateway", ['channel' => 'bitcoin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel'])
            ->assertJsonPath('errors.channel.0', 'Metode pembayaran gateway tidak dikenal (auto, qris, va, ewallet).');
    }

    /**
     * Regresi dari pengujian langsung: tanpa booking_code/nama tamu, array kosong
     * dijson_encode menjadi [] (JSON array) dan Midtrans menjawab 400
     * "Metadata must be a json". Objek kosong `{}` yang benar.
     */
    public function test_data_pelanggan_kosong_tetap_dikirim_sebagai_objek_json(): void
    {
        $this->fakeSnapCreate();

        $this->gateway()->createTransaction(['amount' => 25000, 'channel' => 'auto']);

        Http::assertSent(function ($request) {
            $body = (string) $request->body();

            return str_contains($body, '"metadata":{}')
                && str_contains($body, '"customer_details":{"first_name":"Tamu Aedo Hotel"}')
                && !str_contains($body, 'enabled_payments');
        });
    }

    /**
     * HTTP 200 tanpa transaction_status = transaksi belum tercatat di Midtrans
     * (halaman Snap belum dibuka). Tidak boleh pernah dianggap lunas.
     */
    public function test_http_200_tanpa_transaction_status_bukan_pembayaran(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();
        $orderId = $payment['gateway_transaction_id'];

        Http::fake([
            '*/v2/' . $orderId . '/status' => Http::response([
                'status_code'     => '404',
                'status_message'  => "Transaction doesn't exist.",
                'id'              => 'be-3387a6-414b-477e-83a4-676c73469c92',
            ], 200),
        ]);

        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$payment['id']}/sync")
            ->assertStatus(200)
            ->assertJsonPath('gateway_status', 'pending')
            ->assertJsonPath('gateway_found', false)
            ->assertJsonPath('changed', false);

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment['id'])->status->value);
    }

    public function test_create_transaction_memanggil_snap_midtrans_dengan_order_id_kita(): void
    {
        $this->fakeSnapCreate();

        $payment = $this->createGatewayPayment();

        $this->assertStringStartsWith('AEDO-', (string) $payment['gateway_transaction_id']);
        $this->assertSame('midtrans', $payment['provider']);
        $this->assertSame(
            'https://app.sandbox.midtrans.com/snap/v2/vtweb/tok-midtrans-123',
            $payment['gateway_payload']['redirect_url'] ?? null
        );

        Http::assertSent(function ($request) use ($payment) {
            return str_contains($request->url(), 'app.sandbox.midtrans.com/snap/v1/transactions')
                && $request->headers()['Authorization'][0] === 'Basic ' . base64_encode(self::SERVER_KEY . ':')
                && $request['transaction_details']['order_id'] === $payment['gateway_transaction_id']
                && $request['transaction_details']['gross_amount'] === 333000
                && $request['enabled_payments'] === ['qris'];
        });
    }

    public function test_settlement_notification_melunasi_pembayaran_dan_booking(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();

        $this->postJson('/api/webhooks/payment-gateway', $this->notification(
            $payment['gateway_transaction_id'],
            'settlement',
            '333000.00'
        ))->assertStatus(200)->assertJsonPath('status', 'processed');

        $this->assertSame(PaymentStatus::Verified->value, Payment::find($payment['id'])->status->value);
        $this->assertSame(InvoiceStatus::Paid->value, $this->booking->invoice->fresh()->status->value);
        $this->assertSame(BookingStatus::Confirmed->value, $this->booking->fresh()->status->value);

        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'payment.gateway_verified',
            'auditable_type' => Payment::class,
            'auditable_id'   => $payment['id'],
        ]);
    }

    /**
     * Regresi bug nyata: settlement dengan status_code 201 — kode yang dipakai endpoint
     * /v2/{order_id}/status untuk QRIS/VA/e-wallet karena ia mengikuti kode respons pembuatan
     * transaksi — dulu terbaca "pending" selamanya padahal uangnya sudah masuk.
     */
    public function test_settlement_dengan_status_code_201_dianggap_lunas(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();

        $this->postJson('/api/webhooks/payment-gateway', $this->notification(
            $payment['gateway_transaction_id'],
            'settlement',
            '333000.00',
            '201'
        ))->assertStatus(200)->assertJsonPath('status', 'processed');

        $this->assertSame(PaymentStatus::Verified->value, Payment::find($payment['id'])->status->value);
        $this->assertSame(InvoiceStatus::Paid->value, $this->booking->invoice->fresh()->status->value);
    }

    public function test_sync_dengan_status_code_201_juga_memverifikasi_pembayaran(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();
        $orderId = $payment['gateway_transaction_id'];

        Http::fake([
            '*/v2/' . $orderId . '/status' => Http::response(
                $this->notification($orderId, 'settlement', '333000.00', '201'), 200
            ),
        ]);

        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$payment['id']}/sync")
            ->assertStatus(200)
            ->assertJsonPath('gateway_status', 'paid')
            ->assertJsonPath('payment_status', PaymentStatus::Verified->value);
    }

    /** Melonggarkan ke 201 tidak boleh melonggarkan kode error: settlement + status_code 400 tetap ditolak. */
    public function test_settlement_dengan_status_code_error_tidak_dianggap_lunas(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();

        $this->postJson('/api/webhooks/payment-gateway', $this->notification(
            $payment['gateway_transaction_id'],
            'settlement',
            '333000.00',
            '400'
        ))->assertStatus(200);

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment['id'])->status->value);
    }

    public function test_forged_midtrans_notification_is_rejected(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();

        $body = $this->notification($payment['gateway_transaction_id'], 'settlement', '333000.00');
        $body['signature_key'] = str_repeat('0', 128);

        $this->postJson('/api/webhooks/payment-gateway', $body)->assertStatus(401);

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment['id'])->status->value);
        $this->assertDatabaseHas('payment_gateway_logs', [
            'payment_id'      => $payment['id'],
            'signature_valid' => false,
        ]);
    }

    public function test_nominal_notifikasi_wajib_sama_walau_signature_sah(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();

        // Signature dihitung atas gross_amount Rp 1 -> sah secara Midtrans, tapi tidak cocok tagihan
        $this->postJson('/api/webhooks/payment-gateway', $this->notification(
            $payment['gateway_transaction_id'],
            'settlement',
            '1.00'
        ))->assertStatus(422)->assertJsonPath('status', 'amount_mismatch');

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment['id'])->status->value);
    }

    public function test_status_denied_tanpa_fraud_status_accept_tidak_melunasi(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();

        $body = $this->notification($payment['gateway_transaction_id'], 'capture', '333000.00');
        $body['fraud_status']  = 'deny';
        // signature dihitung ulang karena field body berubah tidak memengaruhi hash (hanya 3 field),
        // jadi tetap sah -> yang menolak harus aturan status, bukan signature
        $this->postJson('/api/webhooks/payment-gateway', $body)->assertStatus(200)->assertJsonPath('status', 'processed');

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment['id'])->status->value);
    }

    public function test_notifikasi_pending_setelah_settlement_tidak_menurunkan_status(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();
        $orderId = $payment['gateway_transaction_id'];

        $this->postJson('/api/webhooks/payment-gateway', $this->notification($orderId, 'settlement', '333000.00'))
            ->assertJsonPath('status', 'processed');

        // Midtrans kadang mengirim notifikasi di luar urutan; yang datang belakangan diabaikan
        $this->postJson('/api/webhooks/payment-gateway', $this->notification($orderId, 'pending', '333000.00'))
            ->assertStatus(200)
            ->assertJsonPath('status', 'skipped');

        $this->assertSame(PaymentStatus::Verified->value, Payment::find($payment['id'])->status->value);
    }

    public function test_cancel_notification_marks_payment_failed(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();

        $this->postJson('/api/webhooks/payment-gateway', $this->notification(
            $payment['gateway_transaction_id'],
            'cancel',
            '333000.00'
        ))->assertStatus(200)->assertJsonPath('status', 'processed');

        $this->assertSame(PaymentStatus::Failed->value, Payment::find($payment['id'])->status->value);
    }

    public function test_status_api_memulihkan_webhook_yang_hilang(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();
        $orderId = $payment['gateway_transaction_id'];

        Http::fake([
            '*/v2/' . $orderId . '/status' => Http::response($this->notification($orderId, 'settlement', '333000.00'), 200),
        ]);

        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$payment['id']}/sync")
            ->assertStatus(200)
            ->assertJsonPath('gateway_status', 'paid')
            ->assertJsonPath('payment_status', PaymentStatus::Verified->value);

        $this->assertSame(PaymentStatus::Verified->value, Payment::find($payment['id'])->status->value);
    }

    /**
     * Sinkron massal saat halaman dibuka: memulihkan semua payment gateway pending yang
     * di gateway sebenarnya sudah settlement, tanpa menunggu seseorang menekan tombol.
     * Dipakai semua role (halaman Daftar Pembayaran & Reservasi memanggilnya otomatis).
     */
    public function test_sync_pending_memulihkan_payment_gateway_yang_sudah_dibayar(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();
        $orderId = $payment['gateway_transaction_id'];

        Http::fake([
            '*/v2/' . $orderId . '/status' => Http::response($this->notification($orderId, 'settlement', '333000.00'), 200),
        ]);

        $pegawai = User::where('role', UserRole::Pegawai->value)->first()
            ?? User::factory()->create(['role' => UserRole::Pegawai->value, 'is_active' => true]);

        $this->actingAs($pegawai)->postJson('/api/v1/payments/sync-pending')
            ->assertStatus(200)
            ->assertJsonPath('changed', 1);

        $this->assertSame(PaymentStatus::Verified->value, Payment::find($payment['id'])->status->value);
        $this->assertSame(BookingStatus::Confirmed->value, $this->booking->fresh()->status->value);
    }

    /**
     * Otomatis, bukan manual: browser membawa transaction_id Midtrans (dari callback Snap JS
     * atau URL redirect), sync memakainya saat order_id kita tidak dikenali API - dan langsung
     * mengingatnya, jadi sync berikutnya sudah benar sendiri.
     */
    public function test_sync_menerima_transaction_id_provider_dari_browser(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();
        $ourOrderId = $payment['gateway_transaction_id'];
        $providerTxn = 'A120261015163622dgFUcuRDmaID';

        Http::fake([
            '*/v2/' . $ourOrderId . '/status' => Http::response(
                ['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 200
            ),
            '*/v2/' . $providerTxn . '/status' => Http::response([
                'order_id'           => $ourOrderId,
                'transaction_id'     => $providerTxn,
                'payment_type'       => 'gopay',
                'transaction_status' => 'settlement',
                'status_code'        => '200',
                'gross_amount'       => '333000.00',
                'fraud_status'       => 'accept',
            ], 200),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$payment['id']}/sync", ['transaction_id' => $providerTxn])
            ->assertStatus(200)
            ->assertJsonPath('gateway_status', 'paid')
            ->assertJsonPath('payment_status', PaymentStatus::Verified->value);

        $fresh = Payment::find($payment['id']);
        $this->assertSame(PaymentStatus::Verified->value, $fresh->status->value);
        // kunci webhook tetap utuh; id provider disimpan terpisah
        $this->assertSame($ourOrderId, (string) $fresh->gateway_transaction_id);
        $this->assertSame($providerTxn, $fresh->gateway_payload['provider_transaction_id'] ?? null);
    }

    /** Id berikutnya ditanya sendiri tanpa bantuan browser, karena sudah diingat. */
    public function test_sync_berikutnya_sudah_tahu_transaction_id_provider(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();
        $ourOrderId = $payment['gateway_transaction_id'];
        $providerTxn = 'A120261015170000AbCdEfGhIj';

        Payment::where('id', $payment['id'])->update([
            'gateway_payload' => json_encode((array) $payment['gateway_payload']
                + ['provider_transaction_id' => $providerTxn]),
        ]);

        Http::fake([
            '*/v2/' . $ourOrderId . '/status' => Http::response(
                ['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 200
            ),
            '*/v2/' . $providerTxn . '/status' => Http::response(
                $this->notification($providerTxn, 'settlement', '333000.00'), 200
            ),
        ]);

        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$payment['id']}/sync")
            ->assertStatus(200)
            ->assertJsonPath('payment_status', PaymentStatus::Verified->value);
    }

    /** Payment dengan token Snap sungguhan (UUID) — bentuk token Midtrans sebenarnya. */
    protected function createGatewayPaymentWithToken(string $token, string $channel = 'auto'): array
    {
        Http::fake([
            '*/snap/v1/transactions' => Http::response([
                'token'        => $token,
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/' . $token,
            ], 200),
        ]);

        return $this->createGatewayPayment($channel);
    }

    /**
     * REGRESI DANA yang benar-benar terjadi: tamu bayar DANA lewat Snap, Midtrans menjawab
     * settlement, tapi /v2/{order_id_kita}/status berkata "Transaction doesn't exist" karena
     * e-wallet hasil Snap disimpan di bawah order_id Midtrans sendiri (A12026...).
     * Satu-satunya id yang mereka kenali untuk transaksi ini adalah token Snap kita.
     */
    public function test_dana_snap_dilunasi_lewat_token_saat_order_id_tidak_dikenal_api(): void
    {
        $token   = '5bf7c071-e1c3-4b80-94dd-8b22697fd147';
        $payment = $this->createGatewayPaymentWithToken($token);
        $orderId = $payment['gateway_transaction_id'];

        Http::fake([
            // Core API: order_id kita tidak dikenal (persis seperti jawaban server asli)
            '*/v2/' . $orderId . '/status' => Http::response([
                'status_code'    => '404',
                'status_message' => "Transaction doesn't exist.",
            ], 200),
            // Snap API: token dikenal dan transaksinya sudah settlement
            '*/snap/v1/transactions/' . $token . '/status' => Http::response([
                'token'              => $token,
                'status_code'        => '200',
                'transaction_id'     => 'A120261015175339n2OJHjg6pEID',
                'order_id'           => $orderId,
                'payment_type'       => 'dana',
                'gross_amount'       => '333000.00',
                'currency'           => 'IDR',
                'transaction_status' => 'settlement',
                'fraud_status'       => 'accept',
                'merchant_id'        => 'M140314068',
            ], 200),
        ]);

        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$payment['id']}/sync")
            ->assertStatus(200)
            ->assertJsonPath('gateway_status', 'paid')
            ->assertJsonPath('gateway_found', true)
            ->assertJsonPath('payment_status', PaymentStatus::Verified->value);

        $fresh = Payment::find($payment['id']);
        $this->assertSame(PaymentStatus::Verified->value, $fresh->status->value);
        $this->assertSame(BookingStatus::Confirmed->value, $this->booking->fresh()->status->value);
        // order_id kita tetap kunci webhook; id Midtrans yang baru dikenal disimpan terpisah
        $this->assertSame($orderId, (string) $fresh->gateway_transaction_id);
        $this->assertSame('A120261015175339n2OJHjg6pEID', $fresh->gateway_payload['provider_transaction_id'] ?? null);
    }

    /** Sync berikutnya cukup sekali tanya: token sudah terbukti, id provider sudah diingat. */
    public function test_token_snap_dipakai_otomatis_oleh_sinkron_massal(): void
    {
        $token   = '9a1b2c3d-4e5f-4a6b-8c7d-1e2f3a4b5c6d';
        $payment = $this->createGatewayPaymentWithToken($token);
        $orderId = $payment['gateway_transaction_id'];

        Http::fake([
            '*/v2/' . $orderId . '/status' => Http::response([
                'status_code'    => '404',
                'status_message' => "Transaction doesn't exist.",
            ], 200),
            '*/snap/v1/transactions/' . $token . '/status' => Http::response([
                'token'              => $token,
                'status_code'        => '200',
                'transaction_id'     => 'A120261016000000ZzYyXxWwVvUu',
                'order_id'           => $orderId,
                'payment_type'       => 'dana',
                'gross_amount'       => '333000.00',
                'transaction_status' => 'settlement',
                'fraud_status'       => 'accept',
            ], 200),
        ]);

        // Tidak ada yang menekan tombol apa pun: scheduler / buka halaman melakukan ini.
        $this->actingAs($this->admin)->postJson('/api/v1/payments/sync-pending')
            ->assertStatus(200)
            ->assertJsonPath('changed', 1);

        $this->assertSame(PaymentStatus::Verified->value, Payment::find($payment['id'])->status->value);
    }

    /** Token yang tidak dikenal Midtrans (HTTP 404 "token not found") tidak mengubah apa pun. */
    public function test_token_snap_yang_asing_tidak_mengubah_status(): void
    {
        $token   = '00000000-0000-4000-8000-000000000000';
        $payment = $this->createGatewayPaymentWithToken($token);
        $orderId = $payment['gateway_transaction_id'];

        Http::fake([
            '*/v2/' . $orderId . '/status' => Http::response([
                'status_code'    => '404',
                'status_message' => "Transaction doesn't exist.",
            ], 200),
            '*/snap/v1/transactions/' . $token . '/status' => Http::response([
                'error_messages' => ['token not found'],
            ], 404),
        ]);

        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$payment['id']}/sync")
            ->assertStatus(200)
            ->assertJsonPath('gateway_status', 'pending')
            ->assertJsonPath('gateway_found', false)
            ->assertJsonPath('changed', false);

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment['id'])->status->value);
    }

    /**
     * Lookup lewat token tidak boleh pernah melunasi payment lain: kalau Midtrans
     * mengembalikan transaksi dengan order_id berbeda (token salah tempel / payload bekas),
     * jawabannya diabaikan.
     */
    public function test_jawaban_midtrans_untuk_order_id_lain_tidak_melunasi_payment_ini(): void
    {
        $token   = '7c1d2e3f-4a5b-4c6d-9e0f-1a2b3c4d5e6f';
        $payment = $this->createGatewayPaymentWithToken($token);
        $orderId = $payment['gateway_transaction_id'];

        Http::fake([
            '*/v2/' . $orderId . '/status' => Http::response([
                'status_code'    => '404',
                'status_message' => "Transaction doesn't exist.",
            ], 200),
            '*/snap/v1/transactions/' . $token . '/status' => Http::response([
                'token'              => $token,
                'status_code'        => '200',
                // order_id transaksi lain, nominal kebetulan sama
                'order_id'           => 'AEDO-PUNYIKKREDITUMLAIN',
                'transaction_id'     => 'A120261016000000QqWwEeRrTtYy',
                'payment_type'       => 'dana',
                'gross_amount'       => '333000.00',
                'transaction_status' => 'settlement',
                'fraud_status'       => 'accept',
            ], 200),
        ]);

        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$payment['id']}/sync")
            ->assertStatus(200)
            ->assertJsonPath('gateway_status', 'pending')
            ->assertJsonPath('gateway_found', false)
            ->assertJsonPath('payment_status', PaymentStatus::Pending->value);

        $fresh = Payment::find($payment['id']);
        $this->assertSame(PaymentStatus::Pending->value, $fresh->status->value);
        // id transaksi asing tidak boleh dijadikan referensi tetap
        $this->assertNull($fresh->gateway_payload['provider_transaction_id'] ?? null);
    }

    /** order_id biasa tidak ditanyakan ke endpoint token (hemat satu trip jaringan). */
    public function test_order_id_tidak_pernah_ditanyakan_ke_endpoint_token_snap(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();
        $orderId = $payment['gateway_transaction_id'];

        Http::fake([
            '*/v2/' . $orderId . '/status' => Http::response(
                $this->notification($orderId, 'settlement', '333000.00'), 200
            ),
        ]);

        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$payment['id']}/sync")
            ->assertStatus(200)
            ->assertJsonPath('payment_status', PaymentStatus::Verified->value);

        Http::assertNotSent(function ($request) {
            return str_contains((string) $request->url(), '/snap/v1/transactions/')
                && str_ends_with((string) $request->url(), '/status');
        });
    }

    /** Perintah diagnostik harus tetap jalan untuk payment yang order_id-nya tak dikenal API. */
    public function test_perintah_payment_status_membaca_lewat_semua_id_yang_diketahui(): void
    {
        $token   = '2b3c4d5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e';
        $payment = $this->createGatewayPaymentWithToken($token);
        $orderId = $payment['gateway_transaction_id'];

        Http::fake([
            '*/v2/' . $orderId . '/status' => Http::response([
                'status_code'    => '404',
                'status_message' => "Transaction doesn't exist.",
            ], 200),
            '*/snap/v1/transactions/' . $token . '/status' => Http::response([
                'token'              => $token,
                'status_code'        => '200',
                'order_id'           => $orderId,
                'transaction_id'     => 'A120261016000000MmNnOoPpQq',
                'gross_amount'       => '333000.00',
                'payment_type'       => 'dana',
                'transaction_status' => 'settlement',
                'fraud_status'       => 'accept',
            ], 200),
        ]);

        $this->artisan('payment:status', ['order_id' => $orderId])
            ->expectsOutputToContain('BELUM SINKRON')
            ->assertExitCode(0);
    }

    /** Pengiriman id apa pun tidak boleh menciptakan pelunasan: gateway yang memutuskan. */
    public function test_transaction_id_di_body_tidak_bisa_memalsukan_pelunasan(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();
        $providerTxn = 'A120261015180000XyZpQRSTUVWXYZ';

        Http::fake([
            '*/v2/*' => Http::response([
                'order_id'           => $payment['gateway_transaction_id'],
                'transaction_id'     => $providerTxn,
                'transaction_status' => 'pending',
                'status_code'        => '201',
                'gross_amount'       => '333000.00',
            ], 200),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$payment['id']}/sync", ['transaction_id' => $providerTxn])
            ->assertStatus(200)
            ->assertJsonPath('payment_status', PaymentStatus::Pending->value);
    }

    /** Notifikasi sah juga mengisi memori transaction_id, tanpa menunggu ada yang menekan apa pun. */
    public function test_webhook_ikut_mengingat_transaction_id_midtrans(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();

        $notification = $this->notification($payment['gateway_transaction_id'], 'settlement', '333000.00');

        $this->postJson('/api/webhooks/payment-gateway', $notification)
            ->assertStatus(200)
            ->assertJsonPath('status', 'processed');

        $this->assertSame(
            $notification['transaction_id'],
            Payment::find($payment['id'])->gateway_payload['provider_transaction_id'] ?? null
        );
    }

    public function test_status_api_yang_404_tidak_mengubah_apa_pun(): void
    {
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();
        $orderId = $payment['gateway_transaction_id'];

        Http::fake([
            '*/v2/' . $orderId . '/status' => Http::response(['error_code' => '40401'], 404),
        ]);

        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$payment['id']}/sync")
            ->assertStatus(200)
            ->assertJsonPath('gateway_status', 'pending')
            ->assertJsonPath('changed', false);

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment['id'])->status->value);
    }

    public function test_gagal_create_transaction_midtrans_dibalas_422_dan_tidak_menyimpan_payment(): void
    {
        Http::fake([
            '*/snap/v1/transactions' => Http::response([
                'error_code' => '400',
                'message'    => 'Transaction amount is not valid',
            ], 400),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/payments/gateway", ['channel' => 'qris'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'error']);

        $this->assertSame(0, Payment::where('booking_id', $this->booking->id)->count());
    }

    public function test_pembayaran_gateway_midtrans_tidak_bisa_dilunasi_lewat_verifikasi_manual_tanpa_bukti(): void
    {
        // Sanity: payment gateway hanya boleh berubah oleh webhook/status provider, bukan oleh
        // tombol verifikasi biasa? Verifikasi admin tetap sah (admin = pemilik uang), tapi
        // tidak lewat jalur gateway: cek bahwa status paymentgateway tidak auto-verified.
        $this->fakeSnapCreate();
        $payment = $this->createGatewayPayment();

        $this->assertSame(PaymentStatus::Pending->value, $payment['status']);

        $this->actingAs($this->admin)->patchJson("/api/v1/payments/{$payment['id']}/verify")
            ->assertStatus(200);

        $this->assertSame(PaymentStatus::Verified->value, Payment::find($payment['id'])->status->value);
    }
}
