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

    protected $description = "Read temples' Google Maps links and own websites for details and photos, for staff to review in the admin panel";

    public function handle(): int
    {
        $query = Temple::query()->where(fn ($q) => $q
            ->where(fn ($w) => $w->whereNotNull('official_website')->where('official_website', '!=', ''))
            ->orWhere(fn ($w) => $w->whereNotNull('google_maps_url')->where('google_maps_url', '!=', '')));

        if ($one = $this->option('temple')) {
            $query->where(fn ($q) => $q->where('id', $one)->orWhere('slug', $one));
        } else {
            $query->where(fn ($q) => $q->whereNull('official_import_at')->orWhere('official_import_at', '<', now()->subDays((int) $this->option('days'))));
        }

        $read = 0;
        foreach ($query->orderBy('official_import_at')->limit((int) $this->option('limit'))->get() as $temple) {
            $found = OfficialSiteImport::read($temple);
            $this->line(str_pad($temple->name, 50).' '.($found['error'] ?? OfficialSiteImport::summary($found)));
            $read++;
            usleep(500_000); // Gentle on small temple sites.
        }

        $this->info("Read {$read} temple websites. Review them in the admin panel: Temples → filter \"Imported details to review\".");

        return self::SUCCESS;
    }
}
