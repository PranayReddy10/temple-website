<?php

use App\Support\Bookings\PujaBookings;
use App\Support\Events\EventRegistrations;
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

// Festival and event reminders for followers who asked, the evening before.
// 18:00 in the devotional time zone: early enough to plan, late enough not
// to wake anyone.
Schedule::command('notifications:event-reminders')->dailyAt('18:00')->timezone(config('brand.timezone', 'Asia/Kolkata'));

// Seva bookings whose day has passed: an unused ticket expires, an unpaid
// booking is cancelled.
Schedule::call(fn () => app(PujaBookings::class)->expireOverdue())
    ->name('bookings:expire-overdue')->hourly()->withoutOverlapping();

// The same for event tickets and "I'll join" places.
Schedule::call(fn () => app(EventRegistrations::class)->expireOverdue())
    ->name('event-tickets:expire-overdue')->hourly()->withoutOverlapping();

// New and changed pages to Bing and the other IndexNow engines, within
// minutes of the change (only what changed is sent). Google reads the
// sitemap instead, whose dates move with every change.
Schedule::command('seo:indexnow')->everyTenMinutes()->withoutOverlapping();

// Temples' own websites, re-read monthly for staff to review (nothing changes
// on a listing until someone ticks it in the admin panel).
Schedule::command('temples:read-official-sites --days=30 --limit=100')->weeklyOn(1, '04:10')->withoutOverlapping();
