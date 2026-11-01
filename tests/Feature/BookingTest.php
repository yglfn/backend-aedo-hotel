<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Room;
use App\Models\Customer;
use App\Models\Booking;
use App\Models\RoomType;
use App\Enums\UserRole;
use App\Enums\BookingStatus;
use App\Enums\BookingSource;
use App\Enums\RoomStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');
    }

    public function test_can_create_multi_room_booking(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $customer = Customer::create([
            'name' => 'Budi Santoso',
            'phone' => '08123456789'
        ]);
        
        // Buat tipe kamar baru untuk test
        $roomType = RoomType::create([
            'name' => 'Deluxe',
            'price' => 500000,
            'max_person' => 2,
        ]);

        $room1 = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'D1']);
        $room2 = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'D2']);

        $response = $this->actingAs($admin)
                         ->withHeaders(['Referer' => 'http://localhost'])
                         ->postJson('/api/v1/bookings', [
                             'customer_id' => $customer->id,
                             'source' => 'walk_in',
                             'check_in' => now()->format('Y-m-d'),
                             'check_out' => now()->addDays(2)->format('Y-m-d'),
                             'guest_count' => 3, // Maks 4 untuk 2 kamar deluxe
                             'rooms' => [$room1->id, $room2->id],
                             'guests' => [
                                 // Reservasi multi-kamar: tiap penghuni wajib ditempatkan ke kamar
                                 ['name' => 'Tamu Utama', 'is_primary' => true, 'room_id' => $room1->id],
                                 ['name' => 'Tamu Tambahan 1', 'is_primary' => false, 'room_id' => $room2->id],
                             ]
                         ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['message', 'data' => ['booking_code']]);

        // Aturan bisnis no. 4 (AGENTS.md): total = subtotal - diskon + pajak.
        // Subtotal = 500.000 x 2 malam x 2 kamar = 2.000.000, pajak dibaca dari settings
        // supaya test tetap benar bila persentase pajak hotel diubah.
        $taxPercentage  = (float) \App\Models\Setting::first()->tax_percentage;
        $expectedTotal  = round(2000000 + (2000000 * $taxPercentage / 100), 2);

        $this->assertDatabaseHas('bookings', [
            'total_price' => $expectedTotal,
            'status' => BookingStatus::Pending->value,
            'guest_count' => 3,
        ]);

        $this->assertDatabaseHas('booking_rooms', [
            'room_id' => $room1->id,
            'nights' => 2,
            'total_price' => 1000000,
        ]);
        
        $this->assertDatabaseHas('booking_guests', [
            'name' => 'Tamu Utama',
            'is_primary' => 1,
        ]);
    }

    public function test_cannot_double_book_room(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $customer = Customer::create([
            'name' => 'Budi Santoso',
            'phone' => '08123456789'
        ]);
        
        $roomType = RoomType::create([
            'name' => 'Standard',
            'price' => 300000,
            'max_person' => 2,
        ]);
        $room = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'S1']);

        $payload = [
            'customer_id' => $customer->id,
            'source' => 'walk_in',
            'check_in' => now()->format('Y-m-d'),
            'check_out' => now()->addDays(3)->format('Y-m-d'),
            'guest_count' => 1,
            'rooms' => [$room->id],
            'guests' => [['name' => 'Tamu 1', 'is_primary' => true]]
        ];

        // Booking 1 - Sukses
        $this->actingAs($admin)
             ->withHeaders(['Referer' => 'http://localhost'])
             ->postJson('/api/v1/bookings', $payload)
             ->assertStatus(201);

        // Booking 2 di tanggal yang sama - Gagal
        $payload['check_out'] = now()->addDays(1)->format('Y-m-d'); // Irisan hari pertama
        $response = $this->actingAs($admin)
                         ->withHeaders(['Referer' => 'http://localhost'])
                         ->postJson('/api/v1/bookings', $payload);

        // Konflik ketersediaan dibalas 409 (AGENTS.md bagian 6 butir 1),
        // bukan 422, agar frontend bisa membedakan dari error validasi biasa.
        $response->assertStatus(409)
                 ->assertJsonFragment(['error' => "Kamar {$room->room_number} tidak tersedia untuk tanggal tersebut."]);
    }

    public function test_discount_cannot_exceed_subtotal(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $customer = Customer::create(['name' => 'Tamu Diskon', 'phone' => '081299999999']);
        $roomType = RoomType::create(['name' => 'Sakura', 'price' => 200000, 'max_person' => 2]);
        $room = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'DIS1']);

        // Subtotal cuma 200.000 x 1 malam, diskon dibuat lebih besar
        $this->actingAs($admin)
            ->withHeaders(['Referer' => 'http://localhost'])
            ->postJson('/api/v1/bookings', [
                'customer_id'     => $customer->id,
                'source'          => 'walk_in',
                'check_in'        => now()->format('Y-m-d'),
                'check_out'       => now()->addDay()->format('Y-m-d'),
                'guest_count'     => 1,
                'rooms'           => [$room->id],
                'guests'          => [['name' => 'Tamu Diskon', 'is_primary' => true]],
                'discount_amount' => 500000,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Diskon tidak boleh melebihi subtotal tagihan (subtotal: Rp 200.000).']);
    }

    public function test_valid_discount_is_applied_and_audited(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $customer = Customer::create(['name' => 'Tamu Diskon Dua', 'phone' => '081288888888']);
        $roomType = RoomType::create(['name' => 'Azalea', 'price' => 200000, 'max_person' => 2]);
        $room = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'DIS2']);

        $response = $this->actingAs($admin)
            ->withHeaders(['Referer' => 'http://localhost'])
            ->postJson('/api/v1/bookings', [
                'customer_id'     => $customer->id,
                'source'          => 'walk_in',
                'check_in'        => now()->format('Y-m-d'),
                'check_out'       => now()->addDay()->format('Y-m-d'),
                'guest_count'     => 1,
                'rooms'           => [$room->id],
                'guests'          => [['name' => 'Tamu Diskon Dua', 'is_primary' => true]],
                'discount_amount' => 50000,
            ]);

        $response->assertStatus(201);

        $tax = (float) \App\Models\Setting::first()->tax_percentage;
        $expectedTotal = round(150000 + (150000 * $tax / 100), 2);

        $this->assertDatabaseHas('bookings', [
            'id'              => $response->json('data.id'),
            'discount_amount' => 50000,
            'total_price'     => $expectedTotal,
        ]);

        // Diskon wajib tercatat di audit log (AGENTS.md bagian 6 butir 10)
        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'booking.discount_applied',
            'auditable_type' => Booking::class,
            'auditable_id'   => $response->json('data.id'),
        ]);
    }

    public function test_checked_out_booking_cannot_be_cancelled(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $customer = Customer::create(['name' => 'Tamu Batal', 'phone' => '081277777777']);
        $roomType = RoomType::create(['name' => 'Melati', 'price' => 150000, 'max_person' => 2]);
        $room = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'CAN1']);

        $booking = Booking::create([
            'booking_code' => 'BK-CANCELTEST',
            'customer_id'  => $customer->id,
            'created_by'   => $admin->id,
            'source'       => BookingSource::WalkIn->value,
            'guest_count'  => 1,
            'check_in'     => now()->format('Y-m-d'),
            'check_out'    => now()->addDay()->format('Y-m-d'),
            'status'       => BookingStatus::CheckedOut->value,
            'total_price'  => 150000,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/bookings/{$booking->id}/cancel", ['cancel_reason' => 'Salah input'])
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Reservasi berstatus checked_out tidak bisa dibatalkan lagi.']);
    }
}
