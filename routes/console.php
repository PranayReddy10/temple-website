<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled app notifications. On shared hosting, add one cron entry:
// * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
Schedule::command('notifications:send-due')->everyMinute()->withoutOverlapping();

// Subscriptions end on their own (entitlements read ends_at); this only
// marks payments abandoned at the gateway, so the admin list stays honest.
Schedule::command('payments:expire-stale')->hourly();
