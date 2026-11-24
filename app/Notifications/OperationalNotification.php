<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class OperationalNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $category,
        private string $event,
        private string $title,
        private string $message,
        private string $actionUrl,
        private array $meta = [],
        private ?string $key = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $data = array_merge($this->meta, [
            'category' => $this->category,
            'event' => $this->event,
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => $this->actionUrl,
        ]);

        if ($this->key !== null) {
            $data['key'] = $this->key;
        }

        return new DatabaseMessage($data);
    }
}
