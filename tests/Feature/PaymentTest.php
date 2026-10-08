<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Room;
use App\Models\Customer;
use App\Models\RoomType;
use App\Models\Booking;
use App\Enums\UserRole;
use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        $this->admin = User::where('role', UserRole::Admin)->first();

        // Buat data booking lengkap
        $customer = Customer::create(['name' => 'Tamu Test', 'phone' => '08111111111']);
        $roomType = RoomType::create(['name' => 'Standard', 'price' => 500000, 'max_person' => 2]);
        $room = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'T1']);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/bookings', [
                'customer_id' => $customer->id,
                'source'      => 'walk_in',
                'check_in'    => now()->format('Y-m-d'),
                'check_out'   => now()->addDays(2)->format('Y-m-d'),
                'guest_count' => 1,
                'rooms'       => [$room->id],
                'guests'      => [['name' => 'Tamu Test', 'is_primary' => true]],
            ]);

        $this->booking = Booking::find($response->json('data.id'));
    }

    public function test_invoice_created_automatically_with_booking(): void
    {
        // Invoice harus sudah otomatis dibuat saat booking dibuat
        $this->assertDatabaseHas('invoices', [
            'booking_id' => $this->booking->id,
            'status'     => InvoiceStatus::Draft->value,
        ]);
        // Pastikan total > 0 (pajak bervariasi tergantung settings seeder)
        $invoice = $this->booking->invoice;
        $this->assertGreaterThan(0, (float) $invoice->total);
    }

    public function test_can_record_cash_payment(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/payments", [
                'amount' => 1000000,
                'method' => 'cash',
                'type'   => 'final',
            ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', PaymentStatus::Verified->value);

        // Pembayaran tunai langsung verified
        $this->assertDatabaseHas('payments', [
            'booking_id' => $this->booking->id,
            'amount'     => 1000000,
            'method'     => 'cash',
            'status'     => PaymentStatus::Verified->value,
        ]);
    }

    public function test_invoice_marked_paid_after_full_payment(): void
    {
        // Bayar penuh = bayar total invoice (berapapun totalnya termasuk pajak)
        $invoice = $this->booking->invoice;
        $total   = (float) $invoice->total;

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/payments", [
                'amount' => $total,
                'method' => 'cash',
                'type'   => 'final',
            ]);

        // Invoice harus otomatis berubah menjadi paid
        $this->assertDatabaseHas('invoices', [
            'booking_id' => $this->booking->id,
            'status'     => InvoiceStatus::Paid->value,
        ]);
    }

    public function test_transfer_payment_is_pending_until_verified(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/payments", [
                'amount'           => 500000,
                'method'           => 'transfer',
                'type'             => 'dp',
                'reference_number' => 'TRF-12345',
            ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', PaymentStatus::Pending->value);
    }

    public function test_admin_can_verify_transfer_payment(): void
    {
        // Buat payment transfer sebesar total invoice
        $invoice    = $this->booking->invoice;
        $paymentResp = $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/payments", [
                'amount' => (float) $invoice->total,
                'method' => 'transfer',
                'type'   => 'final',
            ]);

        $paymentId = $paymentResp->json('data.id');

        // Admin verifikasi
        $verifyResp = $this->actingAs($this->admin)
            ->patchJson("/api/v1/payments/{$paymentId}/verify");

        $verifyResp->assertStatus(200)
                   ->assertJsonPath('data.status', PaymentStatus::Verified->value);

        // Invoice harus otomatis paid
        $this->assertDatabaseHas('invoices', [
            'booking_id' => $this->booking->id,
            'status'     => InvoiceStatus::Paid->value,
        ]);
    }

    public function test_cannot_overpay_invoice(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/payments", [
                'amount' => 9999999, // Lebih dari total tagihan 1.000.000
                'method' => 'cash',
                'type'   => 'final',
            ]);

        $response->assertStatus(422)
                 ->assertJsonStructure(['error']);
    }

    public function test_cannot_checkout_with_unpaid_invoice(): void
    {
        // Ubah status booking menjadi checked_in dulu
        $this->booking->update(['status' => 'checked_in']);
        // Jangan bayar apapun

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/check-out");

        $response->assertStatus(422)
                 ->assertJsonStructure(['error']);
    }
}
