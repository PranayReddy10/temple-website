<?php

namespace Tests\Feature\Filament;

use App\Enums\PhotoModerationStatus;
use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Enums\YatraStatus;
use App\Filament\Pages\DevoteeAnalytics;
use App\Filament\Resources\Devotees\DevoteeResource;
use App\Filament\Resources\Devotees\Pages\ListDevotees;
use App\Filament\Resources\Devotees\Pages\ViewDevotee;
use App\Filament\Resources\Devotees\RelationManagers\MemoriesRelationManager;
use App\Filament\Resources\Devotees\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Devotees\RelationManagers\VisitsRelationManager;
use App\Filament\Resources\Devotees\RelationManagers\YatrasRelationManager;
use App\Filament\Resources\VisitPhotos\Pages\ListVisitPhotos;
use App\Filament\Resources\VisitPhotos\VisitPhotoResource;
use App\Filament\Resources\Yatras\Pages\ListYatras;
use App\Filament\Resources\Yatras\YatraResource;
use App\Models\Devotee;
use App\Models\DevoteeMemory;
use App\Models\DevoteeVisit;
use App\Models\LoginEvent;
use App\Models\Temple;
use App\Models\User;
use App\Models\VisitPhoto;
use App\Models\Yatra;
use App\Filament\Widgets\Devotees\DevoteeAudienceWidget;
use App\Support\DevoteeStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The staff view of the devotee side.
 *
 * Two rules carry these screens. Devotee records are read-only — their
 * account is theirs, and a staff form that can rewrite their name is a form
 * that eventually will. And a private memory stays private: staff see that
 * one exists, not what it says.
 */
class DevoteeAdminTest extends TestCase
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

    protected function templeAdmin(): User
    {
        return User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
    }

    /**
     * Draft, not published: these tests sign in as an editor, and an editor
     * publishing a temple is refused by the observer — correctly. A visit
     * only needs a temple to point at.
     */
    protected function temple(string $name = 'Kashi Vishwanath'): Temple
    {
        return Temple::create(['name' => $name, 'status' => TempleStatus::Draft]);
    }

    // --- Access ---

    public function test_staff_can_reach_every_devotee_screen(): void
    {
        $this->actingAs($this->editor());

        foreach ([
            DevoteeResource::getUrl('index'),
            YatraResource::getUrl('index'),
            VisitPhotoResource::getUrl('index'),
            DevoteeAnalytics::getUrl(),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    /** A temple admin signs into a different panel entirely. */
    public function test_a_temple_admin_cannot_reach_the_devotee_screens(): void
    {
        $this->actingAs($this->templeAdmin());

        $this->get(DevoteeResource::getUrl('index'))->assertForbidden();
        $this->get(DevoteeAnalytics::getUrl())->assertForbidden();
    }

    // --- Read-only by design ---

    public function test_a_devotee_account_cannot_be_created_or_edited_by_staff(): void
    {
        $this->actingAs($this->superAdmin());

        $this->assertFalse(DevoteeResource::canCreate());
        $this->assertFalse(DevoteeResource::canEdit(Devotee::factory()->create()));
        $this->assertFalse(DevoteeResource::canDelete(Devotee::factory()->create()));

        // And there is no route to reach one by hand.
        $this->assertArrayNotHasKey('edit', DevoteeResource::getPages());
        $this->assertArrayNotHasKey('create', DevoteeResource::getPages());
    }

    // --- The profile page ---

    public function test_the_profile_page_shows_what_the_account_has_done(): void
    {
        $this->actingAs($this->superAdmin());

        $devotee = Devotee::factory()->create(['name' => 'Anusha R']);
        $temple = $this->temple();

        DevoteeVisit::create([
            'devotee_id' => $devotee->id, 'temple_id' => $temple->id,
            'visited_on' => '2026-01-01', 'is_verified' => true,
        ]);

        Yatra::create(['devotee_id' => $devotee->id, 'title' => 'Char Dham']);

        $this->get(ViewDevotee::getUrl(['record' => $devotee]))
            ->assertOk()
            ->assertSee('Anusha R')
            ->assertSee('Stamps')
            ->assertSee('Trips')
            ->assertSee('Recent sign-ins');
    }

    public function test_a_suspended_account_can_be_restored(): void
    {
        $this->actingAs($this->superAdmin());

        $devotee = Devotee::factory()->create();

        Livewire::test(ListDevotees::class)
            ->callTableAction('deactivate', $devotee);

        $this->assertFalse((bool) $devotee->fresh()->is_active);

        Livewire::test(ListDevotees::class)
            ->callTableAction('reactivate', $devotee);

        $this->assertTrue((bool) $devotee->fresh()->is_active);
    }

    /** Suspension must not destroy a pilgrimage record. */
    public function test_suspending_an_account_keeps_its_visits(): void
    {
        $this->actingAs($this->superAdmin());

        $devotee = Devotee::factory()->create();
        $temple = $this->temple();
        DevoteeVisit::create([
            'devotee_id' => $devotee->id, 'temple_id' => $temple->id, 'visited_on' => '2026-01-01',
        ]);

        Livewire::test(ListDevotees::class)->callTableAction('deactivate', $devotee);

        $this->assertDatabaseCount('devotee_visits', 1);
    }

    /** Only a super admin may cut off someone's access. */
    public function test_an_editor_cannot_suspend_an_account(): void
    {
        $this->actingAs($this->editor());

        $devotee = Devotee::factory()->create();

        Livewire::test(ListDevotees::class)->assertTableActionHidden('deactivate', $devotee);
    }

    // --- Relation managers ---

    public function test_every_devotee_relation_manager_renders(): void
    {
        $this->actingAs($this->superAdmin());

        $devotee = Devotee::factory()->create();
        $temple = $this->temple();

        $visit = DevoteeVisit::create([
            'devotee_id' => $devotee->id, 'temple_id' => $temple->id, 'visited_on' => '2026-01-01',
        ]);
        Yatra::create(['devotee_id' => $devotee->id, 'title' => 'A trip']);
        VisitPhoto::create([
            'devotee_id' => $devotee->id, 'temple_id' => $temple->id,
            'devotee_visit_id' => $visit->id, 'original_path' => 'a.jpg',
        ]);
        DevoteeMemory::create(['devotee_id' => $devotee->id, 'body' => 'Something.']);

        foreach ([
            VisitsRelationManager::class,
            YatrasRelationManager::class,
            PhotosRelationManager::class,
            MemoriesRelationManager::class,
        ] as $manager) {
            Livewire::test($manager, [
                'ownerRecord' => $devotee,
                'pageClass' => ViewDevotee::class,
            ])->assertOk();
        }
    }

    /**
     * The sharpest edge on these screens: a devotee's private writing is
     * about what they prayed for, and staff have no business reading it.
     */
    public function test_a_private_memory_s_text_is_never_shown_to_staff(): void
    {
        $this->actingAs($this->superAdmin());

        $devotee = Devotee::factory()->create();

        DevoteeMemory::create([
            'devotee_id' => $devotee->id,
            'title' => 'A PRIVATE TITLE',
            'body' => 'I PRAYED FOR MY MOTHER.',
            'is_private' => true,
        ]);

        DevoteeMemory::create([
            'devotee_id' => $devotee->id,
            'title' => 'A SHARED TITLE',
            'body' => 'Beautiful morning aarti.',
            'is_private' => false,
        ]);

        Livewire::test(MemoriesRelationManager::class, [
            'ownerRecord' => $devotee,
            'pageClass' => ViewDevotee::class,
        ])
            ->assertOk()
            ->assertDontSee('A PRIVATE TITLE')
            ->assertDontSee('I PRAYED FOR MY MOTHER')
            // Not hidden entirely: staff need to know the feature is used.
            ->assertSee('Private — not shown')
            ->assertSee('A SHARED TITLE');
    }

    // --- Verifying a visit ---

    public function test_a_super_admin_can_verify_and_revoke_a_stamp(): void
    {
        $this->actingAs($this->superAdmin());

        $devotee = Devotee::factory()->create();
        $temple = $this->temple();
        $visit = DevoteeVisit::create([
            'devotee_id' => $devotee->id, 'temple_id' => $temple->id, 'visited_on' => '2026-01-01',
        ]);

        $manager = fn () => Livewire::test(VisitsRelationManager::class, [
            'ownerRecord' => $devotee, 'pageClass' => ViewDevotee::class,
        ]);

        $manager()->callTableAction('verify', $visit);
        $this->assertTrue($visit->fresh()->is_verified);
        $this->assertSame(1, $devotee->stampCount());

        $manager()->callTableAction('unverify', $visit);
        $this->assertFalse($visit->fresh()->is_verified);
        // The visit survives; only the stamp is revoked.
        $this->assertDatabaseCount('devotee_visits', 1);
        $this->assertSame(0, $devotee->fresh()->stampCount());
    }

    public function test_an_editor_cannot_verify_a_visit(): void
    {
        $this->actingAs($this->editor());

        $devotee = Devotee::factory()->create();
        $visit = DevoteeVisit::create([
            'devotee_id' => $devotee->id, 'temple_id' => $this->temple()->id, 'visited_on' => '2026-01-01',
        ]);

        Livewire::test(VisitsRelationManager::class, [
            'ownerRecord' => $devotee, 'pageClass' => ViewDevotee::class,
        ])->assertTableActionHidden('verify', $visit);
    }

    // --- Photo moderation ---

    public function test_approving_a_photo_records_who_decided(): void
    {
        $moderator = $this->editor();
        $this->actingAs($moderator);

        $photo = VisitPhoto::create([
            'devotee_id' => Devotee::factory()->create()->id,
            'temple_id' => $this->temple()->id,
            'original_path' => 'a.jpg',
            'is_public' => true,
        ]);

        Livewire::test(ListVisitPhotos::class)->callTableAction('approve', $photo);

        $photo->refresh();

        $this->assertSame(PhotoModerationStatus::Approved, $photo->status);
        $this->assertSame($moderator->id, $photo->moderated_by);
        $this->assertTrue($photo->isVisibleToOthers());
    }

    /** Approval is not publication; the devotee's own choice still stands. */
    public function test_approving_a_photo_the_devotee_kept_private_does_not_publish_it(): void
    {
        $this->actingAs($this->editor());

        $photo = VisitPhoto::create([
            'devotee_id' => Devotee::factory()->create()->id,
            'temple_id' => $this->temple()->id,
            'original_path' => 'a.jpg',
            'is_public' => false,
        ]);

        Livewire::test(ListVisitPhotos::class)->callTableAction('approve', $photo);

        $this->assertFalse($photo->fresh()->isVisibleToOthers());
    }

    public function test_rejecting_a_photo_records_a_reason_for_the_devotee(): void
    {
        $this->actingAs($this->editor());

        $photo = VisitPhoto::create([
            'devotee_id' => Devotee::factory()->create()->id,
            'temple_id' => $this->temple()->id,
            'original_path' => 'a.jpg',
        ]);

        Livewire::test(ListVisitPhotos::class)
            ->callTableAction('reject', $photo, data: ['moderation_note' => 'Not a photo of this temple.']);

        $photo->refresh();

        $this->assertSame(PhotoModerationStatus::Rejected, $photo->status);
        $this->assertSame('Not a photo of this temple.', $photo->moderation_note);
    }

    public function test_the_moderation_queue_is_worked_oldest_first(): void
    {
        $this->actingAs($this->editor());

        $devotee = Devotee::factory()->create();
        $temple = $this->temple();

        $older = VisitPhoto::create([
            'devotee_id' => $devotee->id, 'temple_id' => $temple->id, 'original_path' => 'old.jpg',
        ]);
        $older->forceFill(['created_at' => now()->subWeek()])->save();

        $newer = VisitPhoto::create([
            'devotee_id' => $devotee->id, 'temple_id' => $temple->id, 'original_path' => 'new.jpg',
        ]);

        Livewire::test(ListVisitPhotos::class)
            ->assertCanSeeTableRecords([$older, $newer], inOrder: true);
    }

    // --- Trips ---

    public function test_the_trips_list_shows_every_devotee_s_plans(): void
    {
        $this->actingAs($this->editor());

        $mine = Yatra::create([
            'devotee_id' => Devotee::factory()->create(['name' => 'Anusha'])->id,
            'title' => 'Char Dham',
            'status' => YatraStatus::Planning,
        ]);
        $theirs = Yatra::create([
            'devotee_id' => Devotee::factory()->create(['name' => 'Ravi'])->id,
            'title' => 'Jyotirlinga',
            'status' => YatraStatus::Completed,
        ]);

        Livewire::test(ListYatras::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$mine, $theirs])
            ->filterTable('upcoming')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_the_trips_badge_counts_only_the_ones_still_to_happen(): void
    {
        $devotee = Devotee::factory()->create();

        $this->assertNull(YatraResource::getNavigationBadge());

        Yatra::create(['devotee_id' => $devotee->id, 'title' => 'A', 'status' => YatraStatus::Planning]);
        Yatra::create(['devotee_id' => $devotee->id, 'title' => 'B', 'status' => YatraStatus::Completed]);

        $this->assertSame('1', YatraResource::getNavigationBadge());
    }

    // --- The analytics page ---

    /**
     * The widgets are mounted, not just the page requested.
     *
     * Filament loads widgets lazily over Livewire, so a plain GET returns 200
     * with placeholders where the numbers will be — exactly the shape of
     * failure that let a broken relation manager pass a page test before.
     */
    public function test_the_analytics_page_renders_its_widgets(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get(DevoteeAnalytics::getUrl())->assertOk();

        foreach ((new DevoteeAnalytics)->getWidgets() as $widget) {
            Livewire::test($widget)->assertOk();
        }
    }

    /** And the figures are the ones the support class defines. */
    public function test_the_audience_widget_counts_people_not_sign_ins(): void
    {
        $this->actingAs($this->superAdmin());

        $devotee = Devotee::factory()->create();

        // Three sign-ins today by one person is one active user, not three.
        foreach (range(1, 3) as $ignored) {
            LoginEvent::create([
                'authenticatable_type' => $devotee->getMorphClass(),
                'authenticatable_id' => $devotee->getKey(),
                'guard' => 'devotee',
                'succeeded' => true,
                'occurred_at' => now(),
            ]);
        }

        $this->assertSame(1, DevoteeStats::activeUsers(1));
        $this->assertSame(3, DevoteeStats::signIns(1));

        Livewire::test(DevoteeAudienceWidget::class)
            ->assertOk()
            ->assertSee('Active today');
    }

    /**
     * The analytics widgets must not leak onto the main dashboard, which is a
     * work queue: mixing "monthly active users" with "two temples awaiting
     * review" serves neither.
     */
    public function test_the_analytics_widgets_stay_off_the_dashboard(): void
    {
        $dashboardWidgets = filament()->getPanel('admin')->getWidgets();

        foreach ($dashboardWidgets as $widget) {
            $this->assertStringNotContainsString(
                'Widgets\\Devotees\\',
                is_string($widget) ? $widget : $widget::class,
            );
        }
    }
}
