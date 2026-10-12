<?php

namespace Database\Seeders;

use App\Models\State;
use App\Support\TempleImport\StateTempleImporter;
use Illuminate\Database\Seeder;

/**
 * The researched temple sets, state by state: every file in
 * database/data/temples, named after its state ("andhra-pradesh.json").
 *
 * Production loads them with `temples:import-state AP`, which runs the same
 * importer: listed temples are recognised and never added twice.
 */
class StateTempleDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach (State::orderBy('name')->get() as $state) {
            $rows = StateTempleImporter::readFile(StateTempleImporter::dataFile($state));
            if ($rows !== []) {
                (new StateTempleImporter($state))->importAll($rows);
            }
        }
    }
}
