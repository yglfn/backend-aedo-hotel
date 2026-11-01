<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Model C: penanggung jawab wajib saat reservasi, penghuni lain boleh menyusul,
 * tetapi check-in baru boleh terjadi kalau seluruh penghuni sudah punya nama.
 *
 * Aturan yang dijaga di sini:
 * - jumlah baris tamu tidak boleh melebihi guest_count (data vs angka harus nyambung)
 * - tepat satu tamu berstatus is_primary
 * - reservasi tetap boleh disimpan dengan 1 nama untuk 2 orang (tamu belum tahu temannya)
 * - check-in menolak reservasi yang penghuninya belum lengkap
 * - check-in boleh sekaligus melengkapi penghuni, tanpa bisa menyisipkan tamu di luar slot
 */
class BookingGuestRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        $this->admin = User::where('role', UserRole::Admin)->first();
    }

    /** Reservasi 1 kamar @200.000 dengan tamu sesuai permintaan caller. */
    protected function book(int $guestCount, array $guests, string $roomNumber): array
    {
        $customer = Customer::create(['name' => 'Pemesan ' . $roomNumber, 'phone' => '081233' . random_int(10000, 99999)]);
        $roomType = RoomType::create(['name' => 'Tipe ' . $roomNumber, 'price' => 200000, 'max_person' => max(2, $guestCount)]);
        $room     = Room::create(['room_type_id' => $roomType->id, 'room_number' => $roomNumber]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/bookings', [
            'customer_id' => $customer->id,
            'source'      => 'walk_in',
            'check_in'    => now()->format('Y-m-d'),
            'check_out'   => now()->addDay()->format('Y-m-d'),
            'guest_count' => $guestCount,
            'rooms'       => [$room->id],
            'guests'      => $guests,
        ]);

        return ['response' => $response, 'booking' => Booking::find($response->json('data.id')), 'room' => $room];
    }

    protected function confirm(Booking $booking): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", ['reason' => 'Uji data penghuni'])
            ->assertStatus(200);
    }

    public function test_all_occupants_are_recorded_at_booking(): void
    {
        $result = $this->book(2, [
            ['name' => 'Budi Penanggung Jawab', 'ktp' => '3201000000000001', 'phone' => '081200001111', 'is_primary' => true],
            ['name' => 'Sari Pendamping', 'ktp' => '3201000000000002', 'is_primary' => false],
        ], 'GST01');

        $result['response']->assertStatus(201);

        $this->assertSame(2, $result['booking']->bookingGuests()->count());
        $this->assertSame(1, $result['booking']->bookingGuests()->where('is_primary', true)->count());

        // Telepon penghuni sekarang ikut tersimpan (kolomnya sudah ada sejak awal)
        $this->assertDatabaseHas('booking_guests', [
            'booking_id' => $result['booking']->id,
            'name'       => 'Sari Pendamping',
            'is_primary' => false,
        ]);
        $this->assertSame('081200001111', $result['booking']->bookingGuests()->where('is_primary', true)->value('phone'));
    }

    public function test_more_guest_rows_than_guest_count_is_rejected(): void
    {
        $response = $this->book(2, [
            ['name' => 'Tamu Satu', 'is_primary' => true],
            ['name' => 'Tamu Dua', 'is_primary' => false],
            ['name' => 'Tamu Tiga', 'is_primary' => false],
        ], 'GST02')['response'];

        $response->assertStatus(422)
            ->assertJsonFragment(['guests' => ['Jumlah data tamu (3) melebihi jumlah tamu yang dipesan (2 orang).']]);
    }

    public function test_exactly_one_primary_guest_is_required(): void
    {
        // Dua penanggung jawab
        $twoPrimary = $this->book(2, [
            ['name' => 'Kandidat A', 'is_primary' => true],
            ['name' => 'Kandidat B', 'is_primary' => true],
        ], 'GST03')['response'];

        $twoPrimary->assertStatus(422)
            ->assertJsonFragment(['guests' => ['Harus ada tepat satu penanggung jawab reservasi (is_primary).']]);

        // Tidak ada sama sekali
        $noPrimary = $this->book(1, [
            ['name' => 'Tanpa Penanggung Jawab', 'is_primary' => false],
        ], 'GST04')['response'];

        $noPrimary->assertStatus(422)
            ->assertJsonFragment(['guests' => ['Harus ada tepat satu penanggung jawab reservasi (is_primary).']]);
    }

    public function test_booking_may_stay_with_one_name_for_two_guests(): void
    {
        // Sah menurut Model C: teman ikut menginap tapi namanya belum diketahui penelepon
        $result = $this->book(2, [
            ['name' => 'Pemesan Saja', 'is_primary' => true],
        ], 'GST05');

        $result['response']->assertStatus(201);
        $this->assertSame(1, $result['booking']->bookingGuests()->count());
    }

    public function test_checkin_blocked_until_every_occupant_is_named(): void
    {
        $booking = $this->book(2, [
            ['name' => 'Pemesan Saja', 'is_primary' => true],
        ], 'GST06')['booking'];

        $this->confirm($booking);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$booking->id}/check-in")
            ->assertStatus(422)
            ->assertJsonFragment([
                'error' => 'Data penghuni belum lengkap: 1 dari 2 tamu tercatat. Lengkapi nama seluruh penghuni sebelum check-in.',
            ]);

        $this->assertSame(BookingStatus::Confirmed->value, $booking->fresh()->status->value);
    }

    public function test_checkin_can_complete_missing_occupants_in_one_call(): void
    {
        $booking = $this->book(2, [
            ['name' => 'Pemesan Saja', 'is_primary' => true],
        ], 'GST07')['booking'];

        $this->confirm($booking);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$booking->id}/check-in", [
                'guests' => [
                    ['name' => 'Tamu Datang Bareng', 'ktp' => '3201000000000009'],
                ],
            ])
            ->assertStatus(200);

        $this->assertSame(BookingStatus::CheckedIn->value, $booking->fresh()->status->value);
        $this->assertSame(2, $booking->bookingGuests()->count());
        $this->assertSame(1, $booking->bookingGuests()->where('is_primary', true)->count());

        // Penambahan penghuni adalah perubahan data transaksi: wajib ada jejak audit
        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'booking.guests_completed',
            'auditable_type' => Booking::class,
            'auditable_id'   => $booking->id,
        ]);
    }

    public function test_checkin_cannot_smuggle_extra_guests_beyond_the_slot(): void
    {
        $booking = $this->book(2, [
            ['name' => 'Pemesan Saja', 'is_primary' => true],
        ], 'GST08')['booking'];

        $this->confirm($booking);

        // Tiga nama dikirim padahal sisa slot cuma satu: hanya satu yang boleh masuk
        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$booking->id}/check-in", [
                'guests' => [
                    ['name' => 'Tamu Tambahan Satu'],
                    ['name' => 'Tamu Tambahan Dua'],
                    ['name' => 'Tamu Tambahan Tiga'],
                ],
            ])
            ->assertStatus(200);

        $this->assertSame(2, $booking->bookingGuests()->count());
        $this->assertDatabaseHas('booking_guests', ['booking_id' => $booking->id, 'name' => 'Tamu Tambahan Satu']);
        $this->assertDatabaseMissing('booking_guests', ['booking_id' => $booking->id, 'name' => 'Tamu Tambahan Dua']);
    }

    public function test_duplicate_name_at_checkin_is_not_recorded_twice(): void
    {
        $booking = $this->book(2, [
            ['name' => 'Pemesan Saja', 'is_primary' => true],
        ], 'GST09')['booking'];

        $this->confirm($booking);

        // Nama yang sama dikirim dua kali: tetap kurang satu penghuni, jadi check-in ditolak
        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$booking->id}/check-in", [
                'guests' => [['name' => 'pemesan saja'], ['name' => 'Tamu Baru']],
            ])
            ->assertStatus(200);

        $this->assertSame(2, $booking->bookingGuests()->count());
        $this->assertDatabaseHas('booking_guests', ['booking_id' => $booking->id, 'name' => 'Tamu Baru']);
    }

    // ------------------------------------------------------------------
    // Penempatan penghuni ke kamar (reservasi multi-kamar)
    // ------------------------------------------------------------------

    /**
     * Reservasi dengan beberapa kamar; $rooms = [nomor => kapasitas].
     * $assign: daftar nomor kamar (index penghuni => nomor kamar) untuk mengisi room_id,
     * karena kamar baru dibuat di dalam helper ini.
     */
    protected function bookRooms(array $rooms, int $guestCount, array $guests, array $assign = []): array
    {
        $customer = Customer::create(['name' => 'Pemesan Multi', 'phone' => '081234' . random_int(10000, 99999)]);
        $roomIds  = [];

        foreach ($rooms as $number => $capacity) {
            $roomType = RoomType::create(['name' => "Tipe {$number}", 'price' => 200000, 'max_person' => $capacity]);
            $room     = Room::create(['room_type_id' => $roomType->id, 'room_number' => $number]);
            $roomIds[$number] = $room->id;
        }

        foreach ($assign as $index => $number) {
            $guests[$index]['room_id'] = $roomIds[$number] ?? null;
        }

        $response = $this->actingAs($this->admin)->postJson('/api/v1/bookings', [
            'customer_id' => $customer->id,
            'source'      => 'walk_in',
            'check_in'    => now()->format('Y-m-d'),
            'check_out'   => now()->addDay()->format('Y-m-d'),
            'guest_count' => $guestCount,
            'rooms'       => array_values($roomIds),
            'guests'      => $guests,
        ]);

        return ['response' => $response, 'booking' => Booking::find($response->json('data.id')), 'roomIds' => $roomIds];
    }

    /** Reservasi dua kamar dengan dua penghuni, masing-masing satu orang per kamar. */
    protected function makeTwoRoomBooking(int $guestCount = 2): Booking
    {
        $suffix = random_int(1000, 9999);

        $customer = Customer::create(['name' => 'Pemesan Dua Kamar', 'phone' => '081239' . $suffix]);
        $typeA = RoomType::create(['name' => "Tipe A {$suffix}", 'price' => 200000, 'max_person' => 2]);
        $typeB = RoomType::create(['name' => "Tipe B {$suffix}", 'price' => 250000, 'max_person' => 2]);
        $roomA = Room::create(['room_type_id' => $typeA->id, 'room_number' => 'A' . $suffix]);
        $roomB = Room::create(['room_type_id' => $typeB->id, 'room_number' => 'B' . $suffix]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/bookings', [
            'customer_id' => $customer->id,
            'source'      => 'walk_in',
            'check_in'    => now()->format('Y-m-d'),
            'check_out'   => now()->addDay()->format('Y-m-d'),
            'guest_count' => $guestCount,
            'rooms'       => [$roomA->id, $roomB->id],
            'guests'      => [
                ['name' => 'Penghuni Kamar A', 'is_primary' => true, 'room_id' => $roomA->id],
                ['name' => 'Penghuni Kamar B', 'is_primary' => false, 'room_id' => $roomB->id],
            ],
        ]);

        $response->assertStatus(201);

        return Booking::find($response->json('data.id'));
    }

    public function test_multi_room_booking_requires_a_room_per_guest(): void
    {
        $response = $this->bookRooms(
            ['MUL01' => 2, 'MUL02' => 2],
            2,
            [
                ['name' => 'Adi', 'is_primary' => true],
                ['name' => 'Bela', 'is_primary' => false],
            ]
        )['response'];

        $response->assertStatus(422)
            ->assertJsonValidationErrors('guests.0.room_id')
            ->assertJsonFragment(['guests.0.room_id' => ['Pilih kamar untuk setiap penghuni pada reservasi multi-kamar.']]);
    }

    public function test_multi_room_guests_are_placed_explicitly(): void
    {
        $result = $this->bookRooms(
            ['MUL03' => 2, 'MUL04' => 2],
            2,
            [
                ['name' => 'Citra', 'is_primary' => true],
                ['name' => 'Dodi', 'is_primary' => false],
            ],
            ['MUL03', 'MUL04']
        );
        $result['response']->assertStatus(201);

        $booking = $result['booking'];
        $bookedRooms = $booking->bookingRooms()->pluck('id', 'room_id');

        $this->assertSame($bookedRooms[$result['roomIds']['MUL03']], $booking->bookingGuests()->where('name', 'Citra')->value('booking_room_id'));
        $this->assertSame($bookedRooms[$result['roomIds']['MUL04']], $booking->bookingGuests()->where('name', 'Dodi')->value('booking_room_id'));

        // API mengembalikan penempatannya supaya layar detail bisa menampilkan kamarnya
        $detail = $this->actingAs($this->admin)->getJson("/api/v1/bookings/{$booking->id}")
            ->assertStatus(200)->json('data.booking_guests');

        $perRoom = collect($detail)->pluck('booking_room_id', 'name');
        $this->assertNotNull($perRoom['Citra']);
        $this->assertNotSame($perRoom['Citra'], $perRoom['Dodi']);
    }

    public function test_single_room_guest_is_placed_automatically(): void
    {
        $result = $this->bookRooms(
            ['MUL20' => 2],
            2,
            [['name' => 'Iwan', 'is_primary' => true]]
        );
        $result['response']->assertStatus(201);

        $booking = $result['booking'];
        $expected = $booking->bookingRooms()->where('room_id', $result['roomIds']['MUL20'])->value('id');

        // Satu kamar: tidak ambigu, service menautkan sendiri
        $this->assertSame($expected, $booking->bookingGuests()->where('name', 'Iwan')->value('booking_room_id'));
    }

    public function test_room_outside_the_booking_cannot_be_assigned(): void
    {
        $liar = RoomType::create(['name' => 'Tipe Liar', 'price' => 100000, 'max_person' => 2]);
        $liarRoom = Room::create(['room_type_id' => $liar->id, 'room_number' => 'LIAR01']);

        $this->bookRooms(
            ['MUL07' => 2],
            1,
            [['name' => 'Feri', 'is_primary' => true, 'room_id' => $liarRoom->id]]
        )['response']->assertStatus(422);
    }

    public function test_room_capacity_is_enforced_per_room_at_booking(): void
    {
        // Kamar pertama ditumpuki 3 orang padahal kapasitasnya 2
        $this->bookRooms(
            ['MUL40' => 2, 'MUL41' => 2],
            4,
            [
                ['name' => 'Satu', 'is_primary' => true],
                ['name' => 'Dua', 'is_primary' => false],
                ['name' => 'Tiga', 'is_primary' => false],
                ['name' => 'Empat', 'is_primary' => false],
            ],
            ['MUL40', 'MUL40', 'MUL40', 'MUL41']
        )['response']->assertStatus(422)
            ->assertJsonFragment(['error' => 'Kamar MUL40 berkapasitas 2 orang, tetapi 3 penghuni ditugaskan ke kamar ini.']);
    }

    public function test_room_capacity_is_enforced_per_room_at_checkin(): void
    {
        // Empat orang di dua kamar (2+2); dua penghuni terakhir ditumpuk ke kamar yang sama
        $booking = $this->makeTwoRoomBooking(4);
        $this->confirm($booking);

        $kamarB = $booking->bookingRooms()->skip(1)->first();
        $nomorB = $kamarB->room->room_number;

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$booking->id}/check-in", [
                'guests' => [
                    ['name' => 'Hadi', 'room_id' => $kamarB->room_id],
                    ['name' => 'Ines', 'room_id' => $kamarB->room_id],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'error' => "Kamar {$nomorB} berkapasitas 2 orang, tetapi 3 penghuni ditugaskan ke kamar ini.",
            ]);

        // Transaksi dibatalkan penuh: data lama tidak boleh tertinggal separuh
        $this->assertSame(2, $booking->bookingGuests()->count());
        $this->assertSame(BookingStatus::Confirmed->value, $booking->fresh()->status->value);
    }

    public function test_checkin_is_blocked_when_placement_incomplete(): void
    {
        $booking = $this->makeTwoRoomBooking();
        $this->confirm($booking);

        // Data lama / salah input: satu penghuni kehilangan kamarnya
        $booking->bookingGuests()->where('is_primary', false)->update(['booking_room_id' => null]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$booking->id}/check-in")
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Penempatan kamar belum lengkap: 1 penghuni belum ditugaskan ke kamar.']);

        $this->assertSame(BookingStatus::Confirmed->value, $booking->fresh()->status->value);
    }

    public function test_checkin_can_fix_placement_without_duplicating_names(): void
    {
        $booking = $this->makeTwoRoomBooking();
        $this->confirm($booking);

        $lost = $booking->bookingGuests()->where('is_primary', false)->first();
        $lost->update(['booking_room_id' => null]);

        // Kasir mengirim ulang nama yang sudah ada bersama kamarnya
        $this->actingAs($this->admin)
            ->postJson("/api/v1/bookings/{$booking->id}/check-in", [
                'guests' => [['name' => $lost->name, 'room_id' => $lost->bookingRoom?->room_id ?: $booking->bookingRooms()->value('room_id')]],
            ])
            ->assertStatus(200);

        $this->assertSame(2, $booking->bookingGuests()->count());
        $this->assertNotNull($booking->bookingGuests()->where('id', $lost->id)->value('booking_room_id'));
        $this->assertSame(BookingStatus::CheckedIn->value, $booking->fresh()->status->value);
    }
}
