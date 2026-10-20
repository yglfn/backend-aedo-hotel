<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penutup celah yang ditemukan saat analisis sistem:
 * kebocoran secret, otorisasi role, wajib shift, invoice void saat batal, dan daftar pembayaran.
 */
class SystemHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $pegawai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        $this->admin   = User::where('role', UserRole::Admin)->first();
        $this->pegawai = User::where('role', UserRole::Pegawai)->first();
    }

    /**
     * Buat booking siap pakai lewat endpoint reservasi.
     */
    protected function makeBooking(string $roomNumber = 'HRD1', float $price = 200000): array
    {
        $customer = Customer::create(['name' => 'Tamu Hardening', 'phone' => '081230000001']);
        $roomType = RoomType::create(['name' => 'Hardening Room', 'price' => $price, 'max_person' => 2]);
        $room     = Room::create(['room_type_id' => $roomType->id, 'room_number' => $roomNumber]);

        // Tanpa header Referer: request stateful mengganti guard default ke 'sanctum' sehingga
        // actingAs() berikutnya pada user lain tidak lagi dikenali di dalam satu test.
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/bookings', [
                'customer_id' => $customer->id,
                'source'      => 'walk_in',
                'check_in'    => now()->format('Y-m-d'),
                'check_out'   => now()->addDays(2)->format('Y-m-d'),
                'guest_count' => 1,
                'rooms'       => [$room->id],
                'guests'      => [['name' => 'Tamu Hardening', 'is_primary' => true]],
            ]);

        $response->assertStatus(201);

        return [
            'booking_id'  => $response->json('data.id'),
            'customer_id' => $customer->id,
        ];
    }

    public function test_public_settings_only_expose_branding_and_business_fields(): void
    {
        $response = $this->getJson('/api/v1/settings')->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayHasKey('hotel_name', $data);
        $this->assertArrayHasKey('tax_percentage', $data);

        // Endpoint ini tanpa autentikasi: tidak boleh ada kredensial apa pun yang ikut terkirim.
        $leaked = array_filter(
            array_keys($data),
            fn (string $key) => str_contains($key, 'secret')
                || str_contains($key, 'key')
                || str_contains($key, 'password')
                || str_contains($key, 'token')
        );
        $this->assertSame([], $leaked, 'Respons publik membawa field kredensial: ' . implode(',', $leaked));
    }

    public function test_settings_change_is_audit_logged(): void
    {
        $this->actingAs($this->admin)->putJson('/api/v1/settings', [
            'hotel_name'        => 'Aedo Hotel Syariah',
            'default_check_in'  => '14:00:00',
            'default_check_out' => '12:00:00',
            'tax_percentage'    => 12,
        ])->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'settings.updated',
            'auditable_type' => \App\Models\Setting::class,
        ]);
    }

    public function test_pegawai_cannot_manage_rooms_or_services(): void
    {
        $this->actingAs($this->pegawai)->postJson('/api/v1/rooms', [
            'room_type_id' => RoomType::first()->id,
            'room_number'  => 'HACK99',
        ])->assertStatus(403);

        $this->actingAs($this->pegawai)->postJson('/api/v1/services', [
            'name'  => 'Layanan Ilegal',
            'price' => 10000,
            'unit'  => 'x',
        ])->assertStatus(403);

        // Tapi pegawai tetap boleh melihat & mengubah status kamar (AGENTS.md bagian 3)
        $this->actingAs($this->pegawai)->getJson('/api/v1/rooms')->assertStatus(200);
        $this->actingAs($this->pegawai)->getJson('/api/v1/services')->assertStatus(200);

        $room = Room::first();
        $this->actingAs($this->pegawai)
            ->patchJson("/api/v1/rooms/{$room->id}/status", ['status' => 'cleaning'])
            ->assertStatus(200);
    }

    public function test_pegawai_without_open_shift_cannot_record_payment(): void
    {
        $booking = $this->makeBooking()['booking_id'];

        $this->actingAs($this->pegawai)->postJson("/api/v1/bookings/{$booking}/payments", [
            'amount' => 100000,
            'method' => PaymentMethod::Cash->value,
            'type'   => 'dp',
        ])->assertStatus(403)->assertJsonPath('message', 'Anda harus memulai shift terlebih dahulu sebelum mencatat transaksi.');

        // Setelah shift berjalan, pembayaran boleh dicatat dan menempel ke shift itu
        $this->actingAs($this->pegawai)->postJson('/api/v1/shifts/start')->assertSuccessful();

        $response = $this->actingAs($this->pegawai)->postJson("/api/v1/bookings/{$booking}/payments", [
            'amount' => 100000,
            'method' => PaymentMethod::Cash->value,
            'type'   => 'dp',
        ])->assertStatus(201);

        $payment = Payment::find($response->json('data.id'));
        $this->assertNotNull($payment->shift_id);
        $this->assertSame(
            (int) $this->pegawai->currentShift()->id,
            (int) $payment->shift_id
        );
    }

    public function test_pegawai_can_list_payments_but_only_admin_verifies(): void
    {
        $booking = $this->makeBooking('HRD2')['booking_id'];

        // Transfer dicatat admin -> pending, butuh verifikasi admin
        $paymentId = $this->actingAs($this->admin)->postJson("/api/v1/bookings/{$booking}/payments", [
            'amount' => 150000,
            'method' => PaymentMethod::Transfer->value,
            'type'   => 'dp',
        ])->assertStatus(201)->json('data.id');

        // Daftar pembayaran bisa dibaca pegawai (sebelumnya memakai endpoint laporan admin-only)
        $this->actingAs($this->pegawai)->getJson('/api/v1/payments')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $paymentId)
            ->assertJsonStructure(['current_page', 'data', 'per_page', 'total']);

        // Tapi hanya admin yang boleh memverifikasi
        $this->actingAs($this->pegawai)->patchJson("/api/v1/payments/{$paymentId}/verify")->assertStatus(403);

        $this->actingAs($this->admin)->patchJson("/api/v1/payments/{$paymentId}/verify")
            ->assertStatus(200)
            ->assertJsonPath('data.status', PaymentStatus::Verified->value);

        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'payment.verified',
            'auditable_type' => Payment::class,
            'auditable_id'   => $paymentId,
        ]);
    }

    public function test_payments_index_supports_status_filter_and_search(): void
    {
        $first  = $this->makeBooking('HRD3', 250000);
        $second = $this->makeBooking('HRD4', 250000);

        $this->actingAs($this->admin)->postJson("/api/v1/bookings/{$first['booking_id']}/payments", [
            'amount'           => 50000,
            'method'           => PaymentMethod::Transfer->value,
            'type'             => 'dp',
            'reference_number' => 'TRF-HARDENING-01',
        ])->assertStatus(201);

        $this->actingAs($this->admin)->postJson("/api/v1/bookings/{$second['booking_id']}/payments", [
            'amount' => 500000,
            'method' => PaymentMethod::Cash->value,
            'type'   => 'final',
        ])->assertStatus(201);

        $this->actingAs($this->admin)->getJson('/api/v1/payments?status=pending')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference_number', 'TRF-HARDENING-01');

        $this->actingAs($this->admin)->getJson('/api/v1/payments?search=TRF-HARDENING')
            ->assertStatus(200)
            ->assertJsonPath('data.0.status', PaymentStatus::Pending->value);
    }

    public function test_cancel_voids_invoice_and_is_audit_logged(): void
    {
        $bookingId = $this->makeBooking('HRD5')['booking_id'];

        /** @var Invoice $invoice */
        $invoice = Invoice::where('booking_id', $bookingId)->firstOrFail();
        $this->assertNotSame(InvoiceStatus::Void->value, $invoice->status->value);

        $this->actingAs($this->admin)->postJson("/api/v1/bookings/{$bookingId}/cancel", [
            'cancel_reason' => 'Tamu membatalkan lewat telepon',
        ])->assertStatus(200);

        $this->assertSame(InvoiceStatus::Void->value, $invoice->fresh()->status->value);

        // Invoice aktif hilang, jadi booking tidak lagi dianggap punya piutang
        $this->assertNull(\App\Models\Booking::find($bookingId)->invoice);

        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'booking.cancelled',
            'auditable_type' => \App\Models\Booking::class,
            'auditable_id'   => $bookingId,
        ]);
    }

    public function test_service_delete_disables_instead_of_deleting_history(): void
    {
        $service = Service::create([
            'name'      => 'Laundry',
            'price'     => 25000,
            'unit'      => 'kg',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->deleteJson("/api/v1/services/{$service->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        // Baris tetap ada agar histori booking_services tidak yatim
        $this->assertDatabaseHas('services', ['id' => $service->id, 'is_active' => false]);

        $this->actingAs($this->admin)->patchJson("/api/v1/services/{$service->id}/activate")
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_customer_with_history_cannot_be_deleted(): void
    {
        $ids = $this->makeBooking('HRD6');

        $this->actingAs($this->admin)->deleteJson("/api/v1/customers/{$ids['customer_id']}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Data tamu tidak dapat dihapus karena memiliki 1 reservasi. Riwayat tamu harus disimpan untuk laporan dan audit.');

        $fresh = Customer::create(['name' => 'Tamu Baru', 'phone' => '081230000099']);
        $this->actingAs($this->admin)->deleteJson("/api/v1/customers/{$fresh->id}")->assertStatus(200);

        $this->assertDatabaseMissing('customers', ['id' => $fresh->id]);

        // Pegawai tidak boleh menghapus data tamu
        $other = Customer::create(['name' => 'Tamu Lain', 'phone' => '081230000100']);
        $this->actingAs($this->pegawai)->deleteJson("/api/v1/customers/{$other->id}")->assertStatus(403);
    }
}
