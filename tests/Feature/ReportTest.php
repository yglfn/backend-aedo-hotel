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
                         'cash_revenue', 'transfer_revenue', 'daily',
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
    // Ringkasan laporan (tab Ringkasan pada Pusat Laporan)
    // ------------------------------------------------------------------

    public function test_admin_can_get_report_summary(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/summary');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'period'        => ['from', 'to'],
                    'days',
                    'previous_period' => ['from', 'to'],
                    'revenue'       => [
                        'total_revenue', 'total_transactions', 'average_per_day',
                        'cash_revenue', 'transfer_revenue', 'qris_revenue',
                        'pending_count', 'pending_amount', 'change_percent',
                    ],
                    'occupancy'     => ['occupancy_rate', 'occupied_nights', 'total_room_nights', 'idle_rooms', 'change_points'],
                    'reservations'  => ['total', 'total_value', 'by_status' => ['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled'], 'change_percent'],
                    'housekeeping'  => ['total', 'completed', 'completion_rate'],
                    'shifts'        => ['total_shifts', 'total_collected'],
                    'rooms'         => ['total', 'available', 'occupied'],
                    'revenue_trend',
                    'payment_mix',
                    'booking_sources',
                    'highlights'    => ['best_day', 'best_room', 'top_source'],
                ],
            ]);

        $this->assertGreaterThan(0, $response->json('data.revenue.total_revenue'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.reservations.total'));
    }

    public function test_report_summary_honours_period_filter(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/summary?from=2026-11-01&to=2026-11-30');

        $response->assertStatus(200)
            ->assertJsonPath('data.period.from', '2026-11-01')
            ->assertJsonPath('data.period.to', '2026-11-30')
            ->assertJsonPath('data.days', 30)
            // Periode pembanding = 30 hari tepat sebelum 1 Nov 2026
            ->assertJsonPath('data.previous_period.from', '2026-10-02')
            ->assertJsonPath('data.previous_period.to', '2026-10-31');
    }

    public function test_pegawai_cannot_access_report_summary(): void
    {
        $this->actingAs($this->pegawai)
            ->getJson('/api/v1/reports/summary')
            ->assertStatus(403);
    }

    /**
     * Periode tersimpan di URL halaman laporan, jadi tanggal salah ketik harus dijawab
     * validasi (422), bukan error 500.
     */
    public function test_invalid_period_is_rejected_with_indonesian_message(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/summary?from=bukan-tanggal')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Format tanggal tidak valid. Gunakan YYYY-MM-DD.']);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/revenue?from=2026-11-30&to=2026-11-01')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Tanggal awal tidak boleh lebih besar dari tanggal akhir.']);
    }

    // ------------------------------------------------------------------
    // Angka pelengkap yang dipakai tampilan laporan di aplikasi
    // ------------------------------------------------------------------

    public function test_revenue_report_exposes_method_breakdown_and_averages(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/revenue');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'qris_revenue', 'refund_total', 'pending_count', 'pending_amount',
                    'average_per_day', 'average_per_txn', 'best_day',
                    'comparison' => ['period', 'total_revenue', 'total_transactions', 'change_percent'],
                ],
            ]);

        // Pembanding nol harus tetap null, bukan 0%, supaya UI tidak menyesatkan
        $this->assertNull($response->json('data.comparison.change_percent'));

        // Harian dikirim sebagai angka, bukan string desimal "777000.00"
        $dailyRevenue = $response->json('data.daily.0.revenue');
        $this->assertIsNumeric($dailyRevenue);
        $this->assertIsNotString($dailyRevenue);
    }

    public function test_occupancy_report_exposes_room_detail_and_idle_rooms(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/occupancy');

        $response->assertStatus(200);

        $perRoom = $response->json('data.per_room');
        $this->assertNotEmpty($perRoom);
        $this->assertSame('LAP01', $perRoom[0]['room_number']);
        $this->assertSame('Standard', $perRoom[0]['room_type']);
        $this->assertIsInt($perRoom[0]['occupied_nights']);
        $this->assertArrayHasKey('room_rate', $perRoom[0]);
        $this->assertArrayHasKey('idle_rooms', $response->json('data'));
        $this->assertArrayHasKey('comparison', $response->json('data'));
    }

    public function test_reservations_report_exposes_payment_totals_without_ktp(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/reservations');

        $response->assertStatus(200);

        $summary = $response->json('data.summary');
        foreach (['total_value', 'average_value', 'average_nights', 'total_guests', 'collected', 'outstanding', 'cancellation_rate'] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }

        $this->assertGreaterThan(0, $summary['total_value']);

        // Laporan tidak boleh membawa KTP tamu (AGENTS.md bagian 9)
        $this->assertArrayNotHasKey('ktp', $response->json('data.bookings.0.customer'));
    }

    public function test_transactions_report_exposes_status_summary(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/transactions');

        $response->assertStatus(200);

        $summary = $response->json('data.summary');
        foreach (['total_amount', 'verified_amount', 'pending_amount', 'failed_amount', 'refund_amount'] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }

        $this->assertGreaterThan(0, $summary['verified_amount']);
        $this->assertNotEmpty($response->json('data.by_status'));
        $this->assertArrayNotHasKey('ktp', $response->json('data.payments.0.booking.customer'));
    }

    public function test_payment_methods_and_sources_reports_expose_shares(): void
    {
        $methods = $this->actingAs($this->admin)->getJson('/api/v1/reports/payment-methods');
        $methods->assertStatus(200);
        $this->assertArrayHasKey('total_amount', $methods->json('data'));
        $this->assertArrayHasKey('share_percent', $methods->json('data.data.0'));
        $this->assertArrayHasKey('average', $methods->json('data.data.0'));

        // `method` harus string (bukan enum) supaya PDF/Excel bisa mencetak label
        $this->assertIsString($methods->json('data.data.0.method'));
        $this->assertNotEmpty($methods->json('data.data.0.label'));

        $sources = $this->actingAs($this->admin)->getJson('/api/v1/reports/sources');
        $sources->assertStatus(200);
        $this->assertArrayHasKey('total_count', $sources->json('data'));
        $this->assertArrayHasKey('share_percent', $sources->json('data.data.0'));
    }

    public function test_shifts_and_housekeeping_reports_expose_summaries(): void
    {
        $shifts = $this->actingAs($this->admin)->getJson('/api/v1/reports/shifts');
        $shifts->assertStatus(200);
        $this->assertArrayHasKey('total_collected', $shifts->json('data.summary'));

        $housekeeping = $this->actingAs($this->admin)->getJson('/api/v1/reports/housekeeping');
        $housekeeping->assertStatus(200);
        $this->assertArrayHasKey('completion_rate', $housekeeping->json('data.summary'));
        $this->assertIsArray($housekeeping->json('data.by_staff'));
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

    /**
     * Ringkasan adalah satu-satunya laporan yang ditampilkan di layar Pusat Laporan,
     * jadi dokumen PDF dan Excel-nya harus ikut tersedia.
     */
    public function test_report_summary_can_be_downloaded_as_pdf_and_excel(): void
    {
        $pdf = $this->actingAs($this->admin)->get('/api/v1/reports/summary?format=pdf');
        $pdf->assertStatus(200);
        $this->assertStringContainsString('pdf', (string) $pdf->headers->get('Content-Type'));

        $excel = $this->actingAs($this->admin)->get('/api/v1/reports/summary?format=xlsx');
        $excel->assertStatus(200);
        $this->assertStringContainsString(
            'spreadsheet',
            (string) $excel->headers->get('Content-Type')
        );
    }

    /**
     * Semua view PDF dibaca dari struktur laporan yang sama seperti tampilan aplikasi,
     * jadi setiap jenis laporan harus tetap bisa dicetak.
     */
    public function test_every_report_can_be_downloaded_as_pdf(): void
    {
        foreach (['occupancy', 'revenue', 'reservations', 'transactions', 'payment-methods', 'shifts', 'sources', 'housekeeping'] as $type) {
            $response = $this->actingAs($this->admin)->get('/api/v1/reports/' . $type . '?format=pdf');

            $this->assertSame(200, $response->getStatusCode(), "Gagal mencetak PDF untuk laporan {$type}");
        }
    }

    public function test_every_report_can_be_downloaded_as_excel(): void
    {
        foreach (['occupancy', 'revenue', 'reservations', 'transactions', 'payment-methods', 'shifts', 'sources', 'housekeeping'] as $type) {
            $response = $this->actingAs($this->admin)->get('/api/v1/reports/' . $type . '?format=xlsx');

            $this->assertSame(200, $response->getStatusCode(), "Gagal membuat Excel untuk laporan {$type}");
        }
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
