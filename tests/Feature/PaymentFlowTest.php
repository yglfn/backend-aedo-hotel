<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
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
 * Alur bayar vs check-in: tidak menagih ulang pembayaran yang masih menunggu
 * verifikasi, dan check-in hanya untuk reservasi confirmed.
 */
class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $pegawai;
    protected Customer $customer;
    protected float $invoiceTotal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        $this->admin   = User::where('role', UserRole::Admin)->first();
        $this->pegawai = User::where('role', UserRole::Pegawai)->first();

        $this->customer = Customer::create(['name' => 'Tamu Alur Bayar', 'phone' => '081234500001']);
    }

    /**
     * Booking 1 kamar @500.000 x 2 malam -> subtotal 1.000.000, pajak 11% -> 1.110.000.
     */
    protected function makeBooking(string $roomNumber = 'FLOW1'): Booking
    {
        $roomType = RoomType::create(['name' => 'Flow Room', 'price' => 500000, 'max_person' => 2]);
        $room     = Room::create(['room_type_id' => $roomType->id, 'room_number' => $roomNumber]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/bookings', [
            'customer_id' => $this->customer->id,
            'source'      => 'walk_in',
            'check_in'    => now()->format('Y-m-d'),
            'check_out'   => now()->addDays(2)->format('Y-m-d'),
            'guest_count' => 2,
            'rooms'       => [$room->id],
            // Check-in kini menuntut nama seluruh penghuni (Model C), jadi tamu kedua ikut dicatat
            'guests'      => [
                ['name' => 'Tamu Alur Bayar', 'is_primary' => true],
                ['name' => 'Tamu Pendamping', 'is_primary' => false],
            ],
        ]);

        $response->assertStatus(201);

        $booking = Booking::find($response->json('data.id'));
        $this->invoiceTotal = (float) $booking->invoice->total;

        return $booking;
    }

    protected function pay(Booking $booking, float $amount, string $method, string $type = 'dp', ?string $reference = null)
    {
        return $this->actingAs($this->admin)->postJson("/api/v1/bookings/{$booking->id}/payments", array_filter([
            'amount'           => $amount,
            'method'           => $method,
            'type'             => $type,
            'reference_number' => $reference,
        ], fn ($value) => $value !== null));
    }

    public function test_invoice_total_is_tax_inclusive_baseline(): void
    {
        $booking = $this->makeBooking();

        $this->assertSame(1110000.0, $this->invoiceTotal);
        $this->assertSame(BookingStatus::Pending->value, $booking->status->value);
    }

    public function test_booking_payload_exposes_shared_payment_summary(): void
    {
        $booking = $this->makeBooking();

        // Transfer DP 500rb masuk tapi belum diverifikasi admin
        $this->pay($booking, 500000, PaymentMethod::Transfer->value, 'dp', 'TRX-FLOW-01')->assertStatus(201);

        $data = $this->actingAs($this->admin)
            ->getJson('/api/v1/bookings?search=' . urlencode($booking->booking_code))
            ->assertStatus(200)
            ->json('data.data.0');

        $this->assertNotNull($data, 'Booking harus ketemu lewat pencarian kode booking');

        $this->assertSame(0.0, (float) $data['paid_verified']);
        $this->assertSame(500000.0, (float) $data['paid_pending']);
        $this->assertSame(1110000.0, (float) $data['invoice_total']);
        // inilah intinya: sisa harus 610rb, bukan 1.110rb seperti perhitungan lama di UI
        $this->assertSame(610000.0, (float) $data['remaining_due']);
        $this->assertTrue((bool) $data['has_pending_payment']);
        $this->assertTrue((bool) $data['blocks_manual_payment']);
    }

    public function test_pending_manual_payment_blocks_collecting_again(): void
    {
        $booking = $this->makeBooking();

        $this->pay($booking, 500000, PaymentMethod::Transfer->value, 'dp', 'TRX-FLOW-02')->assertStatus(201);

        // Staf meja mencoba menagih pelunasan (type berbeda) -> harus ditolak, bukan didata
        $this->pay($booking, 610000, PaymentMethod::Cash->value, 'final')
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Masih ada pembayaran menunggu verifikasi admin (TRX-FLOW-02 sebesar Rp 500.000). Verifikasi atau tolak pembayaran itu sebelum mencatat pembayaran baru.']);

        $this->assertSame(1, Payment::where('booking_id', $booking->id)->count());
    }

    public function test_checkin_requires_confirmed_booking(): void
    {
        $booking = $this->makeBooking();

        // Belum ada pembayaran sama sekali
        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$booking->id}/check-in")
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Reservasi belum terkonfirmasi. Lunasi/verifikasi pembayaran dulu, atau minta admin mengonfirmasi reservasi.']);

        // Transfer DP menunggu verifikasi: masih belum boleh check-in
        $paymentId = $this->pay($booking, 600000, PaymentMethod::Transfer->value, 'dp', 'TRX-FLOW-03')
            ->assertStatus(201)->json('data.id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$booking->id}/check-in")
            ->assertStatus(422);

        // Admin memverifikasi -> booking otomatis confirmed (min DP 0) -> check-in lolos
        $this->actingAs($this->admin)->patchJson("/api/v1/payments/{$paymentId}/verify")->assertStatus(200);

        $this->assertSame(BookingStatus::Confirmed->value, $booking->fresh()->status->value);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$booking->id}/check-in")
            ->assertStatus(200);
    }

    public function test_confirm_endpoint_is_the_official_way_for_credit_and_is_audited(): void
    {
        $booking = $this->makeBooking('FLOW2');

        // Traveloka: dana tidak lewat sistem, pegawai konfirmasi dengan alasan
        $this->actingAs($this->pegawai)
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", ['reason' => 'Dibayar ke hotel via Traveloka'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', BookingStatus::Confirmed->value);

        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'booking.confirmed',
            'auditable_type' => Booking::class,
            'auditable_id'   => $booking->id,
        ]);

        // Konfirmasi kedua kali ditolak karena sudah bukan pending
        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$booking->id}/confirm")
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Hanya reservasi berstatus pending yang bisa dikonfirmasi.']);
    }

    public function test_min_dp_percentage_is_enforced_for_pegawai_and_skipped_by_admin(): void
    {
        Setting::first()->update(['min_dp_percentage' => 50]);

        $booking = $this->makeBooking('FLOW3');

        // Ambang 50% x 1.110.000 = 555.000; DP 500.000 belum cukup
        $this->actingAs($this->pegawai)
            ->postJson("/api/v1/bookings/{$booking->id}/confirm")
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'DP terverifikasi belum mencapai minimal 50% tagihan. Butuh Rp 555.000, tercatat Rp 0.']);

        // Admin boleh melewati ambang (mis. tamu VIP / kredit hotel) dan itu terekam di audit
        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", ['reason' => 'Kredit disetujui owner'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', BookingStatus::Confirmed->value);
    }

    public function test_cash_dp_below_minimum_does_not_autoconfirm(): void
    {
        Setting::first()->update(['min_dp_percentage' => 50]);
        $booking = $this->makeBooking('FLOW4');

        // Tunai langsung verified tapi di bawah ambang -> booking tetap pending
        $this->pay($booking, 500000, PaymentMethod::Cash->value)->assertStatus(201);
        $this->assertSame(BookingStatus::Pending->value, $booking->fresh()->status->value);

        // Pelunasan sebagian lagi -> total verified 600.000 melewati ambang -> confirmed
        $this->pay($booking, 100000, PaymentMethod::Cash->value, 'final')->assertStatus(201);
        $this->assertSame(BookingStatus::Confirmed->value, $booking->fresh()->status->value);
    }
}
