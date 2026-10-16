<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Room;
use App\Models\Customer;
use App\Models\RoomType;
use App\Models\Booking;
use App\Models\Payment;
use App\Enums\UserRole;
use App\Enums\PaymentStatus;
use App\Enums\InvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        $this->admin = User::where('role', UserRole::Admin)->first();

        $customer = Customer::create(['name' => 'Tamu Gateway', 'phone' => '08222222222']);
        $roomType = RoomType::create(['name' => 'Deluxe', 'price' => 300000, 'max_person' => 2]);
        $room = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'G1']);

        $resp = $this->actingAs($this->admin)
            ->postJson('/api/v1/bookings', [
                'customer_id' => $customer->id,
                'source'      => 'walk_in',
                'check_in'    => now()->format('Y-m-d'),
                'check_out'   => now()->addDays(1)->format('Y-m-d'),
                'guest_count' => 1,
                'rooms'       => [$room->id],
                'guests'      => [['name' => 'Tamu Gateway', 'is_primary' => true]],
            ]);

        $this->booking = Booking::find($resp->json('data.id'));
    }

    public function test_can_create_qris_gateway_payment(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/payments/gateway", [
                'channel' => 'qris',
            ]);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'data' => ['id', 'gateway_transaction_id', 'expires_at'],
                     'payment_info' => ['qr_string', 'transaction_id'],
                 ]);

        $this->assertDatabaseHas('payments', [
            'booking_id' => $this->booking->id,
            'method'     => 'payment_gateway',
            'status'     => PaymentStatus::Pending->value,
            'channel'    => 'qris',
        ]);
    }

    public function test_webhook_with_valid_signature_processes_payment(): void
    {
        // Buat gateway payment dulu
        $resp = $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/payments/gateway", [
                'channel' => 'qris',
            ]);

        $transactionId = $resp->json('data.gateway_transaction_id');

        // Buat payload webhook seperti dari gateway
        $payload = json_encode([
            'transaction_id' => $transactionId,
            'event'          => 'payment.paid',
            'status'         => 'paid',
            'amount'         => $resp->json('data.amount'),
        ]);

        // Buat signature HMAC yang valid. Secret dibaca dari config (phpunit.xml mem-pin
        // nilainya) — bukan hardcode nilai .env developer.
        $secret    = (string) config('services.pg_simulator.secret');
        $signature = hash_hmac('sha256', $payload, $secret);

        $response = $this->postJson('/api/webhooks/payment-gateway', json_decode($payload, true), [
            'X-Simulator-Signature' => $signature,
            'Content-Type'          => 'application/json',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'processed');

        // Payment harus sudah verified
        $this->assertDatabaseHas('payments', [
            'gateway_transaction_id' => $transactionId,
            'status'                 => PaymentStatus::Verified->value,
        ]);

        // Invoice harus lunas
        $this->assertDatabaseHas('invoices', [
            'booking_id' => $this->booking->id,
            'status'     => InvoiceStatus::Paid->value,
        ]);
    }

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        $payload = json_encode([
            'transaction_id' => 'SIM-FAKE123',
            'event'          => 'payment.paid',
            'status'         => 'paid',
        ]);

        $response = $this->postJson('/api/webhooks/payment-gateway', json_decode($payload, true), [
            'X-Simulator-Signature' => 'invalid-signature',
        ]);

        $response->assertStatus(401);

        // Log tetap harus tersimpan meski signature salah
        $this->assertDatabaseHas('payment_gateway_logs', [
            'signature_valid' => false,
            'processed'       => false,
        ]);
    }

    public function test_webhook_is_idempotent(): void
    {
        // Buat gateway payment
        $resp = $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/payments/gateway", [
                'channel' => 'qris',
            ]);

        $transactionId = $resp->json('data.gateway_transaction_id');

        $payload = json_encode([
            'transaction_id' => $transactionId,
            'event'          => 'payment.paid',
            'status'         => 'paid',
            'amount'         => $resp->json('data.amount'),
        ]);

        $secret    = (string) config('services.pg_simulator.secret');
        $signature = hash_hmac('sha256', $payload, $secret);
        $headers   = ['X-Simulator-Signature' => $signature];

        // Kirim webhook pertama kali — diproses
        $this->postJson('/api/webhooks/payment-gateway', json_decode($payload, true), $headers)
             ->assertJsonPath('status', 'processed');

        // Kirim webhook yang sama lagi — harus di-skip (idempoten)
        $this->postJson('/api/webhooks/payment-gateway', json_decode($payload, true), $headers)
             ->assertJsonPath('status', 'skipped');

        // Pastikan payment tidak double-verified (tetap 1 baris verified)
        $this->assertEquals(1, Payment::where('gateway_transaction_id', $transactionId)
            ->where('status', PaymentStatus::Verified->value)
            ->count());
    }
}
