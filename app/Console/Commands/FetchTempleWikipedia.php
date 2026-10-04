<?php

namespace App\Console\Commands;

use App\Models\Temple;
use App\Services\Osm\TempleWikipediaFinder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Links temples to their Wikipedia articles, and gives a temple with no
 * description the article's opening, credited to Wikipedia. See
 * App\Services\Osm\TempleWikipediaFinder. Run before
 * temples:fetch-commons-photos: the Wikidata items it finds are photo leads.
 */
class FetchTempleWikipedia extends Command
{
    protected $signature = 'temples:fetch-wikipedia
        {--state=TG : State code}
        {--district=* : Only temples in these districts, by name}
        {--limit=500 : Stop after this many temples}
        {--dry-run : Show what would be taken, change nothing}';

    protected $description = 'Link temples to their Wikipedia articles and fill empty descriptions from them, credited';

    public function handle(TempleWikipediaFinder $finder): int
    {
        $temples = Temple::query()
            ->whereHas('state', fn ($q) => $q->where('code', $this->option('state')))
            ->when($this->option('district'), fn ($q, array $names) => $q->whereHas('district', fn ($q) => $q->whereIn('name', $names)))
            ->whereNull('wikipedia_url')
            ->orderByRaw('wikidata_id is null')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($temples->isEmpty()) {
            $this->info('Every temple here is already linked to its article, or was checked.');

            return self::SUCCESS;
        }

        $linked = 0;
        $described = 0;

        foreach ($temples as $temple) {
            try {
                $found = $finder->articleFor($temple);
                $article = $found !== null ? $finder->summary(...$found) : null;
            } catch (Throwable $e) {
                $this->warn("{$temple->name}: Wikipedia could not be reached ({$e->getMessage()}).");

                continue;
            }

            if ($article === null) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("{$temple->name}: {$article['url']}");

                continue;
            }

            $changed = $finder->apply($temple, $article);
            if ($changed !== []) {
                $linked++;
                $described += in_array('description', $changed, true) ? 1 : 0;
                $this->info("{$temple->name}: ".implode(', ', $changed).' ← '.$article['url']);
            }

            if (! app()->runningUnitTests()) {
                usleep(200_000); // Gentle on Wikimedia's servers.
            }
        }

        $this->line("{$linked} temple(s) linked to Wikipedia, {$described} given a description from it (shown as \"From Wikipedia\", CC BY-SA).");

        return self::SUCCESS;
    }
}
