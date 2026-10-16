<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Layar hasil pembayaran publik (dituju callbacks.finish Midtrans).
 *
 * Yang dijaga di sini: tamu boleh melihat status transaksinya sendiri tanpa login, tapi
 * status itu HARUS berasal dari verifikasi backend ke gateway — bukan dari parameter URL,
 * karena URL redirect bisa diketik/dipalsukan siapa pun.
 */
class PaymentResultTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-testkey123';
    private const ORDER_ID   = 'AEDO-HASILTEST000001';

    protected User $admin;
    protected Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        $this->admin = User::where('role', UserRole::Admin->value)->first();

        config([
            'services.payment_gateway.provider' => 'midtrans',
            'services.midtrans.server_key'      => self::SERVER_KEY,
            'services.midtrans.signature_key'   => '',
            'services.midtrans.is_production'   => false,
            'services.midtrans.finish_url'      => 'http://localhost:5173/payment-result',
        ]);

        $customer = Customer::create(['name' => 'Tamu Hasil', 'phone' => '081237770002', 'email' => 'tamu@hasil.test']);
        $roomType = RoomType::create(['name' => 'Hasil Room', 'price' => 300000, 'max_person' => 2]);
        $room     = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'HAS1']);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/bookings', [
            'customer_id' => $customer->id,
            'source'      => 'walk_in',
            'check_in'    => now()->format('Y-m-d'),
            'check_out'   => now()->addDay()->format('Y-m-d'),
            'guest_count' => 1,
            'rooms'       => [$room->id],
            'guests'      => [['name' => 'Tamu Hasil', 'is_primary' => true]],
        ]);

        $response->assertStatus(201);

        $this->booking = Booking::find($response->json('data.id'));
    }

    /** Payment gateway pending atas nama booking fixture. */
    protected function makePendingGatewayPayment(): Payment
    {
        $invoice = $this->booking->invoice;

        return Payment::create([
            'booking_id'             => $this->booking->id,
            'invoice_id'             => $invoice->id,
            'created_by'             => $this->admin->id,
            'method'                 => PaymentMethod::PaymentGateway->value,
            'type'                   => PaymentType::Final->value,
            'status'                 => PaymentStatus::Pending->value,
            'amount'                 => (float) $invoice->total,
            'provider'               => 'midtrans',
            'gateway_transaction_id' => self::ORDER_ID,
            'channel'                => 'auto',
            'gateway_payload'        => [
                'transaction_id' => self::ORDER_ID,
                'environment'    => 'sandbox',
            ],
            'expires_at'             => now()->addMinute(),
        ]);
    }

    protected function gatewayStatus(array $body): void
    {
        Http::fake([
            '*/v2/' . self::ORDER_ID . '/status' => Http::response($body, 200),
        ]);
    }

    public function test_tamu_boleh_membaca_hasil_tanpa_login_dan_status_diverifikasi_ke_gateway(): void
    {
        $payment = $this->makePendingGatewayPayment();

        $this->gatewayStatus([
            'order_id'           => self::ORDER_ID,
            'transaction_id'     => 'mt-hasil-1',
            'payment_type'       => 'qris',
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'gross_amount'       => '333000.00',
            'fraud_status'       => 'accept',
        ]);

        $response = $this->getJson('/api/payment-result/' . self::ORDER_ID)
            ->assertStatus(200)
            ->assertJsonPath('found', true)
            ->assertJsonPath('payment_status', PaymentStatus::Verified->value)
            ->assertJsonPath('booking_status', BookingStatus::Confirmed->value)
            ->assertJsonPath('booking_code', $this->booking->booking_code)
            ->assertJsonPath('environment', 'sandbox');

        $this->assertEqualsWithDelta(333000.0, (float) $response->json('amount'), 0.001);
        $this->assertSame(PaymentStatus::Verified->value, Payment::find($payment->id)->fresh()->status->value);
    }

    /**
     * Inti keamanannya: "?status=success" dari redirect hanya hiasan. Kalau gateway menjawab
     * pending, sistem tetap menjawab pending.
     */
    public function test_param_status_di_url_tidak_pernah_dipercaya(): void
    {
        $this->makePendingGatewayPayment();

        $this->gatewayStatus([
            'order_id'           => self::ORDER_ID,
            'transaction_status' => 'pending',
            'status_code'        => '201',
            'gross_amount'       => '333000.00',
        ]);

        $this->getJson('/api/payment-result/' . self::ORDER_ID . '?status=success')
            ->assertStatus(200)
            ->assertJsonPath('payment_status', PaymentStatus::Pending->value)
            ->assertJsonPath('booking_status', BookingStatus::Pending->value)
            ->assertJsonPath('message', 'Pembayaran masih menunggu konfirmasi dari gateway.');

        $this->assertSame(PaymentStatus::Pending->value, Payment::where('gateway_transaction_id', self::ORDER_ID)->firstOrFail()->status->value);
    }

    /**
     * Otomatis tanpa webhook/tunnel: Midtrans menempelkan transaction_id-nya pada URL redirect,
     * halaman hasil mengirimnya ke sini, dan backend menanyakannya ke gateway. Yang dipercaya
     * tetap jawaban gateway (status lunas + nominal sama), bukan parameter URL.
     */
    public function test_redirect_dengan_transaction_id_melunaskan_status_sendiri(): void
    {
        $payment     = $this->makePendingGatewayPayment();
        $providerTxn = 'A120261015163622dgFUcuRDmaID';

        Http::fake([
            // order_id kita tidak dikenali API Midtrans untuk e-wallet hasil Snap
            '*/v2/' . self::ORDER_ID . '/status' => Http::response(
                ['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 200
            ),
            // transaction_id mereka dikenali dan tercatat lunas
            '*/v2/' . $providerTxn . '/status' => Http::response([
                'order_id'           => self::ORDER_ID,
                'transaction_id'     => $providerTxn,
                'payment_type'       => 'gopay',
                'transaction_status' => 'settlement',
                'status_code'        => '200',
                'gross_amount'       => '333000.00',
                'fraud_status'       => 'accept',
            ], 200),
        ]);

        $this->getJson('/api/payment-result/' . self::ORDER_ID . '?transaction_id=' . $providerTxn)
            ->assertStatus(200)
            ->assertJsonPath('payment_status', PaymentStatus::Verified->value)
            ->assertJsonPath('booking_status', BookingStatus::Confirmed->value)
            ->assertJsonPath('message', 'Pembayaran terkonfirmasi.');

        $this->assertSame(PaymentStatus::Verified->value, Payment::find($payment->id)->fresh()->status->value);
        $this->assertSame(BookingStatus::Confirmed->value, $this->booking->fresh()->status->value);
    }

    /**
     * Jalur e-wallet (kasus nyata DANA): API status tidak mengenal order_id, tapi redirect
     * Midtrans membawa parameter notifikasi + signature_key = SHA512(order_id + status_code +
     * gross_amount + server key). Tanda tangan sah = bukti sekuat webhook, jadi lunas otomatis.
     */
    public function test_redirect_tersign_melunaskan_saat_api_status_tidak_mengenal_transaksinya(): void
    {
        $payment = $this->makePendingGatewayPayment();

        Http::fake([
            '*' => Http::response(['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 200),
        ]);

        $this->getJson('/api/payment-result/' . self::ORDER_ID . '?' . http_build_query(
            $this->signedRedirect(['status_code' => '200', 'gross_amount' => '333000.00'])
        ))
            ->assertStatus(200)
            ->assertJsonPath('payment_status', PaymentStatus::Verified->value)
            ->assertJsonPath('booking_status', BookingStatus::Confirmed->value);

        $this->assertSame(PaymentStatus::Verified->value, Payment::find($payment->id)->fresh()->status->value);
        $this->assertDatabaseHas('payment_gateway_logs', [
            'payment_id'      => $payment->id,
            'signature_valid' => true,
            'processed'       => true,
        ]);
    }

    /** Signature palsu di URL tidak boleh menciptakan pelunasan. */
    public function test_redirect_dengan_signature_palsu_ditolak(): void
    {
        $payment = $this->makePendingGatewayPayment();

        Http::fake([
            '*' => Http::response(['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 200),
        ]);

        $params = $this->signedRedirect(['status_code' => '200', 'gross_amount' => '333000.00']);
        $params['signature_key'] = str_repeat('0', 128);

        $this->getJson('/api/payment-result/' . self::ORDER_ID . '?' . http_build_query($params))
            ->assertStatus(200)
            ->assertJsonPath('payment_status', PaymentStatus::Pending->value);

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment->id)->fresh()->status->value);
        $this->assertDatabaseHas('payment_gateway_logs', [
            'payment_id'      => $payment->id,
            'signature_valid' => false,
        ]);
    }

    /** Tanda tangan sah atas nominal kecil tidak boleh melunasi tagihan besar. */
    public function test_redirect_tersign_tapi_nominal_beda_tetak_ditolak(): void
    {
        $payment = $this->makePendingGatewayPayment();

        Http::fake([
            '*' => Http::response(['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 200),
        ]);

        $this->getJson('/api/payment-result/' . self::ORDER_ID . '?' . http_build_query(
            $this->signedRedirect(['status_code' => '200', 'gross_amount' => '1000.00'])
        ))->assertStatus(200)->assertJsonPath('payment_status', PaymentStatus::Pending->value);

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment->id)->fresh()->status->value);
    }

    /** Bangun parameter redirect Midtrans dengan signature_key yang sah. */
    private function signedRedirect(array $overrides = []): array
    {
        $params = array_merge([
            'order_id'           => self::ORDER_ID,
            'transaction_id'     => 'mt-dana-0001',
            'payment_type'       => 'dana',
            'transaction_status' => 'settlement',
            'fraud_status'       => 'accept',
            'currency'           => 'IDR',
            'status_code'        => '200',
            'gross_amount'       => '333000.00',
        ], $overrides);

        $params['signature_key'] = hash(
            'sha512',
            $params['order_id'] . $params['status_code'] . $params['gross_amount'] . self::SERVER_KEY
        );

        return $params;
    }

    /** Tamu iseng mengarang transaction_id: selama gateway tidak mengiyakan, status diam. */
    public function test_transaction_id_karangan_di_url_tidak_melunapkan_siapa_pun(): void
    {
        $payment = $this->makePendingGatewayPayment();

        Http::fake([
            '*' => Http::response(['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 200),
        ]);

        $this->getJson('/api/payment-result/' . self::ORDER_ID . '?transaction_id=A1200000000000Karangan1')
            ->assertStatus(200)
            ->assertJsonPath('payment_status', PaymentStatus::Pending->value);

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment->id)->fresh()->status->value);
    }

    public function test_order_id_tidak_dikenal_dibalas_404(): void
    {
        Http::fake();

        $this->getJson('/api/payment-result/AEDO-BUKANMILIKKITA')
            ->assertStatus(404)
            ->assertJsonPath('found', false)
            ->assertJsonPath('message', 'Transaksi tidak ditemukan.');
    }

    /** Order id boleh ditebak? Tidak: 16 karakter acak. Tapi respons tetap tidak boleh membocorkan PII. */
    public function test_respons_tidak_membocorkan_data_pribadi_tamu(): void
    {
        $this->makePendingGatewayPayment();

        $this->gatewayStatus([
            'order_id'           => self::ORDER_ID,
            'transaction_status' => 'pending',
            'status_code'        => '201',
            'gross_amount'       => '333000.00',
        ]);

        $data = $this->getJson('/api/payment-result/' . self::ORDER_ID)
            ->assertStatus(200)
            ->json();

        $this->assertIsArray($data);
        $this->assertEqualsCanonicalizing(
            ['found', 'booking_code', 'amount', 'payment_status', 'booking_status', 'paid_at', 'provider', 'environment', 'message'],
            array_keys($data)
        );

        $raw = json_encode($data);
        foreach (['Tamu Hasil', '081237770002', 'tamu@hasil.test', 'ktp', 'invoice_number'] as $forbidden) {
            $this->assertStringNotContainsString(strtolower($forbidden), strtolower($raw));
        }
    }

    /** callbacks.finish harus ikut terkirim ke Snap, lengkap dengan order id transaksi ini. */
    public function test_create_transaction_mengirim_callbacks_finish_ke_midtrans(): void
    {
        Http::fake([
            '*/snap/v1/transactions' => Http::response(['token' => 'tok-1', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/x'], 200),
        ]);

        $result = app(\App\Services\PaymentGateway\PaymentGatewayInterface::class)
            ->createTransaction([
                'amount'        => 25000,
                'channel'       => 'auto',
                'customer_name' => 'Tamu Hasil',
                'booking_code'  => $this->booking->booking_code,
            ]);

        Http::assertSent(function ($request) use ($result) {
            // callbacks dikirim sebagai objek, jadi diakses sebagai properti (bukan array)
            $finish = $request['callbacks']->finish ?? null;

            return str_contains($request->url(), '/snap/v1/transactions')
                && $finish === 'http://localhost:5173/payment-result?order_id=' . $result['transaction_id'];
        });
    }
}
