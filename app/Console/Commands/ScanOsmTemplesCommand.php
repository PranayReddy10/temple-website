<?php

namespace App\Console\Commands;

use App\Enums\TempleStatus;
use App\Models\State;
use App\Services\Osm\OsmTempleImporter;
use App\Services\Osm\OverpassClient;
use App\Services\Osm\TelanganaDistricts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * District by district, counts the Hindu temples OpenStreetMap knows in
 * Telangana, says which we already have, and — with --import — adds the rest.
 *
 * A plain run changes nothing: it is the "how many are there, and how many
 * are new" report. Every row also goes to a CSV so the matches can be checked
 * before importing. See App\Services\Osm\OsmTempleImporter for how matching
 * and filling work.
 */
class ScanOsmTemplesCommand extends Command
{
    protected $signature = 'temples:osm-scan
        {--district=* : Only these districts, by name (e.g. --district=Karimnagar)}
        {--import : Save new temples and link matched ones. Without it nothing is written}
        {--draft : Import new temples as drafts for review instead of publishing them}
        {--csv= : Where to write the per-temple report (default: storage/app/private/imports/)}
        {--pause=2 : Seconds to wait between districts, for Overpass fair use}';

    protected $description = 'Count Telangana temples on OpenStreetMap per district, compare with ours, and optionally import the new ones';

    public function handle(OverpassClient $overpass, OsmTempleImporter $importer): int
    {
        $state = State::where('code', 'TG')->first();

        if ($state === null) {
            $this->error('Telangana (TG) is not in the states table. Run app:deploy first so reference data is seeded.');

            return self::FAILURE;
        }

        if (! Schema::hasColumn('temples', 'osm_ref')) {
            $this->error('The temples table has no osm_ref column yet: this release\'s migration has not run.');
            $this->line('Run `php artisan app:deploy --force` first, then run this command again.');

            return self::FAILURE;
        }

        $write = (bool) $this->option('import');
        $status = $this->option('draft') ? TempleStatus::Draft : TempleStatus::Published;

        try {
            $osmDistricts = $overpass->districts('IN-TG');
        } catch (Throwable $e) {
            $this->error('OpenStreetMap (Overpass) could not be reached: '.$e->getMessage());

            return self::FAILURE;
        }

        $wanted = collect($this->option('district'))
            ->map(fn (string $name) => TelanganaDistricts::canonical($name) ?? $name)
            ->all();

        $districts = [];

        foreach ($osmDistricts as $osm) {
            $name = TelanganaDistricts::canonical($osm['name']);

            if ($name === null) {
                $this->warn("OpenStreetMap district \"{$osm['name']}\" is not one of Telangana's 33; skipped.");

                continue;
            }

            if ($wanted === [] || in_array($name, $wanted, true)) {
                $districts[$name] = $osm['id'];
            }
        }

        ksort($districts);

        foreach (array_diff($wanted ?: array_keys(TelanganaDistricts::NAMES), array_keys($districts)) as $missing) {
            $this->warn("{$missing}: no district boundary of that name on OpenStreetMap.");
        }

        if ($districts === []) {
            return self::FAILURE;
        }

        $this->info($write
            ? 'Importing: new temples will be '.($status === TempleStatus::Draft ? 'drafts' : 'published as community records').'.'
            : 'Report only. Nothing is saved; add --import to import.');

        $rows = [];
        $table = [];
        $totals = ['osm' => 0, 'new' => 0, 'matched' => 0, 'known' => 0, 'duplicate' => 0, 'unnamed' => 0];

        foreach (array_keys($districts) as $i => $name) {
            if ($i > 0 && (int) $this->option('pause') > 0) {
                sleep((int) $this->option('pause'));
            }

            try {
                $elements = $overpass->temples($districts[$name]);
                $places = $overpass->places($districts[$name]);
            } catch (Throwable $e) {
                $this->warn("{$name}: OpenStreetMap could not be reached ({$e->getMessage()}). Run again with --district=\"{$name}\".");

                continue;
            }

            $district = $importer->district($state, $name);
            $report = $importer->process($state, $district, $elements, $places, $write, $status);

            $counts = array_count_values(array_column($report, 'outcome')) + ['new' => 0, 'matched' => 0, 'known' => 0, 'duplicate' => 0, 'unnamed' => 0];

            $table[] = [$name, count($report), $counts['matched'] + $counts['known'], $counts['new'], $counts['duplicate'], $counts['unnamed']];

            $totals['osm'] += count($report);

            foreach (['new', 'matched', 'known', 'duplicate', 'unnamed'] as $key) {
                $totals[$key] += $counts[$key];
            }

            foreach ($report as $row) {
                $rows[] = ['district' => $name] + $row;
            }
        }

        $table[] = ['TOTAL', $totals['osm'], $totals['matched'] + $totals['known'], $totals['new'], $totals['duplicate'], $totals['unnamed']];

        $this->table(
            ['District', 'On OpenStreetMap', 'Already ours', $write ? 'Imported' : 'New', 'Mapped twice', 'No name (skipped)'],
            $table,
        );

        $path = $this->writeCsv($rows);
        $this->line("Every temple, with its outcome: {$path}");

        if ($write) {
            $this->line('Imported temples are community level, credited to OpenStreetMap contributors. Run temples:fetch-commons-photos next for freely licensed photos.');
        }

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $rows */
    protected function writeCsv(array $rows): string
    {
        $path = $this->option('csv') ?: storage_path('app/private/imports/osm-telangana-'.now()->format('Ymd-His').'.csv');

        File::ensureDirectoryExists(dirname($path));

        $handle = fopen($path, 'w');
        fputcsv($handle, ['district', 'outcome', 'osm_name', 'locality', 'deity', 'latitude', 'longitude', 'osm_url', 'temple_id', 'our_temple_name'], escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['district'], $row['outcome'], $row['name'], $row['locality'], $row['deity'],
                $row['lat'], $row['lon'], 'https://www.openstreetmap.org/'.$row['osm_ref'],
                $row['temple_id'], $row['temple_name'],
            ], escape: '');
        }

        fclose($handle);

        return $path;
    }
}
