<?php

namespace App\Notifications;

use App\Models\HousekeepingTask;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class RoomNeedsCleaning extends Notification
{
    use Queueable;

    public function __construct(private HousekeepingTask $task)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $this->task->loadMissing('room');

        return new DatabaseMessage([
            'task_id' => $this->task->id,
            'room_id' => $this->task->room_id,
            'message' => "Kamar {$this->task->room->room_number} perlu dibersihkan",
        ]);
    }
}
