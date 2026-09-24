<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Support\Push\NotificationSender;
use Illuminate\Console\Command;

/** Sends scheduled notifications whose time has come. Run every minute by the scheduler. */
class SendDueNotifications extends Command
{
    protected $signature = 'notifications:send-due';

    protected $description = 'Send app notifications scheduled for now or earlier';

    public function handle(NotificationSender $sender): int
    {
        $count = 0;

        AppNotification::query()->due()->orderBy('scheduled_at')->each(function (AppNotification $n) use ($sender, &$count): void {
            $sender->send($n);
            $count++;
        });

        $this->info("Sent {$count} notification(s).");

        return self::SUCCESS;
    }
}
