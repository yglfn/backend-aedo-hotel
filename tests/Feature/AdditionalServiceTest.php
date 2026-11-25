<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdditionalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed');
        Setting::first()->update(['tax_percentage' => 0]);
    }

    public function test_can_create_booking_with_additional_services(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $service = $this->makeService('Laundry', 25000);
        $booking = $this->makeBooking($admin, [
            'services' => [['service_id' => $service->id, 'qty' => 2]],
        ]);

        $this->assertDatabaseHas('booking_services', [
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'quantity' => 2,
            'price_at_transaction' => 25000,
            'total' => 50000,
        ]);
        $this->assertEqualsWithDelta(250000.0, (float) $booking->invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(250000.0, (float) $booking->total_price, 0.01);
    }

    public function test_add_services_accepts_quantity_alias_and_notes(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $service = $this->makeService('Sarapan', 35000);
        $booking = $this->makeBooking($admin);

        $this->actingAs($admin)
            ->postJson("/api/v1/bookings/{$booking->id}/services", [
                'services' => [[
                    'service_id' => $service->id,
                    'quantity' => 2,
                    'notes' => 'Untuk dua orang',
                ]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.booking_services.0.quantity', 2)
            ->assertJsonPath('data.booking_services.0.notes', 'Untuk dua orang');

        $this->assertDatabaseHas('booking_services', [
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'quantity' => 2,
            'total' => 70000,
        ]);
    }

    public function test_adding_services_updates_invoice_booking_total_and_audit(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $service = $this->makeService('Spa', 40000);
        $booking = $this->makeBooking($admin);

        $this->actingAs($admin)
            ->postJson("/api/v1/bookings/{$booking->id}/services", [
                'services' => [['service_id' => $service->id, 'qty' => 3]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.invoice.subtotal', '320000.00')
            ->assertJsonPath('data.total_price', '320000.00');

        $this->assertDatabaseHas('invoices', [
            'booking_id' => $booking->id,
            'subtotal' => 320000,
            'total' => 320000,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'booking.services_added',
            'auditable_type' => Booking::class,
            'auditable_id' => $booking->id,
        ]);
    }

    public function test_new_service_price_does_not_reprice_an_existing_snapshot(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $service = $this->makeService('Laundry', 25000);
        $booking = $this->makeBooking($admin, [
            'services' => [['service_id' => $service->id, 'qty' => 1]],
        ]);

        $service->update(['price' => 40000]);

        $this->actingAs($admin)
            ->postJson("/api/v1/bookings/{$booking->id}/services", [
                'services' => [['service_id' => $service->id, 'qty' => 2]],
            ])
            ->assertStatus(200);

        $rows = DB::table('booking_services')
            ->where('booking_id', $booking->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(25000.0, (float) $rows[0]->price_at_transaction, 0.01);
        $this->assertEqualsWithDelta(25000.0, (float) $rows[0]->total, 0.01);
        $this->assertEqualsWithDelta(40000.0, (float) $rows[1]->price_at_transaction, 0.01);
        $this->assertEqualsWithDelta(80000.0, (float) $rows[1]->total, 0.01);
        $this->assertEqualsWithDelta(305000.0, (float) $booking->fresh()->invoice->subtotal, 0.01);
    }

    public function test_adding_service_reopens_a_paid_invoice_when_total_increases(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $service = $this->makeService('Breakfast', 35000);
        $booking = $this->makeBooking($admin);
        $total = (float) $booking->invoice->total;

        $this->actingAs($admin)
            ->postJson("/api/v1/bookings/{$booking->id}/payments", [
                'amount' => $total,
                'method' => 'cash',
                'type' => 'final',
            ])
            ->assertStatus(201);

        $this->assertSame(InvoiceStatus::Paid->value, $booking->invoice->fresh()->status->value);

        $this->actingAs($admin)
            ->postJson("/api/v1/bookings/{$booking->id}/services", [
                'services' => [['service_id' => $service->id, 'qty' => 1]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.invoice.status', InvoiceStatus::Draft->value);

        $fresh = $booking->fresh();
        $this->assertSame(InvoiceStatus::Draft->value, $fresh->invoice->status->value);
        $this->assertEqualsWithDelta(35000.0, $fresh->remaining_due, 0.01);
    }

    public function test_inactive_service_is_rejected_without_partial_changes(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $active = $this->makeService('Active Service', 10000);
        $inactive = $this->makeService('Inactive Service', 10000, false);
        $booking = $this->makeBooking($admin);

        $this->actingAs($admin)
            ->postJson("/api/v1/bookings/{$booking->id}/services", [
                'services' => [
                    ['service_id' => $active->id, 'qty' => 1],
                    ['service_id' => $inactive->id, 'qty' => 1],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Layanan tambahan tidak valid atau tidak aktif.');

        $this->assertDatabaseMissing('booking_services', ['booking_id' => $booking->id]);
        $this->assertEqualsWithDelta(200000.0, (float) $booking->fresh()->invoice->subtotal, 0.01);
    }

    public function test_finished_booking_rejects_additional_services(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $service = $this->makeService('Spa', 40000);
        $booking = $this->makeBooking($admin);
        $booking->update(['status' => BookingStatus::CheckedOut->value]);

        $this->actingAs($admin)
            ->postJson("/api/v1/bookings/{$booking->id}/services", [
                'services' => [['service_id' => $service->id, 'qty' => 1]],
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('booking_services', ['booking_id' => $booking->id]);
    }

    public function test_service_list_can_filter_active_services_and_search(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $this->makeService('Laundry Express', 25000);
        $this->makeService('Laundry Regular', 20000);
        $this->makeService('Spa', 50000, false);

        $this->actingAs($admin)
            ->getJson('/api/v1/services?is_active=1&search=Laundry&per_page=100')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 2);
    }

    public function test_employee_can_add_service_to_a_reservation(): void
    {
        $employee = User::where('role', UserRole::Pegawai)->first();
        $service = $this->makeService('Extra Towel', 10000);
        $booking = $this->makeBooking($employee);

        $this->actingAs($employee)
            ->postJson("/api/v1/bookings/{$booking->id}/services", [
                'services' => [['service_id' => $service->id, 'qty' => 2]],
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('booking_services', [
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'quantity' => 2,
        ]);
    }

    public function test_admin_can_manage_the_service_catalog(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();

        $created = $this->actingAs($admin)->postJson('/api/v1/services', [
            'name' => 'Meeting Room',
            'price' => 25000,
            'unit' => 'jam',
            'description' => 'Sewa ruang pertemuan',
            'is_active' => true,
        ])->assertStatus(201);

        $id = $created->json('data.id');

        $this->actingAs($admin)
            ->putJson("/api/v1/services/{$id}", ['price' => 30000])
            ->assertStatus(200)
            ->assertJsonPath('data.price', '30000.00');

        $this->actingAs($admin)
            ->deleteJson("/api/v1/services/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('services', ['id' => $id, 'is_active' => false]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/services/{$id}/activate")
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', true);

        foreach (['service.created', 'service.updated', 'service.deactivated', 'service.activated'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'auditable_type' => Service::class,
                'auditable_id' => $id,
            ]);
        }
    }

    public function test_employee_cannot_manage_the_service_catalog(): void
    {
        $employee = User::where('role', UserRole::Pegawai)->first();
        $service = $this->makeService('Protected Service', 10000);

        $this->actingAs($employee)
            ->postJson('/api/v1/services', [
                'name' => 'Illegal Service',
                'price' => 10000,
                'unit' => 'item',
            ])
            ->assertStatus(403);

        $this->actingAs($employee)
            ->putJson("/api/v1/services/{$service->id}", ['price' => 1])
            ->assertStatus(403);

        $this->actingAs($employee)
            ->deleteJson("/api/v1/services/{$service->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'price' => 10000,
            'is_active' => true,
        ]);
    }

    private function makeService(string $name, float $price, bool $active = true): Service
    {
        return Service::create([
            'name' => $name,
            'price' => $price,
            'unit' => 'item',
            'is_active' => $active,
        ]);
    }

    private function makeBooking(User $actor, array $extra = []): Booking
    {
        $customer = Customer::create([
            'name' => 'Service Test Guest',
            'phone' => '081234567890',
        ]);
        $roomType = RoomType::create([
            'name' => 'Service Test Room',
            'price' => 200000,
            'max_person' => 2,
        ]);
        $room = Room::create([
            'room_type_id' => $roomType->id,
            'room_number' => 'SVC-01',
        ]);

        $response = $this->actingAs($actor)->postJson('/api/v1/bookings', array_merge([
            'customer_id' => $customer->id,
            'source' => 'walk_in',
            'check_in' => now()->format('Y-m-d'),
            'check_out' => now()->addDay()->format('Y-m-d'),
            'guest_count' => 1,
            'rooms' => [$room->id],
            'guests' => [['name' => $customer->name, 'is_primary' => true]],
        ], $extra));

        $response->assertStatus(201);

        return Booking::findOrFail($response->json('data.id'));
    }
}
