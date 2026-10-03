<?php

namespace App\Console\Commands;

use App\Support\TempleImport\TempleLinkImport;
use Illuminate\Console\Command;

class ImportTempleLinksCommand extends Command
{
    protected $signature = 'temples:import-links {file : A text or CSV file, one temple per line: Google Maps link, then website if any}';

    protected $description = 'Add temples as drafts from Google Maps links (and websites), read for details to review';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $counts = ['created' => 0, 'duplicate' => 0, 'error' => 0];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $n => $line) {
            $line = trim(str_replace(["\t", '"'], ' ', $line));
            if ($line === '' || str_starts_with($line, '#') || (! str_contains($line, 'http') && ! str_contains($line, 'goo.gl') && ! str_contains($line, 'www.'))) {
                continue;
            }
            $l = TempleLinkImport::parseLine($line);
            $result = TempleLinkImport::create($l['maps'], $l['website'], $l['name']);
            $counts[$result['status']]++;
            $this->line(sprintf('%4d  %-9s %s', $n + 1, $result['status'], $result['message']));
            // The map's address service asks for no more than one request a second.
            if (! app()->runningUnitTests()) {
                usleep(1_100_000);
            }
        }

        $this->info("{$counts['created']} added, {$counts['duplicate']} already listed, {$counts['error']} not read. Review them in the admin panel: Temples → filter \"Imported details to review\".");

        return self::SUCCESS;
    }
}
