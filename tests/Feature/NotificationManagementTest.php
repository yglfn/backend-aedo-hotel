<?php

namespace Tests\Feature;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_delete_own_notification(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Pegawai, 'is_active' => true]);
        $notification = $this->notification($owner);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Notifikasi dihapus.');

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);

        $this->actingAs($owner)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_user_cannot_delete_another_users_notification(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Pegawai, 'is_active' => true]);
        $other = User::factory()->create(['role' => UserRole::Pegawai, 'is_active' => true]);
        $notification = $this->notification($owner);

        $this->actingAs($other)
            ->deleteJson("/api/v1/notifications/{$notification->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'read_at' => null,
        ]);
    }

    public function test_user_can_clear_all_own_notifications(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Pegawai, 'is_active' => true]);
        $other = User::factory()->create(['role' => UserRole::Pegawai, 'is_active' => true]);
        $unread = $this->notification($owner);
        $read = $this->notification($owner, true);
        $otherNotification = $this->notification($other);

        $this->actingAs($owner)
            ->deleteJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('message', 'Semua notifikasi dihapus.')
            ->assertJsonPath('deleted_count', 2);

        $this->assertDatabaseMissing('notifications', ['id' => $unread->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $read->id]);
        $this->assertDatabaseHas('notifications', ['id' => $otherNotification->id]);
    }

    public function test_deleted_daily_reminder_does_not_reappear_on_the_next_fetch(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $today = today()->toDateString();

        Booking::withoutEvents(fn () => Booking::create([
            'booking_code' => 'BK-' . strtoupper(Str::random(8)),
            'customer_id' => Customer::create(['name' => 'Tamu Pengingat'])->id,
            'created_by' => $admin->id,
            'source' => BookingSource::WalkIn->value,
            'guest_count' => 1,
            'check_in' => $today,
            'check_out' => today()->addDay()->toDateString(),
            'status' => BookingStatus::Pending->value,
            'total_price' => 250000,
        ]));

        $response = $this->actingAs($admin)->getJson('/api/v1/notifications')->assertOk();
        $notificationId = $response->json('data.0.id');
        $this->assertNotNull($notificationId);
        $this->assertSame("daily-arrivals-{$today}", $response->json('data.0.data.key'));

        $this->actingAs($admin)
            ->deleteJson("/api/v1/notifications/{$notificationId}")
            ->assertOk();

        $this->actingAs($admin)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonCount(0, 'data');
    }

    private function notification(User $user, bool $read = false): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\OperationalNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'category' => 'reminder',
                'title' => 'Pengingat',
                'message' => 'Notifikasi pengujian',
                'action_url' => '/dashboard',
            ],
            'read_at' => $read ? now() : null,
        ]);
    }
}
