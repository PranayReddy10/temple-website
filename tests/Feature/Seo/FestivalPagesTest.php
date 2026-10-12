<?php

namespace Tests\Feature\Seo;

use App\Enums\TempleStatus;
use App\Models\Deity;
use App\Models\Festival;
use App\Models\Temple;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The festival calendar as public pages, linked to the temples of each festival's deity. */
class FestivalPagesTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://darshansaathi.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => self::SITE]);
        Carbon::setTestNow('2026-10-12 10:00:00');

        $narasimha = Deity::create(['name' => 'Lord Narasimha', 'slug' => 'narasimha']);
        Temple::create(['name' => 'Yadadri Temple', 'slug' => 'yadadri', 'deity_id' => $narasimha->id, 'status' => TempleStatus::Published, 'published_at' => now()]);

        $f = fn (array $a) => Festival::create($a + ['is_published' => true, 'kind' => 'festival', 'computed_on' => $a['starts_on']]);
        $f(['slug' => 'narasimha-jayanti', 'name' => 'Narasimha Jayanti', 'starts_on' => '2027-05-20', 'deity' => 'narasimha', 'is_major' => true, 'tithi' => 'Vaishakha Shukla Chaturdashi', 'description' => 'The appearance of Lord Narasimha.']);
        $f(['slug' => 'pradosh', 'name' => 'Pradosh Vrat', 'kind' => 'vrat', 'starts_on' => '2026-10-23', 'deity' => 'shiva']);
        $f(['slug' => 'pradosh', 'name' => 'Pradosh Vrat', 'kind' => 'vrat', 'starts_on' => '2026-11-07', 'deity' => 'shiva']);
        $f(['slug' => 'papankusha-ekadashi', 'name' => 'Papankusha Ekadashi', 'kind' => 'vrat', 'starts_on' => '2026-10-21', 'deity' => 'vishnu']);
        $f(['slug' => 'rama-ekadashi', 'name' => 'Rama Ekadashi', 'kind' => 'vrat', 'starts_on' => '2026-11-05', 'deity' => 'vishnu']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_festival_page_answers_when_it_is_and_links_to_temples(): void
    {
        $this->get(self::SITE.'/festivals/narasimha-jayanti')->assertOk()
            ->assertSee('<title>Narasimha Jayanti 2027: Date, Tithi &amp; Significance', false)
            ->assertSee('Thursday, 20 May 2027')
            ->assertSee('Vaishakha Shukla Chaturdashi')
            ->assertSee('href="'.self::SITE.'/temples/yadadri"', false)
            ->assertSee('"@type":"FAQPage"', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertDontSee('noindex', false);

        // A vrat kept many times a year lists its dates.
        $this->get(self::SITE.'/festivals/pradosh')->assertOk()
            ->assertSee('Pradosh Vrat 2026 Dates: Full List')->assertSee('23 October 2026')->assertSee('7 November 2026');

        // "… begins" in the calendar: searched for without it, and said properly.
        Festival::create(['slug' => 'sharad-navratri', 'name' => 'Sharad Navratri begins', 'starts_on' => '2026-10-11', 'computed_on' => '2026-10-11', 'kind' => 'festival', 'is_published' => true]);
        $this->get(self::SITE.'/festivals/sharad-navratri')->assertOk()
            ->assertSee('<title>Sharad Navratri 2026: Date', false)
            ->assertSee('Sharad Navratri began on');

        $this->get(self::SITE.'/festivals/no-such-festival')->assertNotFound();
    }

    public function test_the_calendar_months_and_ekadashi_list(): void
    {
        $this->get(self::SITE.'/festivals')->assertOk()->assertSee('Hindu Festival Calendar')->assertSee('Festivals in October 2026');
        $this->get(self::SITE.'/festivals/2026/october')->assertOk()->assertSee('Hindu festivals in October 2026')->assertSee('Papankusha Ekadashi');
        $this->get(self::SITE.'/festivals/2026/june')->assertNotFound();
        $this->get(self::SITE.'/festivals/ekadashi')->assertOk()
            ->assertSee('Ekadashi 2026 Dates')->assertSee('The next Ekadashi is')->assertSee('Papankusha Ekadashi')->assertSee('Rama Ekadashi');

        $this->get(self::SITE.'/sitemap-pages.xml')->assertOk()
            ->assertSee(self::SITE.'/festivals/narasimha-jayanti', false)
            ->assertSee(self::SITE.'/festivals/2026/october', false)
            ->assertSee(self::SITE.'/festivals/ekadashi', false);
    }

    public function test_temple_and_deity_pages_link_to_their_festivals(): void
    {
        $this->get(self::SITE.'/temples/yadadri')->assertOk()->assertSee('Festivals of')->assertSee('href="'.self::SITE.'/festivals/narasimha-jayanti"', false);
        $this->get(self::SITE.'/deities/narasimha')->assertOk()->assertSee('href="'.self::SITE.'/festivals/narasimha-jayanti"', false)
            ->assertSee('"@type":"BreadcrumbList"', false);
    }
}
