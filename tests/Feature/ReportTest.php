<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Customer;
use App\Models\Room;
use App\Models\RoomType;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $pegawai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        $this->admin   = User::where('role', UserRole::Admin->value)->first();
        $this->pegawai = User::where('role', UserRole::Pegawai->value)->first()
            ?? User::factory()->create(['role' => UserRole::Pegawai->value, 'is_active' => true]);

        // Buat satu booking + payment sebagai data laporan
        $customer = Customer::create(['name' => 'Report Guest', 'phone' => '08111111111']);
        $roomType = RoomType::create(['name' => 'Standard', 'price' => 300000, 'max_person' => 2]);
        $room     = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'LAP01']);

        $bookingResp = $this->actingAs($this->admin)
            ->postJson('/api/v1/bookings', [
                'customer_id' => $customer->id,
                'source'      => 'walk_in',
                'check_in'    => now()->format('Y-m-d'),
                'check_out'   => now()->addDays(2)->format('Y-m-d'),
                'guest_count' => 1,
                'rooms'       => [$room->id],
                'guests'      => [['name' => 'Report Guest', 'is_primary' => true]],
            ]);

        $bookingId = $bookingResp->json('data.id');

        // Bayar tunai (langsung verified)
        $invoice = \App\Models\Booking::find($bookingId)->invoice;
        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$bookingId}/payments", [
                'amount' => (float) $invoice->total,
                'method' => 'cash',
                'type'   => 'final',
            ]);
    }

    // ------------------------------------------------------------------
    // JSON Response Tests
    // ------------------------------------------------------------------

    public function test_admin_can_get_occupancy_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/occupancy');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'period', 'total_rooms', 'total_nights',
                         'total_room_nights', 'occupied_nights',
                         'occupancy_rate', 'per_room',
                     ],
                 ]);
    }

    public function test_admin_can_get_revenue_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/revenue');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'period', 'total_revenue', 'total_transactions',
                         'cash_revenue', 'transfer_revenue', 'gateway_revenue', 'daily',
                     ],
                 ]);

        // Harus ada pendapatan dari payment yang sudah dibuat
        $this->assertGreaterThan(0, $response->json('data.total_revenue'));
    }

    public function test_admin_can_get_reservations_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/reservations');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['period', 'summary', 'bookings']]);

        $this->assertGreaterThan(0, $response->json('data.summary.total'));
    }

    public function test_admin_can_get_transactions_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/transactions');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['period', 'total', 'payments']]);

        $this->assertGreaterThan(0, $response->json('data.total'));
    }

    public function test_admin_can_get_payment_methods_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/payment-methods');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['period', 'data']]);
    }

    public function test_admin_can_get_sources_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/sources');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['period', 'data']]);
    }

    public function test_admin_can_get_housekeeping_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/housekeeping');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => ['period', 'summary', 'tasks'],
                 ]);
    }

    public function test_admin_can_get_shifts_report(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/shifts');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['period', 'shifts']]);
    }

    // ------------------------------------------------------------------
    // PDF Export Test
    // ------------------------------------------------------------------

    public function test_admin_can_download_revenue_as_pdf(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/api/v1/reports/revenue?format=pdf');

        // PDF generation requires DomPDF — response 200 dengan Content-Type pdf
        $response->assertStatus(200);
        $contentType = $response->headers->get('Content-Type');
        $this->assertTrue(
            str_contains($contentType, 'application/pdf') || str_contains($contentType, 'application/octet-stream'),
            "Expected PDF content type, got: {$contentType}"
        );
    }

    // ------------------------------------------------------------------
    // Excel Export Test
    // ------------------------------------------------------------------

    public function test_admin_can_download_transactions_as_excel(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/api/v1/reports/transactions?format=xlsx');

        $response->assertStatus(200);
        $contentType = $response->headers->get('Content-Type');
        $this->assertTrue(
            str_contains($contentType, 'spreadsheetml') || str_contains($contentType, 'octet-stream'),
            "Expected Excel content type, got: {$contentType}"
        );
    }

    // ------------------------------------------------------------------
    // Access Control Test
    // ------------------------------------------------------------------

    public function test_pegawai_cannot_access_reports(): void
    {
        $response = $this->actingAs($this->pegawai)
            ->getJson('/api/v1/reports/revenue');

        $response->assertStatus(403);
    }
}
