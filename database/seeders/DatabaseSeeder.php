<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Order matters: temples reference states, deities and categories.
        $this->call([
            StateSeeder::class,
            DeitySeeder::class,
            TempleCategorySeeder::class,
            AdminUserSeeder::class,
            TempleSeeder::class,
        ]);
    }
}
