<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Uang muka (DP) harus benar-benar berfungsi, bukan cuma label di layar reservasi.
 *
 * Yang dijaga di sini:
 *  - ambang DP dihitung di SATU tempat (Booking) dan dipakai backend maupun frontend;
 *  - gateway boleh menagih SEBAGIAN (DP), tidak selalu seluruh sisa tagihan;
 *  - DP terverifikasi mengonfirmasi reservasi walau invoice belum lunas;
 *  - nominal tidak bisa melewati sisa tagihan, dan tidak ada dua tautan bayar hidup
 *    sekaligus untuk tagihan yang sama.
 */
class DpPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-dp-testkey';

    protected User $admin;
    protected Booking $booking;

    /** Tagihan booking fixture = 300.000 + 11% = 333.000 */
    protected float $total;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        $this->admin = User::where('role', UserRole::Admin->value)->first();

        config([
            'services.payment_gateway.provider' => 'midtrans',
            'services.midtrans.server_key'      => self::SERVER_KEY,
            'services.midtrans.client_key'      => 'Mid-client-dp-testkey',
            'services.midtrans.signature_key'   => '',
            'services.midtrans.is_production'   => false,
        ]);

        $customer = Customer::create(['name' => 'Tamu DP', 'phone' => '081238880001', 'email' => 'dp@uji.test']);
        $roomType = RoomType::create(['name' => 'DP Room', 'price' => 300000, 'max_person' => 2]);
        $room     = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'DPX1']);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/bookings', [
            'customer_id' => $customer->id,
            'source'      => 'walk_in',
            'check_in'    => now()->format('Y-m-d'),
            'check_out'   => now()->addDay()->format('Y-m-d'),
            'guest_count' => 1,
            'rooms'       => [$room->id],
            'guests'      => [['name' => 'Tamu DP', 'is_primary' => true]],
        ]);

        $response->assertStatus(201);

        $this->booking = Booking::with('invoice')->find($response->json('data.id'));
        $this->total   = round((float) $this->booking->invoice_total, 2);
    }

    /** Persentase DP minimum hotel, seperti yang diatur admin di Pengaturan. */
    protected function setMinDp(float $percent): void
    {
        Setting::first()->update(['min_dp_percentage' => $percent]);
    }

    /** Halaman Snap selalu berhasil; order id acak seperti gateway asli. */
    protected function fakeSnapCreate(): void
    {
        Http::fake([
            '*/snap/v1/transactions' => Http::response([
                'token'        => 'tok-dp-' . strtolower(bin2hex(random_bytes(4))),
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/tok-dp',
            ], 200),
        ]);
    }

    protected function requestGateway(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/payments/gateway", $body);
    }

    /** Notifikasi Midtrans untuk order id payment tertentu. */
    protected function settlement(Payment $payment, float $amount): array
    {
        $orderId = (string) $payment->gateway_transaction_id;

        $body = [
            'transaction_id'     => 'A120261016000000DpTest' . $payment->id,
            'order_id'           => $orderId,
            'gross_amount'       => number_format($amount, 2, '.', ''),
            'currency'           => 'IDR',
            'payment_type'       => 'dana',
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'fraud_status'       => 'accept',
        ];

        $body['signature_key'] = hash(
            'sha512',
            $orderId . '200' . $body['gross_amount'] . self::SERVER_KEY
        );

        return $body;
    }

    // ----------------------------------------------------------------- ambang DP

    public function test_ambang_dp_dihitung_dari_pengaturan_dan_muncul_di_api(): void
    {
        $this->setMinDp(30);

        $booking = Booking::with('invoice')->find($this->booking->id);

        $this->assertEqualsWithDelta(round($this->total * 0.30, 2), (float) $booking->dp_min_amount, 0.01);
        $this->assertEqualsWithDelta((float) $booking->dp_min_amount, (float) $booking->dp_shortfall, 0.01);

        // JSON menulis 99900 tanpa desimal, jadi nilainya dibandingkan sebagai float
        $json = $this->actingAs($this->admin)->getJson("/api/v1/bookings/{$this->booking->id}")
            ->assertStatus(200)
            ->json();

        $this->assertEqualsWithDelta((float) $booking->dp_min_amount, (float) $json['data']['dp_min_amount'], 0.01);
        $this->assertEqualsWithDelta((float) $booking->dp_shortfall, (float) $json['data']['dp_shortfall'], 0.01);
    }

    /**
     * Layar reservasi membaca ambang DP dari /api/v1/settings untuk menampilkan tombol
     * "DP x%". Field ini hilang dari daftar baca publik = DP tidak pernah ditawarkan,
     * padahal backend masih memakainya - jadi kontrak kecil ini ikut dijaga.
     */
    public function test_pengaturan_publik_membawa_ambang_dp_untuk_dibaca_frontend(): void
    {
        $this->setMinDp(25);

        $this->getJson('/api/v1/settings')
            ->assertStatus(200)
            ->assertJsonPath('data.min_dp_percentage', '25.00');
    }

    public function test_dp_tidak_diwajibkan_saat_persen_nol(): void
    {
        $this->setMinDp(0);

        $booking = Booking::with('invoice')->find($this->booking->id);

        $this->assertSame(0.0, (float) $booking->dp_min_amount);
        $this->assertSame(0.0, (float) $booking->dp_shortfall);
    }

    // ------------------------------------------------------------- DP via gateway

    public function test_dp_lewat_gateway_menagih_tepat_sebesar_ambang(): void
    {
        $this->setMinDp(30);
        $this->fakeSnapCreate();

        $expected = (float) Booking::find($this->booking->id)->dp_min_amount;
        $this->assertGreaterThan(0, $expected);

        $response = $this->requestGateway(['channel' => 'auto', 'type' => 'dp'])->assertStatus(201);

        $payment = Payment::find($response->json('data.id'));

        $this->assertSame(PaymentType::Dp->value, (string) $payment->type->value);
        $this->assertEqualsWithDelta($expected, (float) $payment->amount, 0.01);
        $this->assertSame(PaymentStatus::Pending->value, (string) $payment->status->value);

        // Midtrans harus menerima gross_amount sebesar DP, bukan seluruh tagihan
        Http::assertSent(function ($request) use ($expected) {
            return (int) $request['transaction_details']['gross_amount'] === (int) round($expected);
        });
    }

    public function test_dp_lewat_gateway_bisa_dengan_nominal_sendiri(): void
    {
        $this->setMinDp(30);
        $this->fakeSnapCreate();

        $response = $this->requestGateway([
            'channel' => 'auto',
            'type'    => 'dp',
            'amount'  => 150000,
        ])->assertStatus(201);

        $payment = Payment::find($response->json('data.id'));

        $this->assertEqualsWithDelta(150000.0, (float) $payment->amount, 0.01);
        $this->assertSame(PaymentType::Dp->value, (string) $payment->type->value);
    }

    public function test_dp_terverifikasi_mengonfirmasikan_booking_walau_invoice_belum_lunas(): void
    {
        // Ini inti fitur DP: kamar terkunci oleh uang muka, bukan oleh pelunasan.
        $this->setMinDp(30);
        $this->fakeSnapCreate();

        $paymentId = $this->requestGateway(['channel' => 'auto', 'type' => 'dp'])
            ->assertStatus(201)
            ->json('data.id');

        $payment = Payment::find($paymentId);

        $this->assertSame(BookingStatus::Pending->value, (string) $this->booking->fresh()->status->value);

        $this->postJson('/api/webhooks/payment-gateway', $this->settlement($payment, (float) $payment->amount))
            ->assertStatus(200)
            ->assertJsonPath('status', 'processed');

        $booking = Booking::with('invoice')->find($this->booking->id);
        $payment = Payment::find($paymentId);

        $this->assertSame(PaymentStatus::Verified->value, (string) $payment->status->value);
        $this->assertSame(BookingStatus::Confirmed->value, (string) $booking->status->value);
        $this->assertNotSame(InvoiceStatus::Paid->value, (string) $booking->invoice->status->value);
        $this->assertGreaterThan(0, (float) $booking->remaining_due);
        $this->assertEqualsWithDelta(0.0, (float) $booking->dp_shortfall, 0.01);
    }

    public function test_dp_dibawah_ambang_membayar_tetap_pending_konfirmasi(): void
    {
        // Uang masuk tapi belum mencapai ambang: booking jangan ikut terkunci 'confirmed',
        // kalau tidak check-in jadi lolos tanpa uang muka yang benar.
        $this->setMinDp(60);
        $this->fakeSnapCreate();

        $paymentId = $this->requestGateway([
            'channel' => 'auto',
            'type'    => 'dp',
            'amount'  => 50000,
        ])->assertStatus(201)->json('data.id');

        $payment = Payment::find($paymentId);

        $this->postJson('/api/webhooks/payment-gateway', $this->settlement($payment, 50000.0))
            ->assertStatus(200)
            ->assertJsonPath('status', 'processed');

        $booking = Booking::find($this->booking->id);

        $this->assertSame(PaymentStatus::Verified->value, (string) Payment::find($paymentId)->status->value);
        $this->assertSame(BookingStatus::Pending->value, (string) $booking->status->value);
        $this->assertGreaterThan(0, (float) $booking->dp_shortfall);
    }

    // ------------------------------------------------------------------ penjaga

    public function test_nominal_gateway_melebihi_sisa_ditolak(): void
    {
        $this->setMinDp(30);
        $this->fakeSnapCreate();

        $response = $this->requestGateway([
            'channel' => 'auto',
            'type'    => 'dp',
            'amount'  => $this->total + 100000,
        ])->assertStatus(422);

        $this->assertStringContainsString('melebihi sisa tagihan', (string) $response->json('error'));
        $this->assertSame(0, Payment::where('booking_id', $this->booking->id)->count());
    }

    public function test_dp_ditolak_saat_ambang_dp_belum_diatur(): void
    {
        $this->setMinDp(0);
        $this->fakeSnapCreate();

        $response = $this->requestGateway(['channel' => 'auto', 'type' => 'dp'])->assertStatus(422);

        $this->assertStringContainsString('DP minimum belum diatur', (string) $response->json('error'));
        $this->assertSame(0, Payment::where('booking_id', $this->booking->id)->count());
    }

    public function test_type_gateway_asing_ditolak_dengan_pesan_bahasa_indonesia(): void
    {
        $this->fakeSnapCreate();

        $this->requestGateway(['channel' => 'auto', 'type' => 'cicilan'])->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_tagihan_gateway_menggantikan_tautan_pending_sebelumnya(): void
    {
        // Kasir membuat QR, tamu tidak membayarnya, kasir membuat tagihan baru: tidak boleh
        // ada dua tautan bayar hidup untuk tagihan yang sama.
        $this->setMinDp(30);
        $this->fakeSnapCreate();

        $first  = Payment::find($this->requestGateway(['channel' => 'auto'])->assertStatus(201)->json('data.id'));
        $second = Payment::find($this->requestGateway(['channel' => 'auto'])->assertStatus(201)->json('data.id'));

        $this->assertSame(PaymentStatus::Expired->value, (string) $first->fresh()->status->value);
        $this->assertStringContainsString('Digantikan tagihan gateway baru', (string) $first->fresh()->notes);
        $this->assertSame(PaymentStatus::Pending->value, (string) $second->fresh()->status->value);

        // Nominal tetap dihitung dari sisa yang benar, bukan dari total mentah
        $this->assertEqualsWithDelta($this->total, (float) $second->amount, 0.01);
    }

    public function test_gateway_ditolak_saat_ada_pembayaran_manual_menunggu_verifikasi(): void
    {
        // Guard yang sama sudah berlaku untuk pembayaran manual; tanpa ini di jalur gateway,
        // uang yang sama bisa ditagih dua kali (transfer pending + QR aktif).
        Payment::create([
            'booking_id'       => $this->booking->id,
            'invoice_id'       => $this->booking->invoice_id,
            'created_by'       => $this->admin->id,
            'method'           => PaymentMethod::Transfer->value,
            'type'             => PaymentType::Dp->value,
            'status'           => PaymentStatus::Pending->value,
            'amount'           => 100000,
            'reference_number' => 'TRF-DP-PENDING',
        ]);

        $this->fakeSnapCreate();

        $response = $this->requestGateway(['channel' => 'auto'])->assertStatus(422);

        $this->assertStringContainsString('menunggu verifikasi admin', (string) $response->json('error'));
        $this->assertSame(1, Payment::where('booking_id', $this->booking->id)->count());
    }

    public function test_dp_manual_dihitung_sebagai_uang_masuk_sisa_gateway_ikut_mengecil(): void
    {
        // Tamu bayar DP tunai di meja, sisanya baru ditagih lewat Midtrans: gateway tidak boleh
        // menagih seluruh tagihan lagi.
        $this->setMinDp(30);

        $dp = 100000;

        $this->actingAs($this->admin)->postJson("/api/v1/bookings/{$this->booking->id}/payments", [
            'amount' => $dp,
            'method' => PaymentMethod::Cash->value,
            'type'   => PaymentType::Dp->value,
        ])->assertStatus(201);

        $booking = Booking::with('invoice')->find($this->booking->id);

        $this->assertSame(BookingStatus::Confirmed->value, (string) $booking->status->value);
        $this->assertEqualsWithDelta(round($this->total - $dp, 2), (float) $booking->remaining_due, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $booking->dp_shortfall, 0.01);

        $this->fakeSnapCreate();

        $final = Payment::find($this->requestGateway(['channel' => 'auto'])->assertStatus(201)->json('data.id'));

        $this->assertEqualsWithDelta(round($this->total - $dp, 2), (float) $final->amount, 0.01);
        $this->assertSame(PaymentType::Final->value, (string) $final->type->value);

        // Pelunasan datang dari gateway -> invoice lunas
        $this->postJson('/api/webhooks/payment-gateway', $this->settlement($final, (float) $final->amount))
            ->assertStatus(200)
            ->assertJsonPath('status', 'processed');

        $this->assertSame(InvoiceStatus::Paid->value, (string) Booking::find($this->booking->id)->invoice->status->value);
    }

    public function test_tagihan_yang_sudah_lunas_tidak_bisa_ditagih_lagi(): void
    {
        // Setelah seluruh tagihan lunas, tidak ada jalur penagihan baru - termasuk "DP".
        $this->fakeSnapCreate();

        $payment = Payment::find($this->requestGateway(['channel' => 'auto'])->assertStatus(201)->json('data.id'));

        $this->postJson('/api/webhooks/payment-gateway', $this->settlement($payment, (float) $payment->amount))
            ->assertStatus(200)
            ->assertJsonPath('status', 'processed');

        $this->fakeSnapCreate();

        $this->requestGateway(['channel' => 'auto'])->assertStatus(422)
            ->assertJsonFragment(['error' => 'Invoice sudah lunas.']);
    }

    public function test_penagihan_baru_ditolak_sisa_sudah_habis_oleh_pembayaran_verifikasi_manual(): void
    {
        // Sisa nol tapi invoice belum sempat ditandai lunas (mis. pembayaran pending menunggu
        // admin): tetap jangan menagih Rp 0 ke Midtrans.
        Payment::create([
            'booking_id'       => $this->booking->id,
            'invoice_id'       => $this->booking->invoice_id,
            'created_by'       => $this->admin->id,
            'method'           => PaymentMethod::Transfer->value,
            'type'             => PaymentType::Final->value,
            'status'           => PaymentStatus::Pending->value,
            'amount'           => $this->total,
            'reference_number' => 'TRF-DP-SISA-HABIS',
        ]);

        $this->fakeSnapCreate();

        $this->requestGateway(['channel' => 'auto'])->assertStatus(422)
            ->assertJsonFragment(['error' => 'Masih ada pembayaran manual menunggu verifikasi admin (TRF-DP-SISA-HABIS). Verifikasi atau tolak pembayaran itu sebelum menagih lewat gateway.']);
    }
}
