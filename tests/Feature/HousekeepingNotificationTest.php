<?php

namespace Tests\Feature;

use App\Enums\HousekeepingStatus;
use App\Enums\RoomStatus;
use App\Enums\UserRole;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class HousekeepingNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_housekeeping_task_notifies_active_pegawai(): void
    {
        $pegawai = User::factory()->create([
            'role' => UserRole::Pegawai,
            'is_active' => true,
        ]);
        $inactive = User::factory()->create([
            'role' => UserRole::Pegawai,
            'is_active' => false,
        ]);
        $room = $this->createRoom();

        HousekeepingTask::create([
            'room_id' => $room->id,
            'created_by' => $pegawai->id,
            'status' => HousekeepingStatus::Pending,
        ]);

        $notification = $pegawai->notifications()->first();

        $this->assertNotNull($notification);
        $this->assertSame($room->id, $notification->data['room_id']);
        $this->assertSame("Kamar {$room->room_number} perlu dibersihkan", $notification->data['message']);
        $this->assertSame('/housekeeping', $notification->data['action_url']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $inactive->id]);
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Pegawai, 'is_active' => true]);
        $other = User::factory()->create(['role' => UserRole::Pegawai, 'is_active' => true]);
        $notification = DatabaseNotification::create([
            'id' => 'notification-owner',
            'type' => 'App\\Notifications\\RoomNeedsCleaning',
            'notifiable_type' => User::class,
            'notifiable_id' => $owner->id,
            'data' => ['message' => 'Kamar 101 perlu dibersihkan'],
        ]);

        $this->actingAs($other)->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertNotFound();

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'read_at' => null,
        ]);
    }

    public function test_notification_index_returns_correct_unread_count(): void
    {
        $pegawai = User::factory()->create(['role' => UserRole::Pegawai, 'is_active' => true]);
        DatabaseNotification::create([
            'id' => 'unread-notification',
            'type' => 'App\\Notifications\\RoomNeedsCleaning',
            'notifiable_type' => User::class,
            'notifiable_id' => $pegawai->id,
            'data' => ['message' => 'Kamar 101 perlu dibersihkan'],
        ]);
        DatabaseNotification::create([
            'id' => 'read-notification',
            'type' => 'App\\Notifications\\RoomNeedsCleaning',
            'notifiable_type' => User::class,
            'notifiable_id' => $pegawai->id,
            'data' => ['message' => 'Kamar 102 perlu dibersihkan'],
            'read_at' => now(),
        ]);

        $response = $this->actingAs($pegawai)->getJson('/api/v1/notifications')->assertOk();
        $response->assertJsonPath('unread_count', 1);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment([
            'category' => 'housekeeping',
            'action_url' => '/housekeeping',
        ]);
    }

    public function test_read_all_only_marks_the_authenticated_users_notifications(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Pegawai, 'is_active' => true]);
        $other = User::factory()->create(['role' => UserRole::Pegawai, 'is_active' => true]);

        foreach ([$owner, $other] as $user) {
            DatabaseNotification::create([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\RoomNeedsCleaning',
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => ['message' => 'Kamar 101 perlu dibersihkan'],
            ]);
        }

        $this->actingAs($owner)->postJson('/api/v1/notifications/read-all')->assertOk();

        $this->assertSame(0, $owner->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    private function createRoom(): Room
    {
        $roomType = RoomType::create([
            'name' => 'Standard',
            'price' => 250000,
            'max_person' => 2,
            'facilities' => 'WiFi',
        ]);

        return Room::create([
            'room_type_id' => $roomType->id,
            'room_number' => '101',
            'floor' => 1,
            'status' => RoomStatus::Cleaning,
        ]);
    }
}
