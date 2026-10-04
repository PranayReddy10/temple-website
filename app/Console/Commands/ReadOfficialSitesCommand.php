<?php

namespace App\Console\Commands;

use App\Models\Temple;
use App\Support\OfficialSite\OfficialSiteImport;
use Illuminate\Console\Command;

class ReadOfficialSitesCommand extends Command
{
    protected $signature = 'temples:read-official-sites
        {--temple= : One temple, by id or slug}
        {--days=30 : Skip temples read within this many days}
        {--limit=200 : Temples per run}';

    protected $description = "Read temples' Google Maps links and own websites for details, for staff to review in the admin panel";

    public function handle(): int
    {
        $query = Temple::query()->whereNotNull('official_website')->where('official_website', '!=', '');

        if ($one = $this->option('temple')) {
            $query->where(fn ($q) => $q->where('id', $one)->orWhere('slug', $one));
        } else {
            $query->where(fn ($q) => $q->whereNull('official_import_at')->orWhere('official_import_at', '<', now()->subDays((int) $this->option('days'))));
        }

        $read = 0;
        foreach ($query->orderBy('official_import_at')->limit((int) $this->option('limit'))->get() as $temple) {
            // Counted as tried before reading: a site that breaks the read
            // must not stay first in line and stop every later run before
            // it reaches the temples behind it.
            $temple->forceFill(['official_import_at' => now()])->saveQuietly();
            try {
                $found = OfficialSiteImport::read($temple);
            } catch (\Throwable $e) {
                $found = ['error' => 'Could not be read: '.class_basename($e).'.'];
            }
            $this->line(str_pad($temple->name, 50).' '.($found['error'] ?? OfficialSiteImport::summary($found)));
            $read++;
            usleep(500_000); // Gentle on small temple sites.
        }

        $this->info("Read {$read} temple websites. Review them in the admin panel: Temples → filter \"Imported details to review\".");

        return self::SUCCESS;
    }
}
