<?php

namespace Tests\Feature\Translation;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\RelationManagers\TranslationsRelationManager;
use App\Filament\Resources\Temples\Pages\EditTemple;
use App\Models\Temple;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** After auto-translate, the drafts are marked reviewed together rather than one by one. */
class BulkReviewTest extends TestCase
{
    use RefreshDatabase;

    protected Temple $temple;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temple = Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'dress_code' => 'Traditional dress', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $this->temple->setTranslation('name', 'te', 'శ్రీ రామ ఆలయం', isReviewed: false);
        $this->temple->setTranslation('dress_code', 'te', 'సంప్రదాయ దుస్తులు', isReviewed: false);
        $this->temple->setTranslation('name', 'hi', 'श्री राम मंदिर', isReviewed: false);
    }

    private function manager()
    {
        return Livewire::test(TranslationsRelationManager::class, ['ownerRecord' => $this->temple, 'pageClass' => EditTemple::class]);
    }

    public function test_all_waiting_translations_in_chosen_languages_are_marked_reviewed(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($admin);

        $this->manager()->callAction(TestAction::make('reviewAll')->table(), ['locales' => ['te']])->assertHasNoErrors();

        $rows = $this->temple->translations()->get();
        $this->assertTrue($rows->where('locale', 'te')->every->is_reviewed);
        $this->assertSame($admin->id, $rows->firstWhere('locale', 'te')->reviewed_by);
        $this->assertFalse($rows->firstWhere('locale', 'hi')->is_reviewed);

        // No language chosen: all of them.
        $this->manager()->callAction(TestAction::make('reviewAll')->table(), ['locales' => []]);
        $this->assertTrue($this->temple->translations()->get()->every->is_reviewed);
        $this->assertSame('श्री राम मंदिर', $this->temple->fresh()->translate('name', 'hi', reviewedOnly: true));
    }

    public function test_selected_rows_are_marked_reviewed_together(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));
        $pick = $this->temple->translations()->where('locale', 'te')->get();

        $this->manager()->selectTableRecords($pick->modelKeys())
            ->callAction(TestAction::make('reviewSelected')->table()->bulk())
            ->assertHasNoErrors();

        $this->assertSame(['hi'], $this->temple->translations()->where('is_reviewed', false)->pluck('locale')->all());
    }

    public function test_editors_who_cannot_publish_cannot_mark_reviewed(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor]));

        $this->manager()->assertActionHidden(TestAction::make('reviewAll')->table());
        $this->assertSame(3, $this->temple->translations()->where('is_reviewed', false)->count());
    }
}
