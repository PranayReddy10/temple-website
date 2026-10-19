<?php

namespace Tests\Feature\Seo;

use App\Enums\TempleStatus;
use App\Models\Deity;
use App\Models\Temple;
use App\Models\TempleTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every temple page gives search engines and link previews an image of this
 * temple: its photo, else its deity's painting, else a card drawn for it.
 */
class TempleImagesTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://darshansaathi.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => self::SITE]);
    }

    public function test_a_temple_without_photo_or_painting_gets_a_card_of_its_own(): void
    {
        $temple = Temple::create(['name' => 'Mallur Hemachala Lakshmi Narasimha Swamy Temple', 'slug' => 'mallur', 'city' => 'Mallur', 'status' => TempleStatus::Published, 'published_at' => now()]);
        TempleTiming::create(['temple_id' => $temple->id, 'kind' => 'darshan', 'opens_at' => '05:00', 'closes_at' => '12:30']);

        $this->get(self::SITE.'/temples/mallur')->assertOk()
            ->assertSee('<meta property="og:image" content="'.self::SITE.'/temples/mallur/card.png">', false)
            ->assertSee('"image":"'.self::SITE.'/temples/mallur/card.png"', false)
            ->assertSee('max-image-preview:large', false);

        $png = $this->get(self::SITE.'/temples/mallur/card.png')->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
        $size = getimagesizefromstring($png);
        $this->assertSame([1200, 630], [$size[0], $size[1]]);

        $this->get(self::SITE.'/temples/unknown/card.png')->assertNotFound();
    }

    public function test_the_deity_painting_stands_in_for_a_missing_photo(): void
    {
        $deity = Deity::create(['name' => 'Narasimha', 'slug' => 'narasimha', 'image_disk' => 'public', 'image_path' => 'deities/narasimha-commons-1.jpg', 'image_credit' => 'Raja Ravi Varma, public domain']);
        Temple::create(['name' => 'Yadadri Temple', 'slug' => 'yadadri', 'deity_id' => $deity->id, 'status' => TempleStatus::Published, 'published_at' => now()]);

        $html = $this->get(self::SITE.'/temples/yadadri')->assertOk()
            ->assertSee('Painting of Narasimha')
            ->assertSee('Raja Ravi Varma, public domain')
            ->getContent();
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="[^"]*deities/narasimha-commons-1\.jpg">#', $html);

        $this->get(self::SITE.'/sitemap-temples-1.xml')->assertOk()->assertSee('narasimha-commons-1.jpg', false);
    }

    public function test_one_temple_whose_photo_cannot_be_read_does_not_break_the_sitemap(): void
    {
        // A deity painting on a storage this server has no settings for.
        $deity = Deity::create(['name' => 'Shiva', 'slug' => 'shiva', 'image_disk' => 'no-such-disk', 'image_path' => 'deities/shiva.jpg']);
        Temple::create(['name' => 'Broken Photo Temple', 'slug' => 'broken-photo', 'deity_id' => $deity->id, 'status' => TempleStatus::Published, 'published_at' => now()]);
        Temple::create(['name' => 'Fine Temple', 'slug' => 'fine-temple', 'status' => TempleStatus::Published, 'published_at' => now()]);

        foreach (['/sitemap.xml', '/sitemap-temples-1.xml', '/sitemap-pages.xml'] as $path) {
            $xml = $this->get(self::SITE.$path)->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
            $this->assertStringStartsWith('<?xml', $xml, $path);
            $this->assertNotFalse(simplexml_load_string($xml), $path.' must be well-formed XML');
        }
        // Both temples are listed; the one whose photo failed, without it.
        $this->get(self::SITE.'/sitemap-temples-1.xml')
            ->assertSee('/temples/broken-photo</loc>', false)
            ->assertSee('/temples/fine-temple</loc>', false)
            ->assertDontSee('shiva.jpg', false);
    }
}
