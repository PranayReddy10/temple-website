<?php

namespace App\Console\Commands;

use App\Models\State;
use Database\Seeders\TelanganaTempleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Loads the Telangana temple set into a live database.
 *
 * app:deploy deliberately never re-runs sample temple seeders, because doing
 * so would reset records editors have checked. This command is the explicit,
 * operator-run alternative: the seeder it calls skips verified and deleted
 * temples and only fills empty fields on the rest, so it is safe to repeat.
 */
class ImportTelanganaTemplesCommand extends Command
{
    protected $signature = 'temples:import-telangana';

    protected $description = 'Import the Telangana temple set (famous temples, timings, pujas) without overwriting editors\' work';

    public function handle(): int
    {
        if (! State::where('code', 'TG')->exists()) {
            $this->error('Telangana (TG) is not in the states table. Run app:deploy first so reference data is seeded.');

            return self::FAILURE;
        }

        // The import writes the famous-temple flag. Without its migration the
        // first temple fails with a raw "Unknown column" SQL error.
        if (! Schema::hasColumn('temples', 'is_featured')) {
            $this->error('The temples table has no is_featured column yet: this release\'s migration has not run.');
            $this->line('Run `php artisan app:deploy --force` first, then run this command again.');

            return self::FAILURE;
        }

        $seeder = $this->laravel->make(TelanganaTempleSeeder::class);
        $seeder->run();

        ['created' => $created, 'updated' => $updated, 'unchanged' => $unchanged, 'skipped' => $skipped] = $seeder->counts;

        $this->info("Telangana temples: {$created} created, {$updated} existing records filled in, {$unchanged} already complete, {$skipped} left alone (verified or deleted by an editor).");
        $this->line('All imported records are community level. Verify each against the temple before raising its trust level.');

        return self::SUCCESS;
    }
}
