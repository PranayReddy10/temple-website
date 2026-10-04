<?php

namespace App\Console\Commands;

use App\Support\MoveMediaToSpaces;
use Illuminate\Console\Command;

class MoveMediaToSpacesCommand extends Command
{
    protected $signature = 'media:move-to-spaces {--limit=500 : Rows to move in this run} {--all : Keep going until nothing is left} {--dry-run : Only count what would move}';

    protected $description = 'Move photos, videos and documents still on this server to DigitalOcean Spaces';

    public function handle(): int
    {
        $this->table(['Table', 'Rows still on this server'], collect(MoveMediaToSpaces::remaining())->map(fn ($n, $t) => [$t, $n])->values());

        do {
            $result = MoveMediaToSpaces::run((int) $this->option('limit'), (bool) $this->option('dry-run'));
            $this->info(($this->option('dry-run') ? 'Would move ' : 'Moved ').$result['moved'].' rows ('.$result['files'].' files). Left: '.$result['remaining'].'.');
            foreach ($result['failed'] as $line) {
                $this->warn($line);
            }
        } while ($this->option('all') && ! $this->option('dry-run') && $result['moved'] > 0 && $result['remaining'] > 0);

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
