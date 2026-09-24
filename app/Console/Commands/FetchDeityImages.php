<?php

namespace App\Console\Commands;

use App\Models\Deity;
use App\Services\DeityImageFinder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Gives every deity without an image a public-domain painting from
 * Wikimedia Commons. See App\Services\DeityImageFinder.
 */
class FetchDeityImages extends Command
{
    protected $signature = 'deities:fetch-images
        {--deity=* : Only these slugs}
        {--replace : Also replace images a previous run fetched (never an editor\'s upload)}
        {--dry-run : Show what would be chosen, download nothing}';

    protected $description = 'Fetch public-domain deity paintings from Wikimedia Commons';

    public function handle(DeityImageFinder $finder): int
    {
        $deities = Deity::query()
            ->when($this->option('deity'), fn ($q, $slugs) => $q->whereIn('slug', $slugs))
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (Deity $d) => blank($d->image_path) || ($this->option('replace') && str_contains((string) $d->image_path, '-commons-')));

        if ($deities->isEmpty()) {
            $this->info('Every deity already has an image.');

            return self::SUCCESS;
        }

        foreach ($deities as $deity) {
            try {
                $candidate = $finder->find($deity);
            } catch (Throwable $e) {
                $this->warn("{$deity->name}: Commons could not be reached ({$e->getMessage()}).");

                continue;
            }

            if ($candidate === null) {
                $this->line("{$deity->name}: no public-domain painting found; upload one in the admin.");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("{$deity->name}: {$candidate['title']} ({$candidate['licence']})");

                continue;
            }

            try {
                $finder->store($deity, $candidate);
                $this->info("{$deity->name}: {$candidate['title']}");
            } catch (Throwable $e) {
                $this->warn("{$deity->name}: download failed ({$e->getMessage()}).");
            }
        }

        return self::SUCCESS;
    }
}
