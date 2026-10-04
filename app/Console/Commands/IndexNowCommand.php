<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\IndexNow;
use Carbon\Carbon;
use Illuminate\Console\Command;

class IndexNowCommand extends Command
{
    protected $signature = 'seo:indexnow {--all : Send every published temple, not only the ones changed since the last run}';

    protected $description = 'Tell Bing and other IndexNow search engines about new and changed temple pages';

    public function handle(): int
    {
        $last = Setting::get('indexnow_last_run');
        $since = $this->option('all') || blank($last) ? null : Carbon::parse((string) $last);
        $started = now();

        $urls = IndexNow::templeUrls($since);
        // Only the directory itself and nothing new: nothing to say.
        if ($since !== null && count($urls) <= 1) {
            $this->info('No pages changed since '.$since->toDateTimeString().'.');
            Setting::set('indexnow_last_run', $started->toIso8601String());

            return self::SUCCESS;
        }

        $sent = IndexNow::submit($urls);
        if ($sent === null) {
            $this->warn('The search engines could not be reached; trying again on the next run.');

            return self::FAILURE;
        }

        Setting::set('indexnow_last_run', $started->toIso8601String());
        Setting::set('indexnow_last_sent', $started->toIso8601String());
        Setting::set('indexnow_last_count', $sent);
        $this->info('Sent '.$sent.' pages to IndexNow.');

        return self::SUCCESS;
    }
}
