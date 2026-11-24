<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Notifications\RoomNeedsCleaning;
use App\Services\NotificationService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notificationService)
    {
    }

    public function index(Request $request)
    {
        $this->notificationService->sendDailyReminders();
        $user = $request->user();

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'data' => $user->notifications()
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (DatabaseNotification $notification) => $this->serialize($notification)),
        ]);
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'Semua notifikasi ditandai sudah dibaca.']);
    }

    public function read(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json([
            'message' => 'Notifikasi ditandai sudah dibaca.',
            'data' => $this->serialize($notification->fresh()),
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $notification = $user->notifications()->findOrFail($id);

        $this->notificationService->suppressDailyReminders($user, [$notification]);
        $notification->delete();

        return response()->json(['message' => 'Notifikasi dihapus.']);
    }

    public function destroyAll(Request $request)
    {
        $user = $request->user();
        $dailyNotifications = $user->notifications()
            ->where('data->key', 'like', 'daily-%')
            ->get(['data']);

        $this->notificationService->suppressDailyReminders($user, $dailyNotifications);
        $deletedCount = $user->notifications()->delete();

        return response()->json([
            'message' => 'Semua notifikasi dihapus.',
            'deleted_count' => $deletedCount,
        ]);
    }

    private function serialize(DatabaseNotification $notification): array
    {
        $data = $notification->data;
        $isHousekeeping = isset($data['task_id']) || $notification->type === RoomNeedsCleaning::class;
        $category = $data['category'] ?? ($isHousekeeping ? 'housekeeping' : 'general');

        return [
            'id' => $notification->id,
            'data' => array_merge($data, [
                'category' => $category,
                'title' => $data['title'] ?? match ($category) {
                    'housekeeping' => 'Housekeeping',
                    'booking' => 'Reservasi',
                    'payment' => 'Pembayaran',
                    'maintenance' => 'Maintenance',
                    'shift' => 'Shift',
                    'reminder' => 'Pengingat',
                    default => 'Notifikasi',
                },
                'message' => $data['message'] ?? 'Ada pembaruan pada sistem.',
                'action_url' => $data['action_url'] ?? ($isHousekeeping ? '/housekeeping' : '/dashboard'),
            ]),
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
        ];
    }
}
