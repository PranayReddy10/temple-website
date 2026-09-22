<?php

namespace Tests\Feature\Filament;

use App\Enums\DevotionalMediaType;
use App\Enums\PhotoCategory;
use App\Enums\UserRole;
use App\Filament\Resources\DevotionalDays\Pages\EditDevotionalDay;
use App\Filament\Resources\DevotionalDays\RelationManagers\MediaRelationManager;
use App\Filament\Resources\Temples\Pages\EditTemple;
use App\Filament\Resources\Temples\RelationManagers\ClosuresRelationManager;
use App\Filament\Resources\Temples\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Temples\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Temples\RelationManagers\PujasRelationManager;
use App\Filament\Resources\Temples\RelationManagers\TimingsRelationManager;
use App\Models\Deity;
use App\Models\DevotionalDay;
use App\Models\DevotionalMedia;
use App\Models\Temple;
use App\Models\TempleClosure;
use App\Models\TempleEvent;
use App\Models\TemplePhoto;
use App\Models\TemplePuja;
use App\Models\TempleTiming;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Renders every relation manager, and opens each one's form on a real record.
 *
 * Relation managers load lazily over Livewire, so a plain page request returns
 * 200 while the panel is still broken — which is how an enum-cast TypeError
 * reached production despite the edit page being covered by a test. Mounting
 * the components is what exercises the code that actually ran.
 */
class RelationManagerRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function signIn(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ]));
    }

    protected function temple(): Temple
    {
        return Temple::create(['name' => 'Render Temple']);
    }

    /** @param class-string $manager */
    protected function assertManagerRenders(string $manager, $owner, string $pageClass, $record = null): void
    {
        $component = Livewire::test($manager, [
            'ownerRecord' => $owner,
            'pageClass' => $pageClass,
        ])->assertOk();

        // The create form: field defaults are enum instances here.
        $component->mountAction('create')->assertOk();

        if ($record !== null) {
            // The edit form: state comes from cast model attributes.
            Livewire::test($manager, ['ownerRecord' => $owner, 'pageClass' => $pageClass])
                ->mountAction('edit', ['record' => $record->getKey()])
                ->assertOk();
        }
    }

    public function test_the_photos_manager_renders(): void
    {
        $this->signIn();
        $temple = $this->temple();

        $photo = TemplePhoto::create([
            'temple_id' => $temple->id,
            'disk' => 'public',
            'path' => 'temples/x.jpg',
            // The sanctum category is the one with a conditional helper text.
            'category' => PhotoCategory::Deity,
        ]);

        $this->assertManagerRenders(PhotosRelationManager::class, $temple, EditTemple::class, $photo);
    }

    public function test_the_timings_manager_renders(): void
    {
        $this->signIn();
        $temple = $this->temple();
        $timing = TempleTiming::create(['temple_id' => $temple->id, 'opens_at' => '05:00']);

        $this->assertManagerRenders(TimingsRelationManager::class, $temple, EditTemple::class, $timing);
    }

    public function test_the_pujas_manager_renders(): void
    {
        $this->signIn();
        $temple = $this->temple();
        $puja = TemplePuja::create(['temple_id' => $temple->id, 'name' => 'Archana']);

        $this->assertManagerRenders(PujasRelationManager::class, $temple, EditTemple::class, $puja);
    }

    public function test_the_closures_manager_renders(): void
    {
        $this->signIn();
        $temple = $this->temple();
        $closure = TempleClosure::create([
            'temple_id' => $temple->id,
            'starts_on' => now()->toDateString(),
            'reason' => 'Renovation',
        ]);

        $this->assertManagerRenders(ClosuresRelationManager::class, $temple, EditTemple::class, $closure);
    }

    public function test_the_events_manager_renders(): void
    {
        $this->signIn();
        $temple = $this->temple();
        $event = TempleEvent::create([
            'temple_id' => $temple->id,
            'title' => 'Brahmotsavam',
            'starts_on' => now()->toDateString(),
        ]);

        $this->assertManagerRenders(EventsRelationManager::class, $temple, EditTemple::class, $event);
    }

    public function test_the_devotional_media_manager_renders(): void
    {
        $this->signIn();
        $deity = Deity::create(['name' => 'Hanuman', 'slug' => 'hanuman']);
        $day = DevotionalDay::create(['weekday' => 2, 'deity_id' => $deity->id, 'title' => 'Hanuman']);

        // A song is the case that broke: its type drives two conditional
        // fields, and the state arrives as an enum instance.
        $media = DevotionalMedia::create([
            'devotional_day_id' => $day->id,
            'type' => DevotionalMediaType::Song,
            'title' => 'Bhajan',
            'external_url' => 'https://example.com/a',
            'license' => 'CC BY 4.0',
        ]);

        $this->assertManagerRenders(MediaRelationManager::class, $day, EditDevotionalDay::class, $media);
    }
}
