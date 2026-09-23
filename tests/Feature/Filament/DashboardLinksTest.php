<?php

namespace Tests\Feature\Filament;

use App\Enums\EventStatus;
use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Filament\Resources\TempleAccess\TempleAccessResource;
use App\Filament\Resources\TempleEvents\Pages\ListTempleEvents;
use App\Filament\Resources\Temples\Pages\ListTemples;
use App\Filament\Widgets\TempleCoverageWidget;
use App\Filament\Widgets\TempleDatabaseOverview;
use App\Filament\Widgets\TempleWorkQueueWidget;
use App\Models\Temple;
use App\Models\TempleCategory;
use App\Models\TempleEvent;
use App\Models\User;
use App\Support\AdminLinks;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dashboard as a way into the data.
 *
 * A number on a tile is only useful if it takes you to the records it
 * counted, with the same filters applied. The risk is that the link looks
 * right and filters nothing: Filament binds filter state to `filters` in the
 * URL while the Livewire property is `tableFilters`, and it coerces only the
 * literal strings "true"/"false"/"null". So these tests follow the links and
 * assert on the rows that come back, not on the href.
 */
class DashboardLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): User
    {
        return User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
    }

    protected function editor(): User
    {
        return User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]);
    }

    /** @return array<string, string> label => url */
    protected function statUrls(string $widget): array
    {
        $instance = app($widget);

        $method = new \ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);

        return collect($method->invoke($instance))
            ->mapWithKeys(fn (Stat $stat): array => [$stat->getLabel() => $stat->getUrl()])
            ->all();
    }

    public function test_every_dashboard_tile_links_somewhere_a_super_admin_can_open(): void
    {
        $this->actingAs($this->superAdmin());

        $urls = array_merge(
            $this->statUrls(TempleDatabaseOverview::class),
            $this->statUrls(TempleWorkQueueWidget::class),
        );

        // Nine tiles across the two widgets; a silent drop to none would
        // otherwise pass this test vacuously.
        $this->assertCount(9, $urls);

        foreach ($urls as $label => $url) {
            $this->assertNotNull($url, "The [{$label}] tile has no link.");
            $this->get($url)->assertOk();
        }
    }

    /** An editor cannot open the access queue, so that tile must not pretend. */
    public function test_a_tile_an_editor_may_not_open_is_not_linked(): void
    {
        $this->actingAs($this->editor());

        $urls = $this->statUrls(TempleWorkQueueWidget::class);

        $this->assertNull($urls['Access requests']);
        $this->assertNotNull($urls['Temples to review']);

        // And the reason it is unlinked is real, not cosmetic.
        $this->get(TempleAccessResource::getUrl('index'))->assertForbidden();
    }

    public function test_the_temples_to_review_link_shows_only_temples_in_review(): void
    {
        $this->actingAs($this->superAdmin());

        $inReview = Temple::create(['name' => 'Waiting Temple', 'status' => TempleStatus::InReview]);
        $draft = Temple::create(['name' => 'Draft Temple', 'status' => TempleStatus::Draft]);

        $this->assertFiltersTo(
            $this->statUrls(TempleWorkQueueWidget::class)['Temples to review'],
            sees: [$inReview],
            hides: [$draft],
        );
    }

    public function test_the_missing_coordinates_link_stays_inside_published_temples(): void
    {
        $this->actingAs($this->superAdmin());

        $target = Temple::create(['name' => 'Unmapped', 'status' => TempleStatus::Published]);

        $mapped = Temple::create([
            'name' => 'Mapped',
            'status' => TempleStatus::Published,
            'latitude' => 12.9,
            'longitude' => 77.6,
        ]);

        // The trap: an ungrouped orWhere in the filter would escape the
        // status filter and drag this draft back into the results.
        $draftUnmapped = Temple::create(['name' => 'Draft Unmapped', 'status' => TempleStatus::Draft]);

        $this->assertFiltersTo(
            $this->statUrls(TempleWorkQueueWidget::class)['Missing coordinates'],
            sees: [$target],
            hides: [$mapped, $draftUnmapped],
        );
    }

    public function test_the_verified_link_shows_both_trusted_levels(): void
    {
        $this->actingAs($this->superAdmin());

        $verified = Temple::create([
            'name' => 'Verified',
            'verification_status' => VerificationStatus::Verified,
        ]);
        $official = Temple::create([
            'name' => 'Official',
            'verification_status' => VerificationStatus::Official,
        ]);
        $unverified = Temple::create([
            'name' => 'Unverified',
            'verification_status' => VerificationStatus::Unverified,
        ]);

        $this->assertFiltersTo(
            $this->statUrls(TempleDatabaseOverview::class)['Verified or official'],
            sees: [$verified, $official],
            hides: [$unverified],
        );
    }

    public function test_the_events_to_review_link_shows_only_submissions(): void
    {
        $this->actingAs($this->superAdmin());

        $temple = Temple::create(['name' => 'Event Temple']);

        $submitted = TempleEvent::create([
            'temple_id' => $temple->id,
            'title' => 'Brahmotsavam',
            'starts_on' => now()->toDateString(),
            'status' => EventStatus::PendingReview,
        ]);

        $draft = TempleEvent::create([
            'temple_id' => $temple->id,
            'title' => 'Unfinished',
            'starts_on' => now()->toDateString(),
            'status' => EventStatus::Draft,
        ]);

        $url = $this->statUrls(TempleWorkQueueWidget::class)['Events to review'];

        $this->get($url)->assertOk();

        Livewire::withQueryParams($this->filtersFrom($url))
            ->test(ListTempleEvents::class)
            ->assertCanSeeTableRecords([$submitted])
            ->assertCanNotSeeTableRecords([$draft]);
    }

    public function test_a_circuit_row_opens_that_circuit_s_temples(): void
    {
        $this->actingAs($this->superAdmin());

        $circuit = TempleCategory::create([
            'name' => 'Jyotirlinga',
            'slug' => 'jyotirlinga',
            'kind' => 'circuit',
            'is_active' => true,
            'expected_count' => 12,
        ]);

        $member = Temple::create(['name' => 'Somnath', 'status' => TempleStatus::Published]);
        $member->categories()->attach($circuit);

        $outsider = Temple::create(['name' => 'Elsewhere', 'status' => TempleStatus::Published]);

        $url = Livewire::test(TempleCoverageWidget::class)
            ->instance()
            ->getTable()
            ->getRecordUrl($circuit);

        $this->assertFiltersTo($url, sees: [$member], hides: [$outsider]);
    }

    public function test_a_toggle_filter_arrives_as_a_boolean_not_the_string_one(): void
    {
        // http_build_query would send true as "1", which Filament does not
        // coerce back, leaving the toggle visibly off while the list is
        // filtered. The literal "true" is what survives the round trip.
        $url = AdminLinks::filtered('/admin/temples', ['stale' => AdminLinks::on()]);

        $this->assertStringContainsString('filters%5Bstale%5D%5BisActive%5D=true', $url);
        $this->assertStringNotContainsString('tableFilters', $url);
    }

    public function test_filters_append_to_a_url_that_already_has_a_query(): void
    {
        $url = AdminLinks::filtered('/admin/temples?tab=all', ['stale' => AdminLinks::on()]);

        $this->assertStringContainsString('?tab=all&filters', $url);
    }

    public function test_no_filters_leaves_the_url_untouched(): void
    {
        $this->assertSame('/admin/temples', AdminLinks::filtered('/admin/temples', []));
    }

    /**
     * Follows a dashboard link the way a browser would and checks the rows.
     *
     * @param  array<int, \Illuminate\Database\Eloquent\Model>  $sees
     * @param  array<int, \Illuminate\Database\Eloquent\Model>  $hides
     */
    protected function assertFiltersTo(string $url, array $sees, array $hides): void
    {
        $this->get($url)->assertOk();

        Livewire::withQueryParams($this->filtersFrom($url))
            ->test(ListTemples::class)
            ->assertCanSeeTableRecords($sees)
            ->assertCanNotSeeTableRecords($hides);
    }

    /** @return array<string, mixed> */
    protected function filtersFrom(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
