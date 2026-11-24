<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\BookingSource;
use App\Enums\HousekeepingStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\HousekeepingTask;
use App\Models\Maintenance;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Shift;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_booking_notifies_active_staff_except_the_creator(): void
    {
        $creator = $this->user(UserRole::Pegawai);
        $admin = $this->user(UserRole::Admin);
        $colleague = $this->user(UserRole::Pegawai);
        $inactive = $this->user(UserRole::Admin, false);

        $this->actingAs($creator);
        $booking = $this->booking($creator);

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(1, $colleague->notifications()->count());
        $this->assertSame(0, $creator->notifications()->count());
        $this->assertSame(0, $inactive->notifications()->count());

        $data = $admin->notifications()->first()->data;
        $this->assertSame('booking.created', $data['event']);
        $this->assertSame($booking->id, $data['booking_id']);
        $this->assertSame('/reservations?tab=checkin', $data['action_url']);
    }

    public function test_booking_status_change_notifies_other_active_staff(): void
    {
        $admin = $this->user(UserRole::Admin);
        $employee = $this->user(UserRole::Pegawai);
        $booking = $this->booking($employee, BookingStatus::Pending, false);

        $this->actingAs($admin);
        $booking->update(['status' => BookingStatus::Confirmed->value]);

        $this->assertSame(0, $admin->notifications()->count());
        $this->assertSame(1, $employee->notifications()->count());
        $this->assertSame('Reservasi dikonfirmasi', $employee->notifications()->first()->data['title']);
    }

    public function test_pending_payment_notifies_admins_and_verification_notifies_the_creator(): void
    {
        $admin = $this->user(UserRole::Admin);
        $otherAdmin = $this->user(UserRole::Admin);
        $employee = $this->user(UserRole::Pegawai);
        $booking = $this->booking($employee, BookingStatus::Pending, false);

        $this->actingAs($employee);
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'created_by' => $employee->id,
            'method' => PaymentMethod::Transfer->value,
            'type' => PaymentType::Dp->value,
            'status' => PaymentStatus::Pending->value,
            'amount' => 100000,
        ]);

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(1, $otherAdmin->notifications()->count());
        $this->assertSame(0, $employee->notifications()->count());
        $this->assertSame('payment.pending', $admin->notifications()->first()->data['event']);

        $admin->notifications()->delete();
        $otherAdmin->notifications()->delete();

        $this->actingAs($admin);
        $payment->update([
            'status' => PaymentStatus::Verified->value,
            'verified_by' => $admin->id,
        ]);

        $this->assertSame(1, $employee->notifications()->count());
        $this->assertSame(0, $admin->notifications()->count());
        $this->assertSame(1, $otherAdmin->notifications()->count());
        $this->assertSame('Pembayaran terverifikasi', $employee->notifications()->first()->data['title']);
    }

    public function test_housekeeping_completion_notifies_the_creator_and_admin(): void
    {
        $admin = $this->user(UserRole::Admin);
        $employee = $this->user(UserRole::Pegawai);
        $room = $this->room();
        $task = HousekeepingTask::withoutEvents(fn () => HousekeepingTask::create([
            'room_id' => $room->id,
            'created_by' => $employee->id,
            'status' => HousekeepingStatus::Pending->value,
        ]));

        $this->actingAs($employee);
        $task->update(['status' => HousekeepingStatus::Completed->value]);

        $this->assertSame(0, $employee->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame('housekeeping.completed', $admin->notifications()->first()->data['event']);
    }

    public function test_maintenance_report_assignment_and_progress_reach_the_right_users(): void
    {
        $admin = $this->user(UserRole::Admin);
        $reporter = $this->user(UserRole::Pegawai);
        $technician = $this->user(UserRole::Pegawai);
        $room = $this->room();

        $this->actingAs($reporter);
        $maintenance = Maintenance::create([
            'room_id' => $room->id,
            'reported_by' => $reporter->id,
            'description' => 'AC tidak dingin',
            'priority' => 'high',
            'status' => MaintenanceStatus::Reported->value,
        ]);

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $reporter->notifications()->count());
        $this->assertSame('maintenance.created', $admin->notifications()->first()->data['event']);

        $admin->notifications()->delete();
        $this->actingAs($admin);
        $maintenance->update(['assigned_to' => $technician->id]);

        $this->assertSame(1, $technician->notifications()->count());
        $this->assertSame('maintenance.assigned', $technician->notifications()->first()->data['event']);

        $technician->notifications()->delete();
        $this->actingAs($technician);
        $maintenance->update(['status' => MaintenanceStatus::InProgress->value]);

        $this->assertSame(1, $reporter->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $technician->notifications()->count());
    }

    public function test_shift_events_notify_other_active_employees_only(): void
    {
        $employee = $this->user(UserRole::Pegawai);
        $colleague = $this->user(UserRole::Pegawai);
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($employee);
        $shift = Shift::create([
            'user_id' => $employee->id,
            'started_at' => now(),
            'status' => ShiftStatus::Open->value,
        ]);

        $this->assertSame(1, $colleague->notifications()->count());
        $this->assertSame(0, $employee->notifications()->count());
        $this->assertSame(0, $admin->notifications()->count());
        $this->assertSame('Shift dimulai', $colleague->notifications()->first()->data['title']);

        $colleague->notifications()->delete();
        $shift->update([
            'ended_at' => now(),
            'status' => ShiftStatus::Closed->value,
        ]);

        $this->assertSame(1, $colleague->notifications()->count());
        $this->assertSame('Shift selesai', $colleague->notifications()->first()->data['title']);
    }

    public function test_daily_reminders_are_idempotent_and_skip_inactive_users(): void
    {
        $admin = $this->user(UserRole::Admin);
        $employee = $this->user(UserRole::Pegawai);
        $inactive = $this->user(UserRole::Pegawai, false);
        $today = today()->toDateString();

        $this->booking($employee, BookingStatus::Pending, false, $today);
        $this->booking(
            $employee,
            BookingStatus::CheckedIn,
            false,
            today()->subDay()->toDateString(),
            $today,
        );
        $booking = Booking::withoutEvents(fn () => Booking::create([
            'booking_code' => 'BK-' . strtoupper(Str::random(8)),
            'customer_id' => Customer::create(['name' => 'Tamu Pembayaran'])->id,
            'created_by' => $employee->id,
            'source' => BookingSource::WalkIn->value,
            'guest_count' => 1,
            'check_in' => $today,
            'check_out' => today()->addDay()->toDateString(),
            'status' => BookingStatus::Pending->value,
            'total_price' => 100000,
        ]));

        Payment::withoutEvents(fn () => Payment::create([
            'booking_id' => $booking->id,
            'created_by' => $employee->id,
            'method' => PaymentMethod::Transfer->value,
            'type' => PaymentType::Final->value,
            'status' => PaymentStatus::Pending->value,
            'amount' => 100000,
        ]));

        app(NotificationService::class)->sendDailyReminders($today);
        $this->artisan('notifications:daily-operational')->assertSuccessful();

        $this->assertSame(3, $admin->notifications()->count());
        $this->assertSame(3, $employee->notifications()->count());
        $this->assertSame(0, $inactive->notifications()->count());

        $keys = $admin->notifications()->get()->pluck('data.key')->sort()->values()->all();
        $this->assertSame([
            "daily-arrivals-{$today}",
            "daily-departures-{$today}",
            "daily-pending-payments-{$today}",
        ], $keys);
    }

    private function user(UserRole $role, bool $active = true): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    private function booking(
        User $creator,
        BookingStatus $status = BookingStatus::Pending,
        bool $withEvents = true,
        ?string $checkIn = null,
        ?string $checkOut = null,
    ): Booking {
        $checkIn ??= today()->toDateString();
        $data = [
            'booking_code' => 'BK-' . strtoupper(Str::random(8)),
            'customer_id' => Customer::create(['name' => 'Tamu Notifikasi'])->id,
            'created_by' => $creator->id,
            'source' => BookingSource::WalkIn->value,
            'guest_count' => 1,
            'check_in' => $checkIn,
            'check_out' => $checkOut ?? today()->parse($checkIn)->addDay()->toDateString(),
            'status' => $status->value,
            'total_price' => 250000,
        ];

        return $withEvents
            ? Booking::create($data)
            : Booking::withoutEvents(fn () => Booking::create($data));
    }

    private function room(): Room
    {
        $type = RoomType::create([
            'name' => 'Standard',
            'price' => 250000,
            'max_person' => 2,
            'facilities' => 'WiFi',
        ]);

        return Room::create([
            'room_type_id' => $type->id,
            'room_number' => 'N' . Str::random(4),
            'floor' => 1,
        ]);
    }
}
