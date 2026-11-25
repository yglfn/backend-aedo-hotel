<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\HousekeepingTask;
use App\Models\Maintenance;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\OperationalNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class NotificationService
{
    public function bookingCreated(Booking $booking): void
    {
        $booking->loadMissing('customer');

        $this->notifyRecipients(
            $this->activeUsers(),
            'booking',
            'booking.created',
            'Reservasi baru',
            $this->bookingSummary($booking),
            '/reservations?tab=checkin',
            ['booking_id' => $booking->id],
            Auth::id(),
        );
    }

    public function bookingStatusChanged(Booking $booking, BookingStatus $previousStatus): void
    {
        if ($previousStatus === $booking->status) {
            return;
        }

        $booking->loadMissing('customer');

        [$title, $tab] = match ($booking->status) {
            BookingStatus::Confirmed => ['Reservasi dikonfirmasi', 'checkin'],
            BookingStatus::CheckedIn => ['Tamu check-in', 'checkout'],
            BookingStatus::CheckedOut => ['Check-out selesai', 'history'],
            BookingStatus::Cancelled => ['Reservasi dibatalkan', 'history'],
            BookingStatus::Pending => ['Reservasi menunggu', 'checkin'],
        };

        $this->notifyRecipients(
            $this->activeUsers(),
            'booking',
            'booking.status_changed',
            $title,
            $this->bookingSummary($booking),
            "/reservations?tab={$tab}",
            [
                'booking_id' => $booking->id,
                'booking_status' => $booking->status->value,
            ],
            Auth::id(),
        );
    }

    public function paymentCreated(Payment $payment): void
    {
        $payment->loadMissing('booking');

        if ($payment->status === PaymentStatus::Pending) {
            $this->notifyRecipients(
                $this->activeUsers(UserRole::Admin),
                'payment',
                'payment.pending',
                'Pembayaran menunggu',
                $this->paymentSummary($payment),
                '/payments',
                $this->paymentMeta($payment),
                Auth::id(),
            );

            return;
        }

        $this->notifyPaymentStatus($payment, $payment->status === PaymentStatus::Verified
            ? 'Pembayaran terverifikasi'
            : 'Pembayaran gagal');
    }

    public function paymentStatusChanged(Payment $payment, PaymentStatus $previousStatus): void
    {
        if ($previousStatus === $payment->status) {
            return;
        }

        $title = match ($payment->status) {
            PaymentStatus::Verified => 'Pembayaran terverifikasi',
            PaymentStatus::Failed => $previousStatus === PaymentStatus::Verified
                ? 'Pembayaran dibatalkan'
                : 'Pembayaran ditolak',
            PaymentStatus::Pending => 'Pembayaran menunggu',
        };

        $this->notifyPaymentStatus($payment, $title);
    }

    public function paymentProofUploaded(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return;
        }

        $payment->loadMissing('booking');

        $this->notifyRecipients(
            $this->activeUsers(UserRole::Admin),
            'payment',
            'payment.proof_uploaded',
            'Bukti pembayaran diunggah',
            $this->paymentSummary($payment),
            '/payments',
            $this->paymentMeta($payment),
            Auth::id(),
        );
    }

    public function housekeepingCreated(HousekeepingTask $task): void
    {
        $task->loadMissing('room');

        $recipients = $task->assigned_to
            ? $this->usersByIds([$task->assigned_to])
            : $this->activeUsers(UserRole::Pegawai);

        $meta = [
            'task_id' => $task->id,
            'room_id' => $task->room_id,
        ];

        if ($task->booking_id) {
            $meta['booking_id'] = $task->booking_id;
        }

        $this->notifyRecipients(
            $recipients,
            'housekeeping',
            'housekeeping.created',
            'Housekeeping',
            "Kamar {$task->room->room_number} perlu dibersihkan",
            '/housekeeping',
            $meta,
        );
    }

    public function housekeepingCompleted(HousekeepingTask $task): void
    {
        $task->loadMissing('room');

        $recipients = $this->usersByIds([$task->created_by, $task->assigned_to])
            ->merge($this->activeUsers(UserRole::Admin));

        $this->notifyRecipients(
            $recipients,
            'housekeeping',
            'housekeeping.completed',
            'Housekeeping selesai',
            "Kamar {$task->room->room_number} selesai dibersihkan",
            '/housekeeping',
            [
                'task_id' => $task->id,
                'room_id' => $task->room_id,
            ],
            Auth::id(),
        );
    }

    public function maintenanceCreated(Maintenance $maintenance): void
    {
        $maintenance->loadMissing('room');

        $this->notifyRecipients(
            $this->activeUsers(UserRole::Admin),
            'maintenance',
            'maintenance.created',
            'Maintenance baru',
            $this->maintenanceSummary($maintenance),
            '/maintenance',
            $this->maintenanceMeta($maintenance),
            Auth::id(),
        );
    }

    public function maintenanceAssigned(Maintenance $maintenance): void
    {
        if (!$maintenance->assigned_to) {
            return;
        }

        $maintenance->loadMissing('room');

        $this->notifyRecipients(
            $this->usersByIds([$maintenance->assigned_to]),
            'maintenance',
            'maintenance.assigned',
            'Maintenance ditugaskan',
            "Kamar {$maintenance->room->room_number} ditugaskan kepada Anda",
            '/maintenance',
            $this->maintenanceMeta($maintenance),
            Auth::id(),
        );
    }

    public function maintenanceStatusChanged(Maintenance $maintenance, MaintenanceStatus $previousStatus): void
    {
        if ($previousStatus === $maintenance->status) {
            return;
        }

        $maintenance->loadMissing('room');

        $recipients = $this->usersByIds([$maintenance->reported_by, $maintenance->assigned_to])
            ->merge($this->activeUsers(UserRole::Admin));

        $title = match ($maintenance->status) {
            MaintenanceStatus::Reported => 'Maintenance dilaporkan',
            MaintenanceStatus::InProgress => 'Maintenance dikerjakan',
            MaintenanceStatus::Resolved => 'Maintenance selesai',
        };

        $this->notifyRecipients(
            $recipients,
            'maintenance',
            'maintenance.status_changed',
            $title,
            "Kamar {$maintenance->room->room_number} · {$maintenance->status->label()}",
            '/maintenance',
            array_merge($this->maintenanceMeta($maintenance), [
                'maintenance_status' => $maintenance->status->value,
            ]),
            Auth::id(),
        );
    }

    public function shiftChanged(Shift $shift, ?ShiftStatus $previousStatus = null): void
    {
        $shift->loadMissing('user');

        $started = $previousStatus === null || $shift->status === ShiftStatus::Open;
        $title = $started ? 'Shift dimulai' : 'Shift selesai';
        $message = $started
            ? "{$shift->user->name} memulai shift"
            : "Shift {$shift->user->name} selesai";

        $this->notifyRecipients(
            $this->activeUsers(UserRole::Pegawai),
            'shift',
            'shift.changed',
            $title,
            $message,
            '/dashboard',
            [
                'shift_id' => $shift->id,
                'shift_status' => $shift->status->value,
            ],
            Auth::id(),
        );
    }

    /**
     * Kirim notifikasi ringkasan auto-cancel no-show ke semua user aktif.
     * Dipanggil oleh scheduler `bookings:auto-cancel-no-show` setelah selesai.
     */
    public function noShowAutoCancelled(int $count): void
    {
        $this->notifyRecipients(
            $this->activeUsers(),
            'booking',
            'booking.auto_cancelled_no_show',
            'Pembatalan otomatis no-show',
            "{$count} reservasi dibatalkan otomatis karena tamu tidak datang (no-show).",
            '/reservations?status=cancelled',
            ['cancelled_count' => $count],
        );
    }

    public function suppressDailyReminders(User $user, iterable $notifications): void
    {
        foreach ($notifications as $notification) {
            $key = $notification->data['key'] ?? null;

            if (is_string($key) && str_starts_with($key, 'daily-')) {
                Cache::put($this->dailyReminderMarker($user, $key), true, now()->endOfDay());
            }
        }
    }

    public function sendDailyReminders(?string $date = null): void
    {
        $date = $date ?? today()->toDateString();

        $arrivals = Booking::whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
            ->where('check_in', $date)
            ->count();
        $departures = Booking::where('status', BookingStatus::CheckedIn->value)
            ->where('check_out', $date)
            ->count();
        $pendingPayments = Payment::where('status', PaymentStatus::Pending->value)->count();

        if ($arrivals === 0 && $departures === 0 && $pendingPayments === 0) {
            return;
        }

        foreach ($this->activeUsers() as $user) {
            if ($arrivals > 0) {
                $this->notifyDailyOnce(
                    $user,
                    "daily-arrivals-{$date}",
                    'reminder',
                    'reminder.arrivals',
                    'Check-in hari ini',
                    "{$arrivals} reservasi check-in hari ini",
                    '/reservations?tab=checkin',
                    ['reminder_date' => $date, 'count' => $arrivals],
                );
            }

            if ($departures > 0) {
                $this->notifyDailyOnce(
                    $user,
                    "daily-departures-{$date}",
                    'reminder',
                    'reminder.departures',
                    'Check-out hari ini',
                    "{$departures} tamu check-out hari ini",
                    '/reservations?tab=checkout',
                    ['reminder_date' => $date, 'count' => $departures],
                );
            }

            if ($pendingPayments > 0) {
                $this->notifyDailyOnce(
                    $user,
                    "daily-pending-payments-{$date}",
                    'payment',
                    'reminder.pending_payments',
                    'Pembayaran menunggu',
                    "{$pendingPayments} pembayaran menunggu verifikasi",
                    '/payments',
                    ['reminder_date' => $date, 'count' => $pendingPayments],
                );
            }
        }
    }

    private function notifyPaymentStatus(Payment $payment, string $title): void
    {
        $payment->loadMissing('booking');

        $recipients = $this->usersByIds([$payment->created_by, $payment->booking?->created_by])
            ->merge($this->activeUsers(UserRole::Admin));

        $this->notifyRecipients(
            $recipients,
            'payment',
            'payment.status_changed',
            $title,
            $this->paymentSummary($payment),
            '/payments',
            array_merge($this->paymentMeta($payment), [
                'payment_status' => $payment->status->value,
            ]),
            Auth::id(),
        );
    }

    private function notifyDailyOnce(
        User $user,
        string $key,
        string $category,
        string $event,
        string $title,
        string $message,
        string $actionUrl,
        array $meta,
    ): void {
        $marker = $this->dailyReminderMarker($user, $key);

        if (Cache::has($marker)) {
            return;
        }

        $lock = Cache::lock("notification:{$user->id}:{$key}", 10);

        if (!$lock->get()) {
            return;
        }

        try {
            $alreadySent = $user->notifications()->where('data->key', $key)->exists();

            if ($alreadySent) {
                Cache::put($marker, true, now()->endOfDay());
                return;
            }

            $user->notify(new OperationalNotification(
                $category,
                $event,
                $title,
                $message,
                $actionUrl,
                $meta,
                $key,
            ));
            Cache::put($marker, true, now()->endOfDay());
        } finally {
            $lock->release();
        }
    }

    private function dailyReminderMarker(User $user, string $key): string
    {
        return "notification:sent:{$user->id}:{$key}";
    }

    private function notifyRecipients(
        Collection $users,
        string $category,
        string $event,
        string $title,
        string $message,
        string $actionUrl,
        array $meta = [],
        ?int $exceptUserId = null,
    ): void {
        $users
            ->filter(fn (User $user) => $user->is_active && $user->id !== $exceptUserId)
            ->unique(fn (User $user) => $user->id)
            ->each(fn (User $user) => $user->notify(new OperationalNotification(
                $category,
                $event,
                $title,
                $message,
                $actionUrl,
                $meta,
            )));
    }

    private function activeUsers(?UserRole $role = null): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->when($role, fn ($query) => $query->where('role', $role->value))
            ->get();
    }

    private function usersByIds(array $ids): Collection
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));

        if (!$ids) {
            return new Collection();
        }

        return User::query()->where('is_active', true)->whereIn('id', $ids)->get();
    }

    private function bookingSummary(Booking $booking): string
    {
        $name = $booking->customer?->name ?? 'Tamu';
        $checkIn = $booking->check_in?->format('d/m/Y') ?? '-';

        return "{$booking->booking_code} · {$name} · check-in {$checkIn}";
    }

    private function paymentSummary(Payment $payment): string
    {
        $code = $payment->booking?->booking_code ?? 'Reservasi';
        $amount = number_format((float) $payment->amount, 0, ',', '.');
        $method = $this->paymentMethodLabel($payment->method);

        return "{$code} · Rp {$amount} · {$method}";
    }

    private function paymentMeta(Payment $payment): array
    {
        return [
            'payment_id' => $payment->id,
            'booking_id' => $payment->booking_id,
        ];
    }

    private function paymentMethodLabel(PaymentMethod|string $method): string
    {
        $value = $method instanceof PaymentMethod ? $method->value : $method;

        return match ($value) {
            PaymentMethod::Cash->value => 'Tunai',
            PaymentMethod::Transfer->value => 'Transfer',
            PaymentMethod::Qris->value => 'QRIS',
            default => $value,
        };
    }

    private function maintenanceSummary(Maintenance $maintenance): string
    {
        $priority = match ($maintenance->priority) {
            'critical' => 'Kritis',
            'high' => 'Tinggi',
            'medium' => 'Sedang',
            'low' => 'Rendah',
            default => $maintenance->priority,
        };

        return "Kamar {$maintenance->room->room_number} · {$priority}: " . Str::limit($maintenance->description, 100);
    }

    private function maintenanceMeta(Maintenance $maintenance): array
    {
        return [
            'maintenance_id' => $maintenance->id,
            'room_id' => $maintenance->room_id,
        ];
    }
}
