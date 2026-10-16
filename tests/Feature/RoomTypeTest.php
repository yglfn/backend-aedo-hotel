<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomTypeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $pegawai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed');

        $this->admin   = User::where('role', UserRole::Admin)->first();
        $this->pegawai = User::where('role', UserRole::Pegawai)->first();
    }

    public function test_admin_can_create_room_type(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/room-types', [
            'name'       => 'Suite',
            'price'      => 750000,
            'max_person' => 3,
            'facilities' => 'AC, Sofa Bed, Bathtub',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Suite');

        $this->assertDatabaseHas('room_types', ['name' => 'Suite', 'max_person' => 3]);
    }

    public function test_room_type_name_must_be_unique(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/room-types', [
            'name'       => 'Standard Room',
            'price'      => 400000,
            'max_person' => 2,
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name'])
                 ->assertJsonPath('errors.name.0', 'Nama tipe kamar ini sudah digunakan.');
    }

    public function test_pegawai_can_view_but_not_manage_room_types(): void
    {
        $type = RoomType::first();

        $this->actingAs($this->pegawai)->getJson('/api/v1/room-types')->assertStatus(200);

        $this->actingAs($this->pegawai)->postJson('/api/v1/room-types', [
            'name'       => 'Tipe Ilegal',
            'price'      => 100000,
            'max_person' => 2,
        ])->assertStatus(403);

        $this->actingAs($this->pegawai)
            ->putJson("/api/v1/room-types/{$type->id}", ['price' => 1])
            ->assertStatus(403);

        $this->actingAs($this->pegawai)
            ->deleteJson("/api/v1/room-types/{$type->id}")
            ->assertStatus(403);
    }

    public function test_admin_can_update_price_and_change_is_audit_logged(): void
    {
        $type = RoomType::create(['name' => 'Superior', 'price' => 450000, 'max_person' => 2]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/room-types/{$type->id}", ['price' => 520000])
            ->assertStatus(200);

        $this->assertSame(520000.0, (float) $type->fresh()->price);

        $this->assertDatabaseHas('audit_logs', [
            'action'         => 'room_type.updated',
            'auditable_type' => RoomType::class,
            'auditable_id'   => $type->id,
        ]);
    }

    public function test_room_type_still_used_by_rooms_cannot_be_deleted(): void
    {
        $type = RoomType::create(['name' => 'Family', 'price' => 800000, 'max_person' => 4]);
        Room::create(['room_type_id' => $type->id, 'room_number' => 'F1', 'floor' => 1]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/room-types/{$type->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tipe kamar "Family" masih dipakai oleh 1 kamar. Pindahkan atau hapus kamar-kamar tersebut terlebih dahulu.');

        $this->assertDatabaseHas('room_types', ['id' => $type->id, 'deleted_at' => null]);
    }

    public function test_unused_room_type_can_be_soft_deleted(): void
    {
        $type = RoomType::create(['name' => 'Villa', 'price' => 900000, 'max_person' => 5]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/room-types/{$type->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('room_types', ['id' => $type->id]);
    }

    public function test_index_supports_search_and_returns_rooms_count(): void
    {
        $type = RoomType::create(['name' => 'Penthouse', 'price' => 1200000, 'max_person' => 2]);
        Room::create(['room_type_id' => $type->id, 'room_number' => 'P1', 'floor' => 3]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/room-types?search=Penthouse');

        $response->assertStatus(200)
                 ->assertJsonPath('data.0.name', 'Penthouse')
                 ->assertJsonPath('data.0.rooms_count', 1);
    }
}
