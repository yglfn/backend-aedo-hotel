<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\PaymentGateway\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Check-in & check-out harus menilai status pembayaran yang SEBENARNYA di gateway.
 *
 * Tanpa rekonsiliasi di titik keputusan, webhook yang tidak masuk (lokal tanpa HTTPS tunnel)
 * dan scheduler yang tidak jalan (tanpa cron) membuat kasir melihat "belum lunas" padahal
 * uangnya sudah ada — dan check-out tertahan tanpa sebab.
 */
class BookingGatewayReconcileTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-testkey123';

    protected User $admin;
    protected Booking $booking;
    protected Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        config([
            'services.payment_gateway.provider' => 'midtrans',
            'services.midtrans.server_key'      => self::SERVER_KEY,
            'services.midtrans.signature_key'   => '',
            'services.midtrans.is_production'   => false,
        ]);

        $this->admin = User::where('role', UserRole::Admin->value)->first();

        $customer = Customer::create(['name' => 'Tamu Rekonsiliasi', 'phone' => '081238880003']);
        $roomType = RoomType::create(['name' => 'Recon Room', 'price' => 300000, 'max_person' => 2]);
        $room     = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'RCN1']);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/bookings', [
            'customer_id' => $customer->id,
            'source'      => 'walk_in',
            'check_in'    => now()->format('Y-m-d'),
            'check_out'   => now()->addDay()->format('Y-m-d'),
            'guest_count' => 1,
            'rooms'       => [$room->id],
            'guests'      => [['name' => 'Tamu Rekonsiliasi', 'is_primary' => true]],
        ]);

        $response->assertStatus(201);

        $this->booking = Booking::find($response->json('data.id'));

        // Payment gateway pending atas seluruh tagihan (300.000 + 11% = 333.000)
        $this->payment = Payment::create([
            'booking_id'             => $this->booking->id,
            'invoice_id'             => $this->booking->invoice->id,
            'created_by'             => $this->admin->id,
            'method'                 => PaymentMethod::PaymentGateway->value,
            'type'                   => PaymentType::Final->value,
            'status'                 => PaymentStatus::Pending->value,
            'amount'                 => 333000,
            'provider'               => 'midtrans',
            'gateway_transaction_id' => 'AEDO-RECON000000001',
            'channel'                => 'auto',
            'gateway_payload'        => ['transaction_id' => 'AEDO-RECON000000001', 'environment' => 'sandbox'],
            'expires_at'             => now()->addHour(),
        ]);
    }

    /** Jawaban /v2/{order_id}/status dari Midtrans. */
    protected function fakeStatus(array $body, int $status = 200): void
    {
        Http::fake([
            '*/v2/AEDO-RECON000000001/status' => Http::response($body, $status),
        ]);
    }

    protected function settled(): array
    {
        return [
            'order_id'           => 'AEDO-RECON000000001',
            'transaction_id'     => 'mt-recon-1',
            'payment_type'       => 'qris',
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'gross_amount'       => '333000.00',
            'fraud_status'       => 'accept',
        ];
    }

    public function test_check_in_mengonfirmasikan_reservasi_saat_gateway_sudah_menerima_uang(): void
    {
        // Awalnya pending & belum terkonfirmasi: tanpa rekonsiliasi check-in akan ditolak
        $this->assertSame(BookingStatus::Pending->value, $this->booking->status->value);

        $this->fakeStatus($this->settled());

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/check-in")
            ->assertStatus(200);

        $this->assertSame(PaymentStatus::Verified->value, Payment::find($this->payment->id)->fresh()->status->value);
        $this->assertSame(InvoiceStatus::Paid->value, $this->booking->fresh()->invoice->status->value);
        $this->assertSame(BookingStatus::CheckedIn->value, $this->booking->fresh()->status->value);
    }

    public function test_check_out_boleh_saat_uang_gateway_baru_saja_masuk(): void
    {
        // Tamu sudah check-in tapi pembayarannya masih tercatat pending di DB
        $this->booking->forceFill(['status' => BookingStatus::CheckedIn->value])->save();

        $this->fakeStatus($this->settled());

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/check-out")
            ->assertStatus(200);

        $this->assertSame(PaymentStatus::Verified->value, Payment::find($this->payment->id)->fresh()->status->value);
        $this->assertSame(BookingStatus::CheckedOut->value, $this->booking->fresh()->status->value);
    }

    public function test_check_out_ditolak_dengan_alasan_yang_jujur_saat_gateway_masih_pending(): void
    {
        $this->booking->forceFill(['status' => BookingStatus::CheckedIn->value])->save();

        $this->fakeStatus([
            'order_id'           => 'AEDO-RECON000000001',
            'transaction_status' => 'pending',
            'status_code'        => '201',
            'gross_amount'       => '333000.00',
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/check-out")
            ->assertStatus(422)
            ->assertJsonPath('error', 'Tagihan belum lunas. Sisa pembayaran: Rp 333.000 Pembayaran gateway masih menunggu konfirmasi dari provider.');

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($this->payment->id)->fresh()->status->value);
    }

    /**
     * Gateway tidak bisa dihubungi tidak boleh mengunci meja depan dengan error 500,
     * dan pesannya harus menyebut bahwa status belum dapat diperiksa.
     */
    public function test_gateway_terputus_tidak_membuat_500_dan_dinyatakan_terus(): void
    {
        $this->booking->forceFill(['status' => BookingStatus::CheckedIn->value])->save();

        Http::fake([
            '*' => fn () => throw new ConnectionException('gateway offline'),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/check-out")
            ->assertStatus(422)
            ->assertJsonPath('error', 'Tagihan belum lunas. Sisa pembayaran: Rp 333.000 Status payment gateway belum dapat diperiksa — periksa koneksi lalu ulangi.');

        $this->assertSame(PaymentStatus::Pending->value, Payment::find($this->payment->id)->fresh()->status->value);
    }

    public function test_pembayaran_manual_tidak_ikut_direkonsiliasi(): void
    {
        Payment::create([
            'booking_id' => $this->booking->id,
            'invoice_id' => $this->booking->invoice->id,
            'created_by' => $this->admin->id,
            'method'     => PaymentMethod::Transfer->value,
            'type'       => PaymentType::Dp->value,
            'status'     => PaymentStatus::Pending->value,
            'amount'     => 100000,
        ]);

        // Hanya status transaksi gateway yang ditanya; fake di bawah tidak akan dipakai ulang
        $this->fakeStatus($this->settled());

        app(PaymentGatewayInterface::class); // pastikan provider aktif: midtrans

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$this->booking->id}/check-in")
            ->assertStatus(200);

        // Payment transfer tetap pending (butuh bukti/verifikasi jalur manual), tidak ikut terisi
        $this->assertSame(
            PaymentStatus::Pending->value,
            Payment::where('method', PaymentMethod::Transfer->value)->first()->status->value
        );
    }
}
