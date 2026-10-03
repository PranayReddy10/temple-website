<?php

namespace Tests\Feature\Temple;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Temples\Pages\EditTemple;
use App\Filament\Resources\Temples\RelationManagers\TimingsRelationManager;
use App\Models\Temple;
use App\Models\TempleTiming;
use App\Models\TempleUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Most temples keep other hours on Saturday and Sunday: a timing holds on
 * a set of days, and on those days it replaces the every-day timing of the
 * same type and name.
 */
class WeekendTimingsTest extends TestCase
{
    use RefreshDatabase;

    private Temple $temple;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temple = Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    private function timing(array $attributes): TempleTiming
    {
        return TempleTiming::create($attributes + ['temple_id' => $this->temple->id, 'kind' => 'darshan', 'label' => 'Darshan']);
    }

    public function test_days_read_the_way_a_notice_board_does(): void
    {
        $this->assertSame('Every day', TempleTiming::labelFor(null));
        $this->assertSame('Mon–Fri', TempleTiming::labelFor([5, 4, 3, 2, 1]));
        $this->assertSame('Sat & Sun', TempleTiming::labelFor([0, 6]));
        $this->assertSame('Sunday', TempleTiming::labelFor([0]));
        $this->assertSame('Mon, Wed & Fri', TempleTiming::labelFor([1, 3, 5]));
        $this->assertSame('Every day', TempleTiming::labelFor([0, 1, 2, 3, 4, 5, 6]));
    }

    public function test_a_weekend_timing_replaces_the_every_day_one_only_at_the_weekend(): void
    {
        $daily = $this->timing(['opens_at' => '06:00', 'closes_at' => '20:00']);
        $weekend = $this->timing(['days' => ['6', '0'], 'opens_at' => '05:00', 'closes_at' => '21:30']);
        $aarti = $this->timing(['kind' => 'aarti', 'label' => 'Sandhya Arati', 'opens_at' => '19:00']);
        $shayana = $this->timing(['kind' => 'aarti', 'label' => 'Shayana Arati', 'opens_at' => '20:10']);
        $shayanaWeekend = $this->timing(['kind' => 'aarti', 'label' => 'Shayana Arati', 'days' => [6, 0], 'opens_at' => '20:35']);
        $all = TempleTiming::all();

        $saturday = TempleTiming::forDay($all, 6)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$weekend->id, $aarti->id, $shayanaWeekend->id], $saturday, 'other aartis are untouched');

        $monday = TempleTiming::forDay($all, 1)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$daily->id, $aarti->id, $shayana->id], $monday);
    }

    public function test_day_of_week_follows_days_for_older_apps_and_sets_them(): void
    {
        $weekend = $this->timing(['days' => [0, 6]]);
        $this->assertSame([6, 0], $weekend->days);
        $this->assertSame(6, $weekend->day_of_week);

        // An older trust app sends one day.
        $sunday = $this->timing(['day_of_week' => 0]);
        $this->assertSame([0], $sunday->days);
        $sunday->update(['day_of_week' => null]);
        $this->assertNull($sunday->refresh()->days);

        // All seven is every day.
        $this->assertNull($this->timing(['days' => [0, 1, 2, 3, 4, 5, 6]])->days);
    }

    public function test_the_trust_app_sets_days_and_splits_weekend_timings(): void
    {
        $token = $this->postJson('/api/v1/trust/auth/register', [
            'name' => 'Secretary', 'email' => 'secretary@example.org', 'phone' => '9876543210', 'password' => 'a-long-password',
        ])->json('data.token');
        TempleUser::create(['temple_id' => $this->temple->id, 'user_id' => User::where('email', 'secretary@example.org')->value('id'), 'role' => 'owner', 'requested_at' => now(), 'approved_at' => now()]);
        $api = $this->withHeader('Authorization', 'Bearer '.$token);
        $base = '/api/v1/trust/temples/'.$this->temple->id.'/timings';

        $api->postJson($base, ['kind' => 'darshan', 'label' => 'Darshan', 'opens_at' => '06:00', 'closes_at' => '13:00'])->assertCreated()
            ->assertJsonPath('data.days', null)->assertJsonPath('data.day_label', 'Every day');
        $api->postJson($base, ['kind' => 'aarti', 'label' => 'Kakad Arati', 'days' => [1, 3, 5], 'opens_at' => '05:00'])->assertCreated()
            ->assertJsonPath('data.days', [1, 3, 5])->assertJsonPath('data.day_label', 'Mon, Wed & Fri');

        // "Different timings on Sat & Sun": the every-day timing becomes
        // Mon–Fri, with a Sat & Sun copy to change.
        $api->postJson($base.'/weekend')->assertCreated()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.day_label', 'Sat & Sun')
            ->assertJsonPath('data.0.window', '6:00 AM – 1:00 PM');

        $api->getJson($base)->assertOk()
            ->assertJsonPath('data.*.day_label', ['Mon–Fri', 'Mon, Wed & Fri', 'Sat & Sun']);

        // Nothing every-day is left to split.
        $api->postJson($base.'/weekend')->assertUnprocessable();
    }

    public function test_admin_picks_days_and_splits_weekend_timings(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));
        $this->timing(['opens_at' => '06:00', 'closes_at' => '20:00']);

        $manager = Livewire::test(TimingsRelationManager::class, ['ownerRecord' => $this->temple, 'pageClass' => EditTemple::class]);
        $manager->callTableAction('splitWeekend')->assertHasNoTableActionErrors();
        $this->assertSame(['Mon–Fri', 'Sat & Sun'], TempleTiming::inReadingOrder($this->temple->timings()->get())->map->dayLabel()->all());

        $manager->callTableAction('create', data: ['kind' => 'aarti', 'label' => 'Abhishekam', 'days' => ['0'], 'opens_at' => '07:00'])
            ->assertHasNoTableActionErrors();
        $this->assertSame([0], TempleTiming::where('label', 'Abhishekam')->value('days'));
    }

    public function test_the_temple_page_shows_the_weekend_hours_at_the_weekend(): void
    {
        $this->timing(['opens_at' => '06:00', 'closes_at' => '20:00']);
        $this->timing(['days' => [6, 0], 'opens_at' => '05:00', 'closes_at' => '21:30']);

        Carbon::setTestNow(Carbon::parse('2026-10-03 10:00', 'Asia/Kolkata')); // a Saturday
        $page = $this->get('/temples/sri-rama')->assertOk();
        $page->assertSee('5:00 AM – 9:30 PM')->assertSee('Sat &amp; Sun', false);

        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Asia/Kolkata')); // a Monday
        $this->get('/temples/sri-rama')->assertOk()->assertSee('6:00 AM – 8:00 PM');
        Carbon::setTestNow();
    }
}
