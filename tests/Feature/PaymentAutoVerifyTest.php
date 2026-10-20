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
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Verifikasi otomatis berbasis bukti: pembayaran transfer/QRIS tidak lagi menunggu
 * admin membuka sistem, dengan reversal sebagai kontrol post-audit.
 */
class PaymentAutoVerifyTest extends TestCase
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
        $this->customer = Customer::create(['name' => 'Tamu Auto', 'phone' => '081235550001']);
    }

    /** Booking 1 kamar, default 1 malam @300.000 -> invoice 333.000 (pajak 11%). */
    protected function makeBooking(string $roomNumber = 'AUTO1', float $price = 300000, int $nights = 1): Booking
    {
        $roomType = RoomType::create(['name' => 'Auto Room ' . $roomNumber, 'price' => $price, 'max_person' => 2]);
        $room     = Room::create(['room_type_id' => $roomType->id, 'room_number' => $roomNumber]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/bookings', [
            'customer_id' => $this->customer->id,
            'source'      => 'walk_in',
            'check_in'    => now()->format('Y-m-d'),
            'check_out'   => now()->addDays($nights)->format('Y-m-d'),
            'guest_count' => 1,
            'rooms'       => [$room->id],
            'guests'      => [['name' => 'Tamu Auto', 'is_primary' => true]],
        ]);

        $response->assertStatus(201);

        return Booking::find($response->json('data.id'));
    }

    protected function recordTransfer(Booking $booking, float $amount, ?string $reference = 'TRF-AUTO-01')
    {
        return $this->actingAs($this->admin)->postJson("/api/v1/bookings/{$booking->id}/payments", array_filter([
            'amount'           => $amount,
            'method'           => PaymentMethod::Transfer->value,
            'type'             => 'final',
            'reference_number' => $reference,
        ], fn ($v) => $v !== null));
    }

    protected function uploadProof(int $paymentId)
    {
        return $this->actingAs($this->admin)->postJson("/api/v1/payments/{$paymentId}/proof", [
            'proof' => UploadedFile::fake()->image('bukti-transfer.jpg'),
        ]);
    }

    public function test_settings_default_to_auto_verification_enabled(): void
    {
        $setting = Setting::first();

        $this->assertTrue((bool) $setting->auto_verify_transfers);
        $this->assertSame(0.0, (float) $setting->auto_verify_max_amount);
    }

    public function test_transfer_with_reference_and_proof_verifies_itself_without_admin(): void
    {
        $booking = $this->makeBooking('AUTO2', 300000);

        $paymentId = $this->recordTransfer($booking, 333000)->assertStatus(201)
            ->assertJsonPath('data.status', PaymentStatus::Pending->value)
            ->json('data.id');

        $this->uploadProof($paymentId)
            ->assertStatus(200)
            ->assertJsonPath('auto_verified', true)
            ->assertJsonPath('data.status', PaymentStatus::Verified->value);

        $payment = Payment::find($paymentId);

        // Penanda "sistem yang memverifikasi": verified_by kosong, audit log terisi
        $this->assertNull($payment->verified_by);
        $this->assertStringContainsString('Diverifikasi otomatis', (string) $payment->notes);
        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'payment.auto_verified',
            'auditable_type' => Payment::class,
            'auditable_id'   => $paymentId,
        ]);

        $booking->load('payments');
        $this->assertSame(BookingStatus::Confirmed->value, $booking->fresh()->status->value);
        $this->assertSame(InvoiceStatus::Paid->value, $booking->invoice->fresh()->status->value);
    }

    public function test_transfer_without_reference_still_needs_admin_approval(): void
    {
        $booking = $this->makeBooking('AUTO3');

        $paymentId = $this->recordTransfer($booking, 333000, null)->assertStatus(201)->json('data.id');

        // Bukti ada, tapi nomor referensi kosong -> tidak ada yang bisa dicocokkan
        $this->uploadProof($paymentId)->assertStatus(200)->assertJsonPath('auto_verified', false);
        $this->assertSame(PaymentStatus::Pending->value, Payment::find($paymentId)->status->value);

        // Admin tetap bisa approve manual
        $this->actingAs($this->admin)->patchJson("/api/v1/payments/{$paymentId}/verify")->assertStatus(200);
        $this->assertSame(PaymentStatus::Verified->value, Payment::find($paymentId)->status->value);
    }

    public function test_same_reference_number_cannot_verify_two_payments(): void
    {
        $first  = $this->makeBooking('AUTO4');
        $second = $this->makeBooking('AUTO5');

        $firstId = $this->recordTransfer($first, 333000, 'TRF-REUSED')->assertStatus(201)->json('data.id');
        $this->uploadProof($firstId)->assertStatus(200)->assertJsonPath('auto_verified', true);

        // Bukti daur ulang dengan nomor referensi yang sama -> tidak boleh lolos otomatis
        $secondId = $this->recordTransfer($second, 333000, 'TRF-REUSED')->assertStatus(201)->json('data.id');
        $this->uploadProof($secondId)->assertStatus(200)->assertJsonPath('auto_verified', false);

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($secondId)->status->value);
    }

    public function test_amount_above_ceiling_is_not_auto_verified(): void
    {
        Setting::first()->update(['auto_verify_max_amount' => 100000]);

        $booking = $this->makeBooking('AUTO6');
        $paymentId = $this->recordTransfer($booking, 333000, 'TRF-BIG')->assertStatus(201)->json('data.id');

        $this->uploadProof($paymentId)->assertStatus(200)->assertJsonPath('auto_verified', false);

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($paymentId)->status->value);
    }

    public function test_disabling_auto_verification_restores_manual_approval(): void
    {
        Setting::first()->update(['auto_verify_transfers' => false]);

        $booking = $this->makeBooking('AUTO7');
        $paymentId = $this->recordTransfer($booking, 333000, 'TRF-OFF')->assertStatus(201)->json('data.id');

        $this->uploadProof($paymentId)->assertStatus(200)->assertJsonPath('auto_verified', false);
        $this->assertSame(PaymentStatus::Pending->value, Payment::find($paymentId)->status->value);
    }

    public function test_admin_can_reverse_an_auto_verified_payment_and_pegawai_cannot(): void
    {
        $booking = $this->makeBooking('AUTO8');
        $paymentId = $this->recordTransfer($booking, 333000, 'TRF-REV')->assertStatus(201)->json('data.id');
        $this->uploadProof($paymentId)->assertStatus(200)->assertJsonPath('auto_verified', true);

        $this->actingAs($this->pegawai)
            ->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'Coba-coba'])
            ->assertStatus(403);

        // Alasan wajib
        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$paymentId}/reverse")->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'Mutasi bank tidak ditemukan'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', PaymentStatus::Failed->value);

        $this->assertSame(PaymentStatus::Failed->value, Payment::find($paymentId)->status->value);

        // Tagihan kembali belum lunas & booking turun dari confirmed tidak dilakukan (butuh koreksi manual)
        $invoice = Invoice::where('booking_id', $booking->id)->first();
        $this->assertSame(InvoiceStatus::Draft->value, $invoice->status->value);

        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'payment.reversed',
            'auditable_type' => Payment::class,
            'auditable_id'   => $paymentId,
        ]);
    }

    public function test_checkin_and_checkout_finish_without_admin_approval(): void
    {
        // Alur lengkap yang dulu macet menunggu approval: pegawai, transfer, bukti, check-in, check-out
        $this->actingAs($this->pegawai)->postJson('/api/v1/shifts/start')->assertSuccessful();

        $booking = $this->makeBooking('AUTO9');

        $paymentId = $this->actingAs($this->pegawai)
            ->postJson("/api/v1/bookings/{$booking->id}/payments", [
                'amount'           => 333000,
                'method'           => PaymentMethod::Transfer->value,
                'type'             => 'final',
                'reference_number' => 'TRF-E2E',
            ])->assertStatus(201)->json('data.id');

        $this->actingAs($this->pegawai)->postJson("/api/v1/payments/{$paymentId}/proof", [
            'proof' => UploadedFile::fake()->image('bukti.jpg'),
        ])->assertStatus(200)->assertJsonPath('auto_verified', true);

        $this->assertSame(BookingStatus::Confirmed->value, $booking->fresh()->status->value);

        $this->actingAs($this->pegawai)
            ->postJson("/api/v1/bookings/{$booking->id}/check-in")->assertStatus(200);

        $this->actingAs($this->pegawai)
            ->postJson("/api/v1/bookings/{$booking->id}/check-out")->assertStatus(200);

        $this->assertSame(BookingStatus::CheckedOut->value, $booking->fresh()->status->value);
    }
}
