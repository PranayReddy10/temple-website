<?php

namespace App\Console\Commands;

use App\Enums\TempleStatus;
use App\Models\State;
use App\Support\TempleImport\StateTempleImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Loads a state's researched temple file into a live database. Temples the
 * site already lists are recognised and only have their empty fields filled;
 * --dry-run shows which are new and which are already listed, changing nothing.
 */
class ImportStateTemplesCommand extends Command
{
    protected $signature = 'temples:import-state
        {state : State code or name, e.g. AP, TG or "Andhra Pradesh"}
        {--file= : A JSON file of temples; defaults to database/data/temples/<state>.json}
        {--draft : Add new temples as drafts, for an editor to publish}
        {--dry-run : Only report which temples are new and which are already listed}';

    protected $description = 'Import a state\'s researched temples (details, location, timings, sevas) without duplicating listed ones or overwriting editors\' work';

    public function handle(): int
    {
        $state = self::state((string) $this->argument('state'));
        if ($state === null) {
            $this->error('No state "'.$this->argument('state').'". Use its code (AP, TG …) or name; run app:deploy first so states are seeded.');

            return self::FAILURE;
        }
        if (! Schema::hasColumn('temples', 'how_to_reach')) {
            $this->error('The temples table has no how_to_reach column yet: this release\'s migration has not run.');
            $this->line('Run `php artisan app:deploy --force` first, then run this command again.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('file') ?: StateTempleImporter::dataFile($state));
        $rows = StateTempleImporter::readFile($path);
        if ($rows === []) {
            $this->error("No temples to import in {$path}.");

            return self::FAILURE;
        }

        $importer = new StateTempleImporter(
            $state,
            $this->option('draft') ? TempleStatus::Draft : TempleStatus::Published,
            dryRun: (bool) $this->option('dry-run'),
        );
        $importer->importAll($rows);

        $labels = $this->option('dry-run')
            ? ['created' => 'new', 'updated' => 'listed', 'skipped' => 'verified', 'unchanged' => 'listed', 'invalid' => 'invalid']
            : ['created' => 'added', 'updated' => 'filled in', 'unchanged' => 'complete', 'skipped' => 'left alone', 'invalid' => 'invalid'];
        foreach ($importer->log as $entry) {
            $same = $entry['temple'] !== null && $entry['temple'] !== $entry['name'] ? '  (listed as '.$entry['temple'].')' : '';
            $this->line(sprintf('  %-10s %s%s', $labels[$entry['result']], $entry['name'], $same));
        }

        ['created' => $created, 'updated' => $updated, 'unchanged' => $unchanged, 'skipped' => $skipped, 'invalid' => $invalid] = $importer->counts;
        if ($this->option('dry-run')) {
            $this->info("{$state->name}: {$created} new, ".($updated + $unchanged)." already listed, {$skipped} verified by editors, {$invalid} invalid. Nothing was changed.");
        } else {
            $this->info("{$state->name}: {$created} added, {$updated} listed temples filled in, {$unchanged} already complete, {$skipped} left alone (verified or deleted by an editor), {$invalid} invalid.");
            $this->line('Imported records are community level. Verify each against the temple before raising its trust level.');
        }

        return self::SUCCESS;
    }

    public static function state(string $given): ?State
    {
        $given = trim($given);

        return State::where('code', strtoupper($given))->first()
            ?? State::whereRaw('LOWER(name) = ?', [mb_strtolower(str_replace(['-', '_'], ' ', $given))])->first();
    }
}
