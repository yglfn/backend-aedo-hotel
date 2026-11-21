<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saklar verifikasi pembayaran:
 * - aktif   : transfer/QRIS pending sampai admin memverifikasi.
 * - nonaktif: transfer/QRIS langsung verified (lunas) tanpa upload bukti.
 */
class PaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $pegawai;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        $this->admin    = User::where('role', UserRole::Admin)->first();
        $this->pegawai  = User::where('role', UserRole::Pegawai)->first();
        $this->customer = Customer::create(['name' => 'Tamu Verifikasi', 'phone' => '081235550001']);
    }

    /** Booking 1 kamar, default 1 malam @300.000 -> invoice 333.000 (pajak 11%). */
    protected function makeBooking(string $roomNumber = 'VER1', float $price = 300000, int $nights = 1): Booking
    {
        $roomType = RoomType::create(['name' => 'Verify Room ' . $roomNumber, 'price' => $price, 'max_person' => 2]);
        $room     = Room::create(['room_type_id' => $roomType->id, 'room_number' => $roomNumber]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/bookings', [
            'customer_id' => $this->customer->id,
            'source'      => 'walk_in',
            'check_in'    => now()->format('Y-m-d'),
            'check_out'   => now()->addDays($nights)->format('Y-m-d'),
            'guest_count' => 1,
            'rooms'       => [$room->id],
            'guests'      => [['name' => 'Tamu Verifikasi', 'is_primary' => true]],
        ]);

        $response->assertStatus(201);

        return Booking::find($response->json('data.id'));
    }

    protected function recordTransfer(Booking $booking, float $amount, string $method = 'transfer')
    {
        return $this->actingAs($this->admin)->postJson("/api/v1/bookings/{$booking->id}/payments", [
            'amount' => $amount,
            'method' => $method,
            'type'   => 'final',
        ]);
    }

    public function test_settings_default_to_verification_enabled(): void
    {
        $this->assertTrue((bool) Setting::first()->payment_verification_enabled);
    }

    public function test_transfer_stays_pending_while_verification_is_enabled(): void
    {
        $booking = $this->makeBooking();

        $paymentId = $this->recordTransfer($booking, 333000)->assertStatus(201)
            ->assertJsonPath('data.status', PaymentStatus::Pending->value)
            ->json('data.id');

        $this->assertSame(InvoiceStatus::Draft->value, $booking->invoice->fresh()->status->value);

        // Admin tetap bisa memverifikasi manual
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/payments/{$paymentId}/verify")
            ->assertStatus(200)
            ->assertJsonPath('data.status', PaymentStatus::Verified->value);

        $this->assertSame(BookingStatus::Confirmed->value, $booking->fresh()->status->value);
        $this->assertSame(InvoiceStatus::Paid->value, $booking->invoice->fresh()->status->value);
    }

    public function test_disabling_verification_settles_transfer_immediately_without_proof(): void
    {
        Setting::first()->update(['payment_verification_enabled' => false]);

        $booking = $this->makeBooking();

        $paymentId = $this->recordTransfer($booking, 333000)->assertStatus(201)
            ->assertJsonPath('data.status', PaymentStatus::Verified->value)
            ->json('data.id');

        $payment = Payment::find($paymentId);

        // Penanda "sistem yang melunasi": verified_by kosong, catatan & audit terisi
        $this->assertNull($payment->verified_by);
        $this->assertNull($payment->proof_of_payment);
        $this->assertStringContainsString('verifikasi pembayaran nonaktif', (string) $payment->notes);
        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'payment.auto_verified',
            'auditable_type' => Payment::class,
            'auditable_id'   => $paymentId,
        ]);

        $this->assertSame(BookingStatus::Confirmed->value, $booking->fresh()->status->value);
        $this->assertSame(InvoiceStatus::Paid->value, $booking->invoice->fresh()->status->value);
    }

    public function test_qris_is_settled_immediately_when_verification_disabled(): void
    {
        Setting::first()->update(['payment_verification_enabled' => false]);

        $booking = $this->makeBooking('VER2');

        $this->recordTransfer($booking, 333000, PaymentMethod::Qris->value)
            ->assertStatus(201)
            ->assertJsonPath('data.status', PaymentStatus::Verified->value);

        $this->assertSame(InvoiceStatus::Paid->value, $booking->invoice->fresh()->status->value);
    }

    public function test_cash_is_always_verified(): void
    {
        $booking = $this->makeBooking('VER3');

        $this->actingAs($this->admin)->postJson("/api/v1/bookings/{$booking->id}/payments", [
            'amount' => 333000,
            'method' => PaymentMethod::Cash->value,
            'type'   => 'final',
        ])->assertStatus(201)->assertJsonPath('data.status', PaymentStatus::Verified->value);
    }

    public function test_rejecting_pending_payment_releases_the_block(): void
    {
        $booking = $this->makeBooking('VER4');

        $paymentId = $this->recordTransfer($booking, 333000)->assertStatus(201)->json('data.id');

        // Pending memblokir pencatatan baru
        $this->recordTransfer($booking, 100000)->assertStatus(422);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/payments/{$paymentId}/reject", ['reason' => 'Dana tidak masuk'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', PaymentStatus::Failed->value);

        $this->actingAs($this->admin)->postJson("/api/v1/bookings/{$booking->id}/payments", [
            'amount' => 333000,
            'method' => PaymentMethod::Cash->value,
            'type'   => 'final',
        ])->assertStatus(201);
    }

    public function test_admin_can_reverse_a_settled_transfer_and_pegawai_cannot(): void
    {
        Setting::first()->update(['payment_verification_enabled' => false]);

        $booking   = $this->makeBooking('VER5');
        $paymentId = $this->recordTransfer($booking, 333000)->assertStatus(201)->json('data.id');

        $this->actingAs($this->pegawai)
            ->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'Coba-coba'])
            ->assertStatus(403);

        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$paymentId}/reverse")->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'Mutasi bank tidak ditemukan'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', PaymentStatus::Failed->value);

        $invoice = Invoice::where('booking_id', $booking->id)->first();
        $this->assertSame(InvoiceStatus::Draft->value, $invoice->status->value);

        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'payment.reversed',
            'auditable_type' => Payment::class,
            'auditable_id'   => $paymentId,
        ]);
    }

    public function test_checkin_and_checkout_finish_without_verification(): void
    {
        Setting::first()->update(['payment_verification_enabled' => false]);

        $this->actingAs($this->pegawai)->postJson('/api/v1/shifts/start')->assertSuccessful();

        $booking = $this->makeBooking('VER6');

        $this->actingAs($this->pegawai)
            ->postJson("/api/v1/bookings/{$booking->id}/payments", [
                'amount' => 333000,
                'method' => PaymentMethod::Transfer->value,
                'type'   => 'final',
            ])->assertStatus(201)->assertJsonPath('data.status', PaymentStatus::Verified->value);

        $this->assertSame(BookingStatus::Confirmed->value, $booking->fresh()->status->value);

        $this->actingAs($this->pegawai)
            ->postJson("/api/v1/bookings/{$booking->id}/check-in")->assertStatus(200);

        $this->actingAs($this->pegawai)
            ->postJson("/api/v1/bookings/{$booking->id}/check-out")->assertStatus(200);

        $this->assertSame(BookingStatus::CheckedOut->value, $booking->fresh()->status->value);
    }

    public function test_admin_can_toggle_verification_via_settings_endpoint(): void
    {
        $this->actingAs($this->admin)->putJson('/api/v1/settings', [
            'hotel_name'                   => 'Aedo Hotel Syariah',
            'hotel_address'                => 'Jl. Contoh',
            'hotel_phone'                  => '0812',
            'default_check_in'             => '14:00:00',
            'default_check_out'            => '12:00:00',
            'tax_percentage'               => 11,
            'min_dp_percentage'            => 0,
            'payment_verification_enabled' => false,
        ])->assertStatus(200)
            ->assertJsonPath('data.payment_verification_enabled', false);

        $this->assertFalse((bool) Setting::first()->payment_verification_enabled);
    }
}
