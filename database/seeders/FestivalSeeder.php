<?php

namespace Database\Seeders;

use App\Support\FestivalImporter;
use Illuminate\Database\Seeder;

/** India's festivals and vrat days, from database/data/festivals.json. */
class FestivalSeeder extends Seeder
{
    public function run(): void
    {
        FestivalImporter::import();
    }
}
