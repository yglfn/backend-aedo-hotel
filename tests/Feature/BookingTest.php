<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Room;
use App\Models\Customer;
use App\Models\RoomType;
use App\Enums\UserRole;
use App\Enums\BookingStatus;
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
                                 ['name' => 'Tamu Utama', 'is_primary' => true],
                                 ['name' => 'Tamu Tambahan 1', 'is_primary' => false],
                             ]
                         ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['message', 'data' => ['booking_code']]);

        // Pastikan harga kamar * malam = 500000 * 2 malam * 2 kamar = 2.000.000
        $this->assertDatabaseHas('bookings', [
            'total_price' => 2000000,
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

        $response->assertStatus(422)
                 ->assertJsonFragment(['error' => "Kamar {$room->room_number} tidak tersedia untuk tanggal tersebut."]);
    }
}
