<?php

namespace App\Console\Commands;

use App\Support\SpacesCors;
use Illuminate\Console\Command;

/** The admin panel's "Allow the website to load photos", from the terminal. */
class SpacesCorsCommand extends Command
{
    protected $signature = 'media:allow-cors';

    protected $description = 'Set the CORS rule on the DigitalOcean Space so the website may load its photos';

    public function handle(): int
    {
        $result = SpacesCors::apply();
        $result['ok'] ? $this->info($result['message']) : $this->error($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
