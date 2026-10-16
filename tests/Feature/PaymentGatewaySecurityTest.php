<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentGatewayLog;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SIMULASI SERANGAN ke jalur payment gateway.
 *
 * Tujuannya membuktikan bahwa status "lunas" tidak bisa dibuat oleh pihak yang tidak
 * berwenang: webhook wajib signature sah + nominal cocok + idempoten, dan tidak ada
 * endpoint publik/manual yang bisa menandai bayar tanpa bukti dari provider.
 */
class PaymentGatewaySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $pegawai;
    protected string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        $this->admin   = User::where('role', UserRole::Admin)->first();
        $this->pegawai = User::where('role', UserRole::Pegawai)->first();
        $this->secret  = (string) config('services.pg_simulator.secret');
    }

    protected function makeBooking(string $roomNumber = 'GSEC1'): Booking
    {
        $roomType = RoomType::create(['name' => 'Sec Room ' . $roomNumber, 'price' => 400000, 'max_person' => 2]);
        $room     = Room::create(['room_type_id' => $roomType->id, 'room_number' => $roomNumber]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/bookings', [
            'customer_id' => Customer::create(['name' => 'Tamu Sec', 'phone' => '08123999000' . strlen($roomNumber)])->id,
            'source'      => 'walk_in',
            'check_in'    => now()->format('Y-m-d'),
            'check_out'   => now()->addDays(2)->format('Y-m-d'),
            'guest_count' => 1,
            'rooms'       => [$room->id],
            'guests'      => [['name' => 'Tamu Sec', 'is_primary' => true]],
        ]);

        $response->assertStatus(201);

        return Booking::find($response->json('data.id'));
    }

    /** Buat tagihan gateway (QR/VA) dan kembalikan payload transaksinya. */
    protected function makeGatewayPayment(Booking $booking): array
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$booking->id}/payments/gateway", ['channel' => 'qris'])
            ->assertStatus(201)
            ->json('data');
    }

    /** Kirim webhook seperti yang dilakukan provider (signature HMAC atas raw body). */
    protected function sendWebhook(array $data, ?string $signature = null)
    {
        $payload = json_encode($data);

        return $this->postJson('/api/webhooks/payment-gateway', json_decode($payload, true), [
            'X-Simulator-Signature' => $signature ?? hash_hmac('sha256', $payload, $this->secret),
        ]);
    }

    public function test_forged_signature_cannot_make_a_payment_verified(): void
    {
        $payment = $this->makeGatewayPayment($this->makeBooking('GSEC2'));

        $this->sendWebhook([
            'transaction_id' => $payment['gateway_transaction_id'],
            'event'          => 'payment.settled',
            'status'         => 'paid',
            'amount'         => $payment['amount'],
        ], 'hasil-tanpa-hmac-yang-sah')->assertStatus(401);

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment['id'])->status->value);
        $this->assertDatabaseHas('payment_gateway_logs', [
            'payment_id'      => $payment['id'],
            'signature_valid' => false,
        ]);
    }

    public function test_unknown_transaction_id_is_ignored_and_logged(): void
    {
        $this->sendWebhook([
            'transaction_id' => 'SIM-YANGTIDAKADA',
            'status'         => 'paid',
        ])->assertStatus(200)->assertJsonPath('status', 'ignored');

        $this->assertDatabaseHas('payment_gateway_logs', ['error_message' => 'Payment dengan transaction_id tidak ditemukan.']);
    }

    public function test_webhook_amount_must_match_the_payment_amount(): void
    {
        $payment = $this->makeGatewayPayment($this->makeBooking('GSEC3'));

        // Penyerang punya signature sah (mis. menyalin webhook asli) tapi nominal dikecilkan
        $this->sendWebhook([
            'transaction_id' => $payment['gateway_transaction_id'],
            'event'          => 'payment.settled',
            'status'         => 'paid',
            'amount'         => 1,
        ])->assertStatus(422)->assertJsonPath('status', 'amount_mismatch');

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment['id'])->status->value);
        $this->assertDatabaseHas('payment_gateway_logs', [
            'payment_id'      => $payment['id'],
            'signature_valid' => true,
        ]);
    }

    public function test_missing_amount_in_settlement_is_rejected(): void
    {
        $payment = $this->makeGatewayPayment($this->makeBooking('GSEC4'));

        $this->sendWebhook([
            'transaction_id' => $payment['gateway_transaction_id'],
            'status'         => 'paid',
        ])->assertStatus(422)->assertJsonPath('status', 'amount_mismatch');

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment['id'])->status->value);
    }

    public function test_replayed_webhook_is_idempotent(): void
    {
        $payment = $this->makeGatewayPayment($this->makeBooking('GSEC5'));
        $data = [
            'transaction_id' => $payment['gateway_transaction_id'],
            'event'          => 'payment.settled',
            'status'         => 'paid',
            'amount'         => $payment['amount'],
        ];

        $this->sendWebhook($data)->assertStatus(200)->assertJsonPath('status', 'processed');
        $paidAt = Payment::find($payment['id'])->paid_at;

        $this->sendWebhook($data)->assertStatus(200)->assertJsonPath('status', 'skipped');

        $fresh = Payment::find($payment['id']);
        $this->assertSame(PaymentStatus::Verified->value, $fresh->status->value);

        // Replay tidak boleh menghasilkan efek ganda: waktu bayar sama & audit hanya satu baris
        $this->assertSame($paidAt->toDateTimeString(), $fresh->paid_at->toDateTimeString());
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'payment.gateway_verified')
            ->where('auditable_id', $payment['id'])->count());

        // Webhook yang sama tetap dicatat dua kali sebagai jurnal masuk (forensik), bukan bug
        $this->assertSame(2, PaymentGatewayLog::where('payment_id', $payment['id'])
            ->where('event_type', 'payment.settled')->count());
    }

    public function test_a_signed_webhook_cannot_verify_a_manual_payment(): void
    {
        // Payment transfer manual (bukan gateway) tidak punya gateway_transaction_id,
        // jadi siapa pun yang mengirim transaction_id apa pun tidak boleh bisa men verified kan dia
        $booking = $this->makeBooking('GSEC6');
        $manualId = $this->actingAs($this->admin)->postJson("/api/v1/bookings/{$booking->id}/payments", [
            'amount'           => 100000,
            'method'           => PaymentMethod::Transfer->value,
            'type'             => 'dp',
            'reference_number' => 'TRF-SEC-MANUAL',
        ])->assertStatus(201)->json('data.id');

        $this->sendWebhook([
            'transaction_id' => 'SIM-TEBAK',
            'status'         => 'paid',
            'amount'         => 100000,
        ])->assertStatus(200)->assertJsonPath('status', 'ignored');

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($manualId)->status->value);

        // ... dan endpoint manual tidak menerima transaksi gateway
        $this->actingAs($this->admin)->postJson("/api/v1/payments/{$manualId}/sync")
            ->assertStatus(200)
            ->assertJsonPath('gateway_status', 'ignored')
            ->assertJsonPath('payment_status', PaymentStatus::Pending->value);
    }

    public function test_guest_or_anonymous_cannot_change_payment_status(): void
    {
        $payment = $this->makeGatewayPayment($this->makeBooking('GSEC7'));

        // actingAs() bertahan antar request di dalam satu test, jadi guard-nya dibersihkan dulu
        $this->app['auth']->forgetGuards();

        foreach ([
            "/api/v1/payments/{$payment['id']}/verify"  => 'patchJson',
            "/api/v1/payments/{$payment['id']}/reject"  => 'patchJson',
            "/api/v1/payments/{$payment['id']}/reverse" => 'postJson',
            "/api/v1/payments/{$payment['id']}/sync"    => 'postJson',
        ] as $uri => $method) {
            $response = $this->{$method}($uri, ['reason' => 'x', 'status' => 'verified']);
            $this->assertSame([401], [$response->status()], "{$uri} harus butuh login, dapat {$response->status()}");
        }

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment['id'])->status->value);
    }

    public function test_webhook_with_old_timestamp_is_rejected_as_replay(): void
    {
        $payment = $this->makeGatewayPayment($this->makeBooking('GSEC12'));

        $payload = json_encode([
            'transaction_id' => $payment['gateway_transaction_id'],
            'event'          => 'payment.settled',
            'status'         => 'paid',
            'amount'         => $payment['amount'],
        ]);

        // Signature sah, tapi timestamp-nya 1 jam lalu -> di luar jendela replay guard
        $stale = (string) (time() - 3600);

        $this->postJson('/api/webhooks/payment-gateway', json_decode($payload, true), [
            'X-Simulator-Signature' => hash_hmac('sha256', $stale . '.' . $payload, $this->secret),
            'X-Simulator-Timestamp' => $stale,
        ])->assertStatus(401);

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment['id'])->status->value);
    }

    public function test_sandbox_cannot_settle_with_a_different_amount(): void
    {
        $payment = $this->makeGatewayPayment($this->makeBooking('GSEC13'));

        // Tamu "bayar" Rp 1.000 untuk tagihan ratusan ribu -> webhook sandbox ditolak sistem sendiri
        $this->postJson('/api/sandbox/gateway/' . $payment['gateway_transaction_id'] . '/pay', [
            'amount' => 1000,
        ], ['X-Sandbox-Token' => $this->secret])
            ->assertStatus(422)
            ->assertJsonPath('webhook_status', 'amount_mismatch')
            ->assertJsonPath('payment_status', PaymentStatus::Pending->value);

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment['id'])->status->value);
    }

    public function test_pegawai_cannot_verify_reject_or_reverse_payments(): void
    {
        $payment = $this->makeGatewayPayment($this->makeBooking('GSEC8'));

        $this->actingAs($this->pegawai)->patchJson("/api/v1/payments/{$payment['id']}/verify")->assertStatus(403);
        $this->actingAs($this->pegawai)->patchJson("/api/v1/payments/{$payment['id']}/reject")->assertStatus(403);
        $this->actingAs($this->pegawai)->postJson("/api/v1/payments/{$payment['id']}/reverse", ['reason' => 'x'])->assertStatus(403);

        // Cek status ke gateway boleh untuk pegawai (bukan aksi akuntansi, hanya bertanya)
        $this->actingAs($this->pegawai)->postJson("/api/v1/payments/{$payment['id']}/sync")->assertStatus(200);
    }

    public function test_late_settlement_after_expiry_is_recorded_not_silently_dropped(): void
    {
        $payment = $this->makeGatewayPayment($this->makeBooking('GSEC9'));

        // QR kedaluwarsa, tapi uang ternyata masuk
        Payment::find($payment['id'])->update(['status' => PaymentStatus::Expired->value]);

        $this->sendWebhook([
            'transaction_id' => $payment['gateway_transaction_id'],
            'status'         => 'paid',
            'amount'         => $payment['amount'],
        ])->assertStatus(200)->assertJsonPath('status', 'processed');

        $fresh = Payment::find($payment['id']);
        $this->assertSame(PaymentStatus::Verified->value, $fresh->status->value);
        $this->assertStringContainsString('kedaluwarsa', (string) $fresh->notes);

        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'payment.gateway_verified',
            'auditable_type' => Payment::class,
            'auditable_id'   => $payment['id'],
        ]);
    }

    public function test_unknown_gateway_provider_does_not_silently_use_the_simulator(): void
    {
        config(['services.payment_gateway.provider' => 'midtrans']);

        $this->expectException(\RuntimeException::class);

        app(\App\Services\PaymentGateway\PaymentGatewayInterface::class);
    }

    public function test_sandbox_pay_endpoint_requires_login_or_token(): void
    {
        $payment = $this->makeGatewayPayment($this->makeBooking('GSEC10'));

        // actingAs() bertahan antar request, jadi guard dibersihkan supaya benar-benar anonim
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/sandbox/gateway/' . $payment['gateway_transaction_id'] . '/pay')
            ->assertStatus(401);

        // Token salah tetap ditolak
        $this->postJson('/api/sandbox/gateway/' . $payment['gateway_transaction_id'] . '/pay', [], [
            'X-Sandbox-Token' => 'bukan-token',
        ])->assertStatus(401);

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($payment['id'])->status->value);
    }

    public function test_logged_in_staff_can_settle_in_sandbox_from_the_app_route(): void
    {
        $payment = $this->makeGatewayPayment($this->makeBooking('GSEC14'));

        // Tombol "Bayar (Sandbox)" di aplikasi: jalur /api/v1 yang sudah ter-auth, tanpa token,
        // dan secret sandbox tidak pernah dikirim ke browser
        $this->actingAs($this->pegawai)
            ->postJson('/api/v1/sandbox/gateway/' . $payment['gateway_transaction_id'] . '/pay')
            ->assertStatus(200)
            ->assertJsonPath('webhook_status', 'processed')
            ->assertJsonPath('payment_status', PaymentStatus::Verified->value);

        $this->assertSame(PaymentStatus::Verified->value, Payment::find($payment['id'])->status->value);
    }

    public function test_sandbox_pay_marks_the_payment_settled_through_the_real_webhook_path(): void
    {
        $payment = $this->makeGatewayPayment($this->makeBooking('GSEC11'));

        $this->postJson('/api/sandbox/gateway/' . $payment['gateway_transaction_id'] . '/pay', [
            'amount' => $payment['amount'],
        ], ['X-Sandbox-Token' => $this->secret])
            ->assertStatus(200)
            ->assertJsonPath('payment_status', PaymentStatus::Verified->value);

        $this->assertSame(PaymentStatus::Verified->value, Payment::find($payment['id'])->status->value);
        $this->assertSame('confirmed', Booking::find($payment['booking_id'])->fresh()->status->value);
        $this->assertSame('paid', Booking::find($payment['booking_id'])->invoice->fresh()->status->value);

        // Jejak webhook-nya nyata (bukan update diam-diam), jadi bisa diaudit
        $this->assertDatabaseHas('payment_gateway_logs', [
            'payment_id'      => $payment['id'],
            'signature_valid' => true,
            'event_type'      => 'payment.settled',
        ]);
    }
}
