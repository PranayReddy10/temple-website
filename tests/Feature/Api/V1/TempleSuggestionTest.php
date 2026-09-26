<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TempleStatus;
use App\Enums\TempleSuggestionStatus;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Filament\Resources\TempleSuggestions\Pages\ViewTempleSuggestion;
use App\Filament\Resources\TempleSuggestions\TempleSuggestionResource;
use App\Models\Devotee;
use App\Models\Temple;
use App\Models\TempleSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** "My temple is not listed": added from the app, reviewed before it is public. */
class TempleSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected const JSON = ['Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.media'));
    }

    /** @return array<string, mixed> */
    protected function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sri Someshwara Swamy Temple',
            'alternate_names' => 'Kolanupaka Shiva temple',
            'city' => 'Kolanupaka',
            'district' => 'Yadadri Bhuvanagiri',
            'pincode' => '508101',
            'description' => 'A small Chalukya-era Shiva temple beside the old Jain temple, cared for by the village.',
            'opens_at' => '06:00',
            'closes_at' => '20:00',
            'submitter_role' => 'devotee',
            'photos' => [UploadedFile::fake()->image('front.jpg')],
        ], $overrides);
    }

    public function test_a_devotee_adds_a_temple_that_waits_for_review(): void
    {
        Sanctum::actingAs(Devotee::factory()->create(['name' => 'Ravi']), guard: 'devotee');

        $this->post('/api/v1/me/temple-suggestions', $this->form(), self::JSON)
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'pending')
            ->assertJsonPath('data.status.label', 'Being reviewed');

        $suggestion = TempleSuggestion::query()->firstOrFail();
        $this->assertSame('Ravi', $suggestion->submitter_name);
        $this->assertSame(1, $suggestion->photos()->count());

        // Nothing is published: it is not a temple yet.
        $this->assertSame(0, Temple::query()->count());
        $this->getJson('/api/v1/me/temple-suggestions')->assertJsonCount(1, 'data');
    }

    public function test_it_needs_a_photograph_and_a_description(): void
    {
        Sanctum::actingAs(Devotee::factory()->create(), guard: 'devotee');

        $this->post('/api/v1/me/temple-suggestions', $this->form(['photos' => [], 'description' => 'short']), self::JSON)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photos', 'description']);
    }

    public function test_a_temple_member_must_leave_a_number(): void
    {
        Sanctum::actingAs(Devotee::factory()->create(), guard: 'devotee');

        $this->post('/api/v1/me/temple-suggestions', $this->form(['submitter_role' => 'trustee']), self::JSON)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('submitter_phone');

        $this->post('/api/v1/me/temple-suggestions', $this->form(['submitter_role' => 'trustee', 'submitter_phone' => '98480 12345']), self::JSON)
            ->assertCreated();

        $this->assertTrue(TempleSuggestion::query()->firstOrFail()->isFromTempleMember());
    }

    public function test_signing_in_is_required(): void
    {
        $this->post('/api/v1/me/temple-suggestions', $this->form(), self::JSON)->assertUnauthorized();
    }

    public function test_staff_turn_a_suggestion_into_a_draft_temple(): void
    {
        $devotee = Devotee::factory()->create();
        Sanctum::actingAs($devotee, guard: 'devotee');
        $this->post('/api/v1/me/temple-suggestions', $this->form(), self::JSON)->assertCreated();
        $suggestion = TempleSuggestion::query()->firstOrFail();

        $staff = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
        $this->actingAs($staff, 'web');

        $this->get(TempleSuggestionResource::getUrl('index'))->assertOk();
        $this->get(TempleSuggestionResource::getUrl('view', ['record' => $suggestion]))->assertOk()->assertSee('Sri Someshwara Swamy Temple');

        Livewire::test(ViewTempleSuggestion::class, ['record' => $suggestion->getRouteKey()])
            ->callAction('create_temple');

        $temple = Temple::query()->firstOrFail();
        $this->assertSame('Sri Someshwara Swamy Temple', $temple->name);
        $this->assertSame(TempleStatus::Draft, $temple->status);
        $this->assertSame(VerificationStatus::Community, $temple->verification_status);
        $this->assertSame(1, $temple->photos()->count());
        $this->assertSame(TempleSuggestionStatus::Approved, $suggestion->fresh()->status);
        $this->assertSame($temple->id, $suggestion->fresh()->temple_id);
    }

    public function test_staff_mark_one_already_listed_and_the_sender_sees_where(): void
    {
        $temple = Temple::create(['name' => 'Kolanupaka Someshwara', 'status' => TempleStatus::Published]);
        $devotee = Devotee::factory()->create();
        Sanctum::actingAs($devotee, guard: 'devotee');
        $this->post('/api/v1/me/temple-suggestions', $this->form(), self::JSON)->assertCreated();
        $suggestion = TempleSuggestion::query()->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]), 'web');
        Livewire::test(ViewTempleSuggestion::class, ['record' => $suggestion->getRouteKey()])
            ->callAction('duplicate', data: ['temple_id' => $temple->id, 'review_note' => 'Already here.']);

        $this->getJson('/api/v1/me/temple-suggestions')
            ->assertJsonPath('data.0.status.value', 'duplicate')
            ->assertJsonPath('data.0.temple.slug', $temple->slug)
            ->assertJsonPath('data.0.review_note', 'Already here.');
    }

    public function test_a_temple_admin_cannot_reach_the_queue(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]))
            ->get(TempleSuggestionResource::getUrl('index'))
            ->assertForbidden();
    }
}
