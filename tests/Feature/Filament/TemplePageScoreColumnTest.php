<?php

namespace Tests\Feature\Filament;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Temples\Pages\ListTemples;
use App\Models\Temple;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Admin → Temples shows how complete each public page is, and what to add. */
class TemplePageScoreColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_temples_list_shows_the_page_score_and_what_is_missing(): void
    {
        $bare = Temple::create(['name' => 'Bare Temple', 'slug' => 'bare', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

        $this->get('/admin/temples')->assertOk()
            ->assertSee('Page score')
            ->assertSee('0%')
            ->assertSee('Add: Cover photo, At least 3 photos, Timings +9 more');

        Livewire::test(ListTemples::class)
            ->assertTableColumnVisible('page_score')
            ->filterTable('without_timings')->assertCanSeeTableRecords([$bare])
            ->resetTableFilters()
            ->filterTable('few_photos')->assertCanSeeTableRecords([$bare])
            ->resetTableFilters()
            ->filterTable('without_description')->assertCanSeeTableRecords([$bare]);
    }
}
