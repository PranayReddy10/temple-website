<?php

namespace App\Console\Commands;

use App\Enums\TempleStatus;
use App\Models\Temple;
use App\Support\TempleAgent\TempleResearcher;
use App\Support\TempleImport\StateTempleImporter;
use App\Support\TempleImport\TempleMatcher;
use Illuminate\Console\Command;

/**
 * The temple research agent, run state by state:
 *
 * 1. reads which temples of the state the site already lists;
 * 2. asks the researcher for the state's top temples not among them
 *    (or takes the names given with --temple);
 * 3. skips any the site already has under another name;
 * 4. researches each from its own website and public sources, then
 *    fact-checks the result field by field in a second pass (kept only
 *    when the official site or two independent sources confirm it);
 * 5. saves it to the state's data file (database/data/temples/<state>.json),
 *    so the research is kept, reviewed and re-importable;
 * 6. imports it — a temple found to be listed after all only has its
 *    empty fields filled.
 */
class TempleAgentCommand extends Command
{
    protected $signature = 'temples:agent
        {state : State code or name, e.g. AP or TG}
        {--limit=10 : How many new temples to look for}
        {--temple=* : Research these temples by name instead of discovering them}
        {--draft : Add new temples as drafts, for an editor to publish}
        {--file= : Where to save the research; defaults to database/data/temples/<state>.json}
        {--no-import : Only save the research to the data file}';

    protected $description = 'Research agent: find a state\'s top temples not yet listed, research each from Wikidata, Wikipedia, OpenStreetMap and its own website (free), and add them';

    public function handle(): int
    {
        try {
            $researcher = $this->laravel->make(TempleResearcher::class);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $state = ImportStateTemplesCommand::state((string) $this->argument('state'));
        if ($state === null) {
            $this->error('No state "'.$this->argument('state').'". Use its code (AP, TG …) or name.');

            return self::FAILURE;
        }

        $file = (string) ($this->option('file') ?: StateTempleImporter::dataFile($state));
        $saved = StateTempleImporter::readFile($file);
        $matcher = new TempleMatcher($state->id);

        $names = (array) $this->option('temple');
        if ($names !== []) {
            $candidates = array_map(fn (string $n) => ['name' => $n, 'city' => null, 'district' => null], $names);
        } else {
            $listed = Temple::where('state_id', $state->id)->with('aliases')->get()
                ->map(fn (Temple $t) => $t->name.($t->aliases->isNotEmpty() ? ' (also: '.$t->aliases->pluck('name')->implode(', ').')' : ''))
                ->merge(array_column($saved, 'name'))
                ->unique()->values()->all();
            $this->info("{$state->name}: ".count($listed).' temples already known. Looking for up to '.$this->option('limit').' more…');
            try {
                $candidates = $researcher->discover($state, $listed, max(1, (int) $this->option('limit')));
            } catch (\Throwable $e) {
                $this->error('Could not look for temples: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $importer = new StateTempleImporter($state, $this->option('draft') ? TempleStatus::Draft : TempleStatus::Published);
        $researched = 0;

        foreach ($candidates as $candidate) {
            if ($names === [] && ($same = $matcher->find($candidate))) {
                $this->line("  listed     {$candidate['name']}  (as {$same->name})");

                continue;
            }

            $this->line("  research   {$candidate['name']}…");
            try {
                $row = $researcher->research($state, $candidate);
            } catch (\Throwable $e) {
                $this->warn('             not researched: '.$e->getMessage());

                continue;
            }
            if ($row === null) {
                $this->warn('             not researched: nothing reliable found.');

                continue;
            }

            if (isset($row['verification'])) {
                $corrections = count($row['verification']['changes'] ?? []);
                $this->line("             fact-checked: {$corrections} ".($corrections === 1 ? 'correction' : 'corrections'));
            }
            $this->line('             sources: '.implode(', ', array_column($row['sources'] ?? [], 'name')));

            $saved = self::upsert($saved, $row);
            // OpenStreetMap and Wikimedia ask for no more than one request a second.
            if (! app()->runningUnitTests()) {
                sleep(1);
            }
            $this->save($file, $saved);
            $researched++;

            if (! $this->option('no-import')) {
                $result = $importer->import($row);
                $last = end($importer->log);
                $this->line('             '.match ($result) {
                    'created' => 'added',
                    'updated' => 'already listed as '.$last['temple'].': empty details filled in',
                    'unchanged' => 'already listed as '.$last['temple'].', nothing new',
                    'skipped' => 'already listed as '.$last['temple'].', verified by an editor: left alone',
                    default => 'not imported: no name or district',
                });
            }
        }

        $this->info("Researched {$researched}, saved to ".str_replace(base_path().'/', '', $file).'.');
        if (! $this->option('no-import')) {
            ['created' => $c, 'updated' => $u] = $importer->counts;
            $this->info("{$c} temples added, {$u} listed temples filled in. They are community level: verify each against the temple before raising its trust level.");
        }

        return self::SUCCESS;
    }

    /**
     * The row replaces an earlier one of the same temple, else is added.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function upsert(array $rows, array $row): array
    {
        $key = TempleMatcher::key((string) $row['name']);
        foreach ($rows as $i => $existing) {
            if (TempleMatcher::key((string) ($existing['name'] ?? '')) === $key) {
                $rows[$i] = $row;

                return $rows;
            }
        }
        $rows[] = $row;

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function save(string $file, array $rows): void
    {
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }
}
