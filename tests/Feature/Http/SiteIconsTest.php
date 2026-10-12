<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The website's icon in browser tabs, search results and on phones. */
class SiteIconsTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://darshansaathi.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => self::SITE]);
    }

    public function test_every_page_carries_its_icons(): void
    {
        $html = $this->get(self::SITE.'/no-such-page')->assertNotFound()->getContent();

        $this->assertStringContainsString('<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,', $html);
        $this->assertStringContainsString('<link rel="icon" type="image/png" sizes="192x192" href="'.self::SITE.'/icons/icon-192.png">', $html);
        $this->assertStringContainsString('<link rel="shortcut icon" href="'.self::SITE.'/favicon.ico">', $html);
        $this->assertStringContainsString('<link rel="manifest" href="'.self::SITE.'/site.webmanifest">', $html);
    }

    public function test_icon_files_are_served_even_when_the_request_reaches_laravel(): void
    {
        $this->get(self::SITE.'/favicon.ico')->assertOk();
        $this->get(self::SITE.'/favicon.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(self::SITE.'/icons/icon-192.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(self::SITE.'/icons/../.env')->assertNotFound();

        $this->get(self::SITE.'/site.webmanifest')->assertOk()
            ->assertJsonPath('icons.0.src', '/icons/icon-192.png')
            ->assertJsonPath('name', config('brand.name'));
    }
}
