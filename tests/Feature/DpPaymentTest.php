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
use Tests\TestCase;

/**
 * Uang muka (DP) harus benar-benar berfungsi, bukan cuma label di layar reservasi.
 *
 * Yang dijaga di sini:
 *  - ambang DP dihitung di SATU tempat (Booking) dan dipakai backend maupun frontend;
 *  - DP boleh dibayar SEBAGIAN, tidak selalu seluruh sisa tagihan;
 *  - DP terverifikasi mengonfirmasi reservasi walau invoice belum lunas;
 *  - nominal tidak bisa melewati sisa tagihan, dan tidak ada dua klaim pembayaran
 *    hidup sekaligus untuk tagihan yang sama.
 */
class DpPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Booking $booking;

    /** Tagihan booking fixture = 300.000 + 11% = 333.000 */
    protected float $total;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        $this->admin = User::where('role', UserRole::Admin->value)->first();

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

    /**
     * Catat pembayaran lewat jalur meja (tunai langsung terverifikasi,
     * transfer/QRIS menunggu verifikasi admin).
     */
    protected function pay(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/payments", $body);
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
     * Layar reservasi membaca ambang DP dari /api/v1/settings untuk menampilkan besarnya
     * uang muka. Field ini hilang dari daftar baca publik = DP tidak pernah ditawarkan,
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

    // ------------------------------------------------------------------ DP di meja

    public function test_dp_tunai_mengonfirmasikan_booking_walau_invoice_belum_lunas(): void
    {
        // Ini inti fitur DP: kamar terkunci oleh uang muka, bukan oleh pelunasan.
        $this->setMinDp(30);

        $expected = (float) Booking::find($this->booking->id)->dp_min_amount;
        $this->assertGreaterThan(0, $expected);

        // Sebelum ada uang masuk: reservasi masih menunggu.
        $this->assertSame(BookingStatus::Pending->value, (string) $this->booking->fresh()->status->value);

        $paymentId = $this->pay([
            'amount' => $expected,
            'method' => PaymentMethod::Cash->value,
            'type'   => PaymentType::Dp->value,
        ])->assertStatus(201)->json('data.id');

        $booking = Booking::with('invoice')->find($this->booking->id);
        $payment = Payment::find($paymentId);

        $this->assertSame(PaymentType::Dp->value, (string) $payment->type->value);
        $this->assertEqualsWithDelta($expected, (float) $payment->amount, 0.01);
        $this->assertSame(PaymentStatus::Verified->value, (string) $payment->status->value);
        $this->assertSame(BookingStatus::Confirmed->value, (string) $booking->status->value);
        $this->assertNotSame(InvoiceStatus::Paid->value, (string) $booking->invoice->status->value);
        $this->assertGreaterThan(0, (float) $booking->remaining_due);
        $this->assertEqualsWithDelta(0.0, (float) $booking->dp_shortfall, 0.01);
    }

    public function test_dp_di_bawah_ambang_tidak_mengonfirmasikan_booking(): void
    {
        // Uang masuk tapi belum mencapai ambang: booking jangan ikut terkunci 'confirmed',
        // kalau tidak check-in jadi lolos tanpa uang muka yang benar.
        $this->setMinDp(60);

        $paymentId = $this->pay([
            'amount' => 50000,
            'method' => PaymentMethod::Cash->value,
            'type'   => PaymentType::Dp->value,
        ])->assertStatus(201)->json('data.id');

        $booking = Booking::find($this->booking->id);

        $this->assertSame(PaymentStatus::Verified->value, (string) Payment::find($paymentId)->status->value);
        $this->assertSame(BookingStatus::Pending->value, (string) $booking->status->value);
        $this->assertGreaterThan(0, (float) $booking->dp_shortfall);
    }

    public function test_dp_sebagian_yang_dibayar_di_bawah_ambang_bisa_dilunasi_bertahap(): void
    {
        // Tamu bayar DP tunai di meja, sisanya menyusul: sisa dihitung dari tagihan nyata,
        // bukan dari estimasi layar, dan DP tidak ditagih ulang.
        $this->setMinDp(30);

        $dp = 100000;

        $this->pay([
            'amount' => $dp,
            'method' => PaymentMethod::Cash->value,
            'type'   => PaymentType::Dp->value,
        ])->assertStatus(201);

        $booking = Booking::with('invoice')->find($this->booking->id);

        $this->assertSame(BookingStatus::Confirmed->value, (string) $booking->status->value);
        $this->assertEqualsWithDelta(round($this->total - $dp, 2), (float) $booking->remaining_due, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $booking->dp_shortfall, 0.01);

        // Pelunasan sisa -> invoice benar-benar lunas
        $this->pay([
            'amount' => round($this->total - $dp, 2),
            'method' => PaymentMethod::Cash->value,
            'type'   => PaymentType::Final->value,
        ])->assertStatus(201);

        $this->assertSame(
            InvoiceStatus::Paid->value,
            (string) Booking::find($this->booking->id)->invoice->status->value
        );
    }

    // ------------------------------------------------------------------ penjaga

    public function test_nominal_melebihi_sisa_tagihan_ditolak(): void
    {
        $this->setMinDp(30);

        $response = $this->pay([
            'amount' => $this->total + 100000,
            'method' => PaymentMethod::Cash->value,
            'type'   => PaymentType::Dp->value,
        ])->assertStatus(422);

        $this->assertStringContainsString('melebihi sisa tagihan', (string) $response->json('error'));
        $this->assertSame(0, Payment::where('booking_id', $this->booking->id)->count());
    }

    public function test_pembayaran_baru_ditolak_saat_ada_klaim_menunggu_verifikasi(): void
    {
        // Transfer masih menunggu admin; kalau jalur lain boleh terus mencatat, uang yang
        // sama bisa ditagih dua kali.
        $this->setMinDp(30);

        $this->pay([
            'amount'           => 100000,
            'method'           => PaymentMethod::Transfer->value,
            'type'             => PaymentType::Dp->value,
            'reference_number' => 'TRF-DP-PENDING',
        ])->assertStatus(201);

        $response = $this->pay([
            'amount' => 50000,
            'method' => PaymentMethod::Cash->value,
            'type'   => PaymentType::Final->value,
        ])->assertStatus(422);

        $this->assertStringContainsString('menunggu verifikasi admin', (string) $response->json('error'));
        $this->assertSame(1, Payment::where('booking_id', $this->booking->id)->count());
    }

    public function test_tagihan_yang_sudah_lunas_tidak_bisa_dibayar_lagi(): void
    {
        // Setelah seluruh tagihan lunas, tidak ada jalur penagihan baru - termasuk "DP".
        $this->pay([
            'amount' => $this->total,
            'method' => PaymentMethod::Cash->value,
            'type'   => PaymentType::Final->value,
        ])->assertStatus(201);

        $this->assertSame(
            InvoiceStatus::Paid->value,
            (string) Booking::find($this->booking->id)->invoice->status->value
        );

        $this->pay([
            'amount' => 50000,
            'method' => PaymentMethod::Cash->value,
            'type'   => PaymentType::Dp->value,
        ])->assertStatus(422);

        $this->assertSame(1, Payment::where('booking_id', $this->booking->id)->count());
    }

    public function test_penagihan_baru_ditolak_sisa_sudah_habis_oleh_pembayaran_pending(): void
    {
        // Sisa nol tapi invoice belum sempat ditandai lunas (pembayaran masih menunggu
        // admin): jangan ada catatan pembayaran Rp 0 / dobel.
        $this->pay([
            'amount'           => $this->total,
            'method'           => PaymentMethod::Transfer->value,
            'type'             => PaymentType::Final->value,
            'reference_number' => 'TRF-DP-SISA-HABIS',
        ])->assertStatus(201);

        $response = $this->pay([
            'amount' => 10000,
            'method' => PaymentMethod::Cash->value,
            'type'   => PaymentType::Final->value,
        ])->assertStatus(422);

        $this->assertStringContainsString('menunggu verifikasi admin', (string) $response->json('error'));
        $this->assertSame(1, Payment::where('booking_id', $this->booking->id)->count());
    }

    public function test_metode_gateway_ditolak_validasi_enum(): void
    {
        // Payment gateway sudah dihentikan: nilainya bukan lagi metode yang sah, jadi
        // tidak bisa masuk lewat API pembayaran meja.
        $response = $this->pay([
            'amount' => 50000,
            'method' => 'payment_gateway',
            'type'   => PaymentType::Dp->value,
        ])->assertStatus(422);

        $response->assertJsonValidationErrors(['method']);
        $this->assertSame(0, Payment::where('booking_id', $this->booking->id)->count());
    }
}
