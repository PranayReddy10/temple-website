<?php

namespace App\Console\Commands;

use App\Support\FestivalImporter;
use Illuminate\Console\Command;

/**
 * Loads festival dates into the calendar. Safe to repeat: rows already
 * loaded (and any an editor corrected) are left as they are. For a new
 * year, generate the file first:
 *
 *     python3 tool/panchang/festivals.py 2028 2028 > database/data/festivals-2028.json
 *     php artisan festivals:import database/data/festivals-2028.json
 */
class ImportFestivalsCommand extends Command
{
    protected $signature = 'festivals:import {file? : JSON from tool/panchang/festivals.py (default database/data/festivals.json)}';

    protected $description = 'Load India\'s festival and vrat dates into the calendar';

    public function handle(): int
    {
        $result = FestivalImporter::import($this->argument('file'));
        $this->components->info("Added {$result['added']} festival days; {$result['kept']} were already there.");

        return self::SUCCESS;
    }
}
