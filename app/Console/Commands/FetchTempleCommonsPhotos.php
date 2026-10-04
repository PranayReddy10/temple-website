<?php

namespace App\Console\Commands;

use App\Models\Temple;
use App\Services\Osm\TempleCommonsPhotoFinder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Gives temples with no photo a freely licensed one from Wikimedia Commons,
 * where OpenStreetMap or Wikidata points to one. See
 * App\Services\Osm\TempleCommonsPhotoFinder.
 */
class FetchTempleCommonsPhotos extends Command
{
    protected $signature = 'temples:fetch-commons-photos
        {--state=TG : State code}
        {--district=* : Only temples in these districts, by name}
        {--limit=200 : Stop after this many temples}
        {--dry-run : Show what would be taken, download nothing}';

    protected $description = 'Add freely licensed Wikimedia Commons photos to temples that have none';

    public function handle(TempleCommonsPhotoFinder $finder): int
    {
        $temples = Temple::query()
            ->whereHas('state', fn ($q) => $q->where('code', $this->option('state')))
            ->when($this->option('district'), fn ($q, array $names) => $q->whereHas('district', fn ($q) => $q->whereIn('name', $names)))
            ->where(fn ($q) => $q->whereNotNull('commons_image')->orWhereNotNull('wikidata_id'))
            ->whereDoesntHave('photos')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($temples->isEmpty()) {
            $this->info('No temple without a photo has a Commons or Wikidata lead.');

            return self::SUCCESS;
        }

        $added = 0;

        foreach ($temples as $temple) {
            try {
                $file = $finder->fileFor($temple);
                $candidate = $file !== null ? $finder->describe($file) : null;
            } catch (Throwable $e) {
                $this->warn("{$temple->name}: Wikimedia could not be reached ({$e->getMessage()}).");

                continue;
            }

            if ($candidate === null) {
                $this->line("{$temple->name}: no photo under a reusable licence.");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("{$temple->name}: {$candidate['title']} ({$candidate['licence']})");

                continue;
            }

            if ($finder->alreadyHas($temple, $candidate['page'])) {
                continue;
            }

            try {
                $finder->store($temple, $candidate);
                $added++;
                $this->info("{$temple->name}: {$candidate['title']} ({$candidate['licence']})");
            } catch (Throwable $e) {
                $this->warn("{$temple->name}: download failed ({$e->getMessage()}).");
            }
        }

        $this->line("{$added} photo(s) added, each with its photographer and licence.");

        return self::SUCCESS;
    }
}
