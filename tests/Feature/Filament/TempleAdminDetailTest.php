<?php

namespace Tests\Feature\Filament;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Deities\DeityResource;
use App\Filament\Resources\TemplePhotos\TemplePhotoResource;
use App\Filament\Resources\TemplePujas\Pages\ListTemplePujas;
use App\Filament\Resources\TemplePujas\TemplePujaResource;
use App\Filament\Resources\Temples\Pages\CreateTemple;
use App\Filament\Resources\Temples\Pages\EditTemple;
use App\Filament\Resources\Temples\Pages\ListTemples;
use App\Models\Deity;
use App\Models\State;
use App\Models\Temple;
use App\Models\TemplePhoto;
use App\Models\TemplePuja;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The detail added to the temple side of the admin: images where they were
 * missing, the lists that were only reachable through a temple, and the cuts
 * of the temple list people actually take.
 */
class TempleAdminDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function staff(UserRole $role = UserRole::SuperAdmin): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    // --- Deity images and mantras ---

    public function test_a_deity_carries_an_image_and_a_mantra(): void
    {
        $deity = Deity::create([
            'name' => 'Shiva',
            'slug' => 'shiva',
            'image_disk' => 'public',
            'image_path' => 'deities/shiva.jpg',
            'mantra' => 'ॐ नमः शिवाय',
            'mantra_transliteration' => 'Om Namah Shivaya',
            'accent_color' => '#4E7A51',
        ]);

        $this->assertStringStartsWith(url('/storage/'), $deity->imageUrl());
        $this->assertTrue($deity->hasMantra());
        $this->assertSame('#4E7A51', $deity->accentColor());
    }

    /** A deity with no colour still themes a screen rather than leaving a gap. */
    public function test_a_deity_without_a_colour_falls_back_to_the_brand(): void
    {
        $deity = Deity::create(['name' => 'Shiva', 'slug' => 'shiva']);

        $this->assertSame(config('brand.colors.saffron.hex'), $deity->accentColor());
    }

    public function test_the_deity_list_shows_what_is_still_missing(): void
    {
        $this->actingAs($this->staff());

        $complete = Deity::create([
            'name' => 'Shiva', 'slug' => 'shiva',
            'image_path' => 'deities/shiva.jpg', 'mantra' => 'ॐ नमः शिवाय',
        ]);
        $bare = Deity::create(['name' => 'Hanuman', 'slug' => 'hanuman']);

        Livewire::test(\App\Filament\Resources\Deities\Pages\ListDeities::class)
            ->assertOk()
            ->filterTable('needs_image')
            ->assertCanSeeTableRecords([$bare])
            ->assertCanNotSeeTableRecords([$complete]);
    }

    // --- Mantra fallback ---

    public function test_a_temple_falls_back_to_its_deity_s_mantra(): void
    {
        $deity = Deity::create([
            'name' => 'Vishnu', 'slug' => 'vishnu',
            'mantra' => 'ॐ नमो नारायणाय',
            'mantra_transliteration' => 'Om Namo Narayanaya',
        ]);

        $withOwn = Temple::create([
            'name' => 'Tirumala', 'deity_id' => $deity->id,
            'mantra' => 'कौसल्या सुप्रजा राम',
            'mantra_transliteration' => 'Kausalya Supraja Rama',
        ]);

        $withoutOwn = Temple::create(['name' => 'Another Vishnu Temple', 'deity_id' => $deity->id]);

        // Its own where it has one — the Suprabhatam is sung at Tirumala.
        $this->assertSame('कौसल्या सुप्रजा राम', $withOwn->mantraText());
        // The deity's otherwise, rather than a heading with nothing under it.
        $this->assertSame('ॐ नमो नारायणाय', $withoutOwn->mantraText());
        $this->assertSame('Om Namo Narayanaya', $withoutOwn->mantraTransliteration());
    }

    public function test_a_temple_with_no_deity_and_no_mantra_returns_nothing(): void
    {
        $this->assertNull(Temple::create(['name' => 'Bare'])->mantraText());
    }

    // --- Temple media, and its precedence over the deity's ---

    public function test_a_temple_s_own_songs_come_before_its_deity_s(): void
    {
        $deity = Deity::create(['name' => 'Vishnu', 'slug' => 'vishnu']);
        $temple = Temple::create(['name' => 'Tirumala', 'deity_id' => $deity->id]);

        $deity->media()->create([
            'type' => \App\Enums\DevotionalMediaType::Chant,
            'title' => 'Vishnu Sahasranama',
            'external_url' => 'https://example.com/deity',
            'is_published' => true,
        ]);

        $temple->media()->create([
            'type' => \App\Enums\DevotionalMediaType::Chant,
            'title' => 'Suprabhatam',
            'external_url' => 'https://example.com/temple',
            'is_published' => true,
        ]);

        $titles = $temple->fresh()->allMedia()->pluck('title')->all();

        $this->assertSame(['Suprabhatam', 'Vishnu Sahasranama'], $titles);
    }

    public function test_unpublished_media_is_left_out_of_the_combined_list(): void
    {
        $deity = Deity::create(['name' => 'Vishnu', 'slug' => 'vishnu']);
        $temple = Temple::create(['name' => 'Tirumala', 'deity_id' => $deity->id]);

        $temple->media()->create([
            'type' => \App\Enums\DevotionalMediaType::Chant,
            'title' => 'Draft',
            'external_url' => 'https://example.com/draft',
            'is_published' => false,
        ]);

        $this->assertCount(0, $temple->fresh()->allMedia());
        $this->assertCount(1, $temple->fresh()->allMedia(publishedOnly: false));
    }

    /** Media with no owner is media nobody can reach. */
    public function test_media_cannot_be_created_without_an_owner(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        \App\Models\DevotionalMedia::create([
            'type' => \App\Enums\DevotionalMediaType::Song,
            'title' => 'Orphan',
            'external_url' => 'https://example.com/a',
        ]);
    }

    // --- The cover image on the temple form ---

    public function test_a_cover_image_can_be_set_while_creating_a_temple(): void
    {
        $this->actingAs($this->staff());

        Livewire::test(CreateTemple::class)
            ->fillForm([
                'name' => 'Kashi Vishwanath',
                'status' => TempleStatus::Draft->value,
                'cover_image' => ['temples/covers/kashi.jpg'],
                'cover_image_credit' => 'Photo by A Devotee',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $temple = Temple::firstOrFail();
        $photo = $temple->photos()->where('is_primary', true)->first();

        // Written to temple_photos, not to a second column that could
        // disagree with the gallery about which photo leads.
        $this->assertNotNull($photo);
        $this->assertSame('temples/covers/kashi.jpg', $photo->path);
        $this->assertSame('Photo by A Devotee', $photo->credit);
    }

    public function test_the_cover_image_loads_back_into_the_form(): void
    {
        $this->actingAs($this->staff());

        // A real file on the disk: Filament drops an upload path whose file
        // is missing, so asserting against a made-up path would pass for the
        // wrong reason or fail for one.
        Storage::fake('public');
        Storage::disk('public')->put('temples/1/cover.jpg', 'not really a jpeg');

        $temple = Temple::create(['name' => 'Kashi Vishwanath']);
        $temple->photos()->create([
            'disk' => 'public',
            'path' => 'temples/1/cover.jpg',
            'credit' => 'A Photographer',
            'is_primary' => true,
        ]);

        $state = Livewire::test(EditTemple::class, ['record' => $temple->getKey()])
            ->assertFormSet(['cover_image_credit' => 'A Photographer'])
            ->instance()
            ->form
            ->getRawState();

        $this->assertContains('temples/1/cover.jpg', (array) ($state['cover_image'] ?? []));
    }

    /**
     * Clearing the field means "not this one as the cover", not "destroy this
     * photograph" — which would take the file with it.
     */
    public function test_clearing_the_cover_keeps_the_photo_in_the_gallery(): void
    {
        $this->actingAs($this->staff());

        $temple = Temple::create(['name' => 'Kashi Vishwanath']);
        $photo = $temple->photos()->create([
            'disk' => 'public',
            'path' => 'temples/1/cover.jpg',
            'is_primary' => true,
        ]);

        Livewire::test(EditTemple::class, ['record' => $temple->getKey()])
            ->fillForm(['cover_image' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertModelExists($photo);
        $this->assertFalse($photo->fresh()->is_primary);
    }

    public function test_replacing_the_cover_demotes_the_old_one_rather_than_deleting_it(): void
    {
        $this->actingAs($this->staff());

        $temple = Temple::create(['name' => 'Kashi Vishwanath']);
        $original = $temple->photos()->create([
            'disk' => 'public', 'path' => 'temples/1/old.jpg', 'is_primary' => true,
        ]);

        Livewire::test(EditTemple::class, ['record' => $temple->getKey()])
            ->fillForm(['cover_image' => ['temples/covers/new.jpg']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertModelExists($original);
        $this->assertFalse($original->fresh()->is_primary);
        $this->assertSame('temples/covers/new.jpg', $temple->fresh()->photos()->where('is_primary', true)->value('path'));
        $this->assertSame(2, $temple->photos()->count());
    }

    // --- The side-menu lists ---

    public function test_pujas_are_reachable_without_opening_a_temple(): void
    {
        $this->actingAs($this->staff(UserRole::Editor));

        $temple = Temple::create(['name' => 'Kashi Vishwanath']);
        $priced = TemplePuja::create(['temple_id' => $temple->id, 'name' => 'Archana', 'fee_amount' => 100]);
        $unpriced = TemplePuja::create(['temple_id' => $temple->id, 'name' => 'Abhishekam']);

        $this->get(TemplePujaResource::getUrl('index'))->assertOk();

        Livewire::test(ListTemplePujas::class)
            ->assertCanSeeTableRecords([$priced, $unpriced])
            // The reason the list exists: finding what has no published price
            // across every temple at once.
            ->filterTable('no_price')
            ->assertCanSeeTableRecords([$unpriced])
            ->assertCanNotSeeTableRecords([$priced]);
    }

    public function test_the_photo_library_finds_the_uncredited_ones(): void
    {
        $this->actingAs($this->staff(UserRole::Editor));

        $temple = Temple::create(['name' => 'Kashi Vishwanath']);
        $credited = TemplePhoto::create([
            'temple_id' => $temple->id, 'disk' => 'public',
            'path' => 'a.jpg', 'credit' => 'A Photographer',
        ]);
        $uncredited = TemplePhoto::create([
            'temple_id' => $temple->id, 'disk' => 'public', 'path' => 'b.jpg',
        ]);

        $this->get(TemplePhotoResource::getUrl('index'))->assertOk();

        Livewire::test(\App\Filament\Resources\TemplePhotos\Pages\ListTemplePhotos::class)
            ->filterTable('uncredited')
            ->assertCanSeeTableRecords([$uncredited])
            ->assertCanNotSeeTableRecords([$credited]);

        $this->assertSame('1', TemplePhotoResource::getNavigationBadge());
    }

    // --- The temple list's cuts ---

    public function test_the_temple_list_can_be_grouped_state_wise_and_god_wise(): void
    {
        $this->actingAs($this->staff());

        $groups = collect(Livewire::test(ListTemples::class)->instance()->getTable()->getGroups())
            ->map(fn ($group): string => $group->getId());

        $this->assertTrue($groups->contains('state.name'));
        $this->assertTrue($groups->contains('deity.name'));
    }

    public function test_the_needs_work_tab_finds_published_temples_a_devotee_would_be_let_down_by(): void
    {
        $this->actingAs($this->staff());

        $state = State::create(['name' => 'Telangana', 'slug' => 'telangana', 'code' => 'TG']);

        $complete = Temple::create([
            'name' => 'Complete', 'status' => TempleStatus::Published, 'state_id' => $state->id,
            'latitude' => 17.3, 'longitude' => 78.4, 'short_description' => 'A description.',
        ]);
        $complete->photos()->create(['disk' => 'public', 'path' => 'a.jpg']);

        $unmapped = Temple::create([
            'name' => 'No coordinates', 'status' => TempleStatus::Published,
            'short_description' => 'A description.',
        ]);
        $unmapped->photos()->create(['disk' => 'public', 'path' => 'b.jpg']);

        $draft = Temple::create(['name' => 'Draft', 'status' => TempleStatus::Draft]);

        Livewire::test(ListTemples::class)
            ->set('activeTab', 'needs_work')
            ->assertCanSeeTableRecords([$unmapped])
            // A draft is not something a devotee can reach, so it is not on
            // this tab however incomplete it is.
            ->assertCanNotSeeTableRecords([$complete, $draft]);
    }

    public function test_the_deity_resource_gained_its_relation_managers(): void
    {
        $relations = DeityResource::getRelations();

        $this->assertContains(\App\Filament\RelationManagers\DevotionalMediaRelationManager::class, $relations);
        $this->assertContains(\App\Filament\RelationManagers\TranslationsRelationManager::class, $relations);
    }
}
