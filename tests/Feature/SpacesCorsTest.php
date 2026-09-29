<?php

namespace Tests\Feature;

use App\Support\SpacesCors;
use Tests\TestCase;

class SpacesCorsTest extends TestCase
{
    public function test_the_rule_allows_the_website_and_this_server_to_read(): void
    {
        config(['brand.website' => 'https://darshansaathi.com', 'app.url' => 'https://temple.darshansaathi.com']);

        $rule = SpacesCors::configuration()['CORSRules'][0];
        $this->assertSame(['https://darshansaathi.com', 'https://temple.darshansaathi.com'], $rule['AllowedOrigins']);
        $this->assertSame(['GET', 'HEAD'], $rule['AllowedMethods']);
    }

    public function test_without_spaces_nothing_is_attempted(): void
    {
        $this->artisan('media:allow-cors')->assertFailed();
        $this->assertFalse(SpacesCors::apply()['ok']);
    }
}
