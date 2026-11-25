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
        Setting::firstOrCreate(
            [],
            [
                'hotel_name' => 'Aedo Hotel Syariah',
                'default_check_in' => '14:00:00',
                'default_check_out' => '12:00:00',
                'tax_percentage' => 11,
                'payment_verification_enabled' => true,
            ]
        );

        // 2. Admin User
        User::firstOrCreate(
            ['email' => 'admin@aedohotel.com'],
            [
                'name' => 'Admin Utama',
                'password' => Hash::make('password123'),
                'role' => UserRole::Admin,
                'is_active' => true,
            ]
        );

        // 3. Pegawai User
        User::firstOrCreate(
            ['email' => 'pegawai@aedohotel.com'],
            [
                'name' => 'Pegawai Satu',
                'password' => Hash::make('password123'),
                'role' => UserRole::Pegawai,
                'is_active' => true,
            ]
        );

        // 4. Room Types
        // Hanya data awal. Selanjutnya tipe kamar dikelola lewat menu "Tipe Kamar",
        // jadi memakai updateOrCreate agar seeder ulang tidak menduplikasi data.
        $standard = RoomType::updateOrCreate(
            ['name' => 'Standard Room'],
            [
                'price' => 350000,
                'max_person' => 2,
                'facilities' => 'AC, TV, Queen Bed, WiFi',
            ]
        );

        $deluxe = RoomType::updateOrCreate(
            ['name' => 'Deluxe Room'],
            [
                'price' => 500000,
                'max_person' => 2,
                'facilities' => 'AC, Smart TV, King Bed, WiFi, Minibar',
            ]
        );

        // 5. Rooms
        Room::updateOrCreate(['room_number' => '101'], ['room_type_id' => $standard->id, 'floor' => 1]);
        Room::updateOrCreate(['room_number' => '102'], ['room_type_id' => $standard->id, 'floor' => 1]);
        Room::updateOrCreate(['room_number' => '201'], ['room_type_id' => $deluxe->id, 'floor' => 2]);
        Room::updateOrCreate(['room_number' => '202'], ['room_type_id' => $deluxe->id, 'floor' => 2]);
    }
}
