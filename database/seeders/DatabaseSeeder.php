<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Setting;
use App\Enums\UserRole;
use App\Models\RoomType;
use App\Models\Room;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Setting
        Setting::create([
            'hotel_name' => 'Aedo Hotel Syariah',
            'default_check_in' => '14:00:00',
            'default_check_out' => '12:00:00',
            'tax_percentage' => 11,
            'payment_expiry_minutes' => 60,
        ]);

        // 2. Admin User
        User::create([
            'name' => 'Admin Utama',
            'email' => 'admin@aedohotel.com',
            'password' => Hash::make('password123'),
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        // 3. Pegawai User
        User::create([
            'name' => 'Pegawai Satu',
            'email' => 'pegawai@aedohotel.com',
            'password' => Hash::make('password123'),
            'role' => UserRole::Pegawai,
            'is_active' => true,
        ]);

        // 4. Room Types
        $standard = RoomType::create([
            'name' => 'Standard Room',
            'price' => 350000,
            'max_person' => 2,
            'facilities' => 'AC, TV, Queen Bed, WiFi',
        ]);
        
        $deluxe = RoomType::create([
            'name' => 'Deluxe Room',
            'price' => 500000,
            'max_person' => 2,
            'facilities' => 'AC, Smart TV, King Bed, WiFi, Minibar',
        ]);

        // 5. Rooms
        Room::create(['room_type_id' => $standard->id, 'room_number' => '101', 'floor' => 1]);
        Room::create(['room_type_id' => $standard->id, 'room_number' => '102', 'floor' => 1]);
        Room::create(['room_type_id' => $deluxe->id, 'room_number' => '201', 'floor' => 2]);
        Room::create(['room_type_id' => $deluxe->id, 'room_number' => '202', 'floor' => 2]);
    }
}
