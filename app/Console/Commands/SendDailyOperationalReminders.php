<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendDailyOperationalReminders extends Command
{
    protected $signature = 'notifications:daily-operational';

    protected $description = 'Send daily check-in, check-out, and pending-payment reminders';

    public function handle(NotificationService $notifications): int
    {
        $notifications->sendDailyReminders();
        $this->info('Daily operational reminders processed.');

        return self::SUCCESS;
    }
}
