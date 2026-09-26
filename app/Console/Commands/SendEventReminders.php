<?php

namespace App\Console\Commands;

use App\Support\Push\EventReminders;
use Illuminate\Console\Command;

/** Reminds followers of tomorrow's festivals and events. Run once a day by the scheduler. */
class SendEventReminders extends Command
{
    protected $signature = 'notifications:event-reminders';

    protected $description = 'Send festival and event reminders to followers who asked for them';

    public function handle(EventReminders $reminders): int
    {
        $count = $reminders->sendDue();

        $this->info("Sent {$count} reminder(s).");

        return self::SUCCESS;
    }
}
