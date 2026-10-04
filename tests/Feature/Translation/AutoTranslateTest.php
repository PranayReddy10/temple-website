<?php

namespace Tests\Feature\Translation;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\RelationManagers\TranslationsRelationManager;
use App\Filament\Resources\Temples\Pages\EditTemple;
use App\Models\Deity;
use App\Models\Setting;
use App\Models\Temple;
use App\Models\TempleUser;
use App\Models\User;
use App\Support\Translation\AutoTranslateFailed;
use App\Support\Translation\AutoTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Temple names in the reader's language, and auto-translate as a draft that
 * a person reads before devotees see it.
 */
class AutoTranslateTest extends TestCase
{
    use RefreshDatabase;

    protected Temple $temple;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temple = Temple::create([
            'name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'city' => 'Bhadrachalam',
            'dress_code' => 'Traditional dress only.',
            'status' => TempleStatus::Published, 'published_at' => now(),
        ]);
    }

    protected function fakeMyMemory(string $answer = 'అనువాదం'): void
    {
        Http::fake(['api.mymemory.translated.net/*' => Http::response([
            'responseData' => ['translatedText' => $answer],
            'responseStatus' => 200,
            'quotaFinished' => false,
        ])]);
    }

    /** @return array{0: string, 1: User} */
    protected function teamMember(string $role = 'manager'): array
    {
        $token = $this->postJson('/api/v1/trust/auth/register', [
            'name' => 'Trust Secretary', 'email' => 'secretary@example.org',
            'phone' => '9876543210', 'password' => 'a-long-password',
        ])->assertCreated()->json('data.token');
        $user = User::query()->where('email', 'secretary@example.org')->firstOrFail();

        TempleUser::create([
            'temple_id' => $this->temple->id, 'user_id' => $user->id, 'role' => $role,
            'requested_at' => now(), 'approved_at' => now(),
        ]);

        return [$token, $user];
    }

    public function test_mymemory_is_the_free_default_and_answers_are_cached(): void
    {
        $this->fakeMyMemory('శ్రీ రామ ఆలయం');

        $this->assertSame('mymemory', AutoTranslator::provider());
        $this->assertSame('శ్రీ రామ ఆలయం', AutoTranslator::translate('Sri Rama Temple', 'te'));
        $this->assertSame('శ్రీ రామ ఆలయం', AutoTranslator::translate('Sri Rama Temple', 'te'));

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'langpair=en%7Cte'));
    }

    public function test_long_text_goes_to_mymemory_in_pieces_under_its_limit(): void
    {
        $this->fakeMyMemory('x');
        $text = str_repeat('Remove footwear at the gate. ', 40);

        AutoTranslator::translate($text, 'hi');

        Http::assertSent(fn (HttpRequest $r): bool => mb_strlen((string) $r['q']) <= 450);
        $this->assertGreaterThan(1, count(Http::recorded()));
    }

    public function test_a_used_up_quota_is_reported_plainly(): void
    {
        Http::fake(['api.mymemory.translated.net/*' => Http::response([
            'responseData' => ['translatedText' => 'MYMEMORY WARNING: YOU USED ALL AVAILABLE FREE TRANSLATIONS FOR TODAY'],
            'responseStatus' => 429,
            'quotaFinished' => true,
        ])]);

        $this->expectException(AutoTranslateFailed::class);
        $this->expectExceptionMessage('free translations are used up');

        AutoTranslator::translate('Sri Rama Temple', 'ta');
    }

    public function test_google_is_used_when_chosen_and_a_key_is_saved(): void
    {
        Setting::set('auto_translate_provider', 'google', 'string');
        Setting::set('google_translate_api_key', 'test-key', 'secret');
        Http::fake(['translation.googleapis.com/*' => Http::response(['data' => ['translations' => [['translatedText' => 'श्री राम मंदिर']]]])]);

        $this->assertSame('श्री राम मंदिर', AutoTranslator::translate('Sri Rama Temple', 'hi'));
        Http::assertSent(fn (HttpRequest $r): bool => $r['target'] === 'hi' && $r['key'] === 'test-key');
    }

    public function test_switched_off_means_no_requests(): void
    {
        Setting::set('auto_translate_enabled', '0', 'boolean');
        Http::fake();

        try {
            AutoTranslator::translate('Sri Rama Temple', 'te');
            $this->fail('Expected a failure');
        } catch (AutoTranslateFailed) {
            Http::assertNothingSent();
        }
    }

    public function test_apis_give_temple_and_deity_names_in_the_readers_language_once_reviewed(): void
    {
        $deity = Deity::create(['name' => 'Rama', 'slug' => 'rama']);
        $this->temple->update(['deity_id' => $deity->id]);
        $this->temple->setTranslation('name', 'te', 'శ్రీ రామ ఆలయం', isReviewed: true);
        $deity->setTranslation('name', 'te', 'రాముడు', isReviewed: true);

        $this->assertSame('శ్రీ రామ ఆలయం', $this->temple->fresh()->localName('te'));
        $this->assertSame('Sri Rama Temple', $this->temple->fresh()->localName('en'));

        $this->temple->setTranslation('name', 'hi', 'श्री राम मंदिर', isReviewed: false);
        $this->assertSame('Sri Rama Temple', $this->temple->fresh()->localName('hi'), 'an unreviewed draft is never served');

        [$token] = $this->teamMember();
        $this->withToken($token)->withHeader('Accept-Language', 'te')
            ->getJson('/api/v1/trust/temples')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'శ్రీ రామ ఆలయం')
            ->assertJsonPath('data.0.name_en', 'Sri Rama Temple')
            ->assertJsonPath('data.0.deity', 'రాముడు');
    }

    public function test_a_team_translates_its_temple_and_team_fields_publish_directly(): void
    {
        [$token, $user] = $this->teamMember();

        $this->withToken($token)->getJson("/api/v1/trust/temples/{$this->temple->id}/translations")
            ->assertOk()
            ->assertJsonPath('data.auto_translate', true)
            ->assertJsonPath('data.languages.0.code', 'te')
            ->assertJsonFragment(['field' => 'dress_code', 'english' => 'Traditional dress only.', 'publishes_directly' => true])
            ->assertJsonFragment(['field' => 'name', 'publishes_directly' => false]);

        $this->withToken($token)->putJson("/api/v1/trust/temples/{$this->temple->id}/translations", [
            'field' => 'dress_code', 'locale' => 'te', 'value' => 'సాంప్రదాయ దుస్తులు మాత్రమే.',
        ])->assertOk();

        // The name is our editors' to approve, like a change to the English.
        $this->withToken($token)->putJson("/api/v1/trust/temples/{$this->temple->id}/translations", [
            'field' => 'name', 'locale' => 'te', 'value' => 'శ్రీ రామ ఆలయం',
        ])->assertOk()->assertJsonFragment(['te' => ['value' => 'శ్రీ రామ ఆలయం', 'is_reviewed' => false]]);

        $temple = $this->temple->fresh();
        $this->assertSame('సాంప్రదాయ దుస్తులు మాత్రమే.', $temple->translate('dress_code', 'te', reviewedOnly: true));
        $this->assertSame($user->id, $temple->translations->firstWhere('field', 'dress_code')->reviewed_by);
        $this->assertSame('Sri Rama Temple', $temple->localName('te'));

        // Blank removes it.
        $this->withToken($token)->putJson("/api/v1/trust/temples/{$this->temple->id}/translations", [
            'field' => 'dress_code', 'locale' => 'te', 'value' => '',
        ])->assertOk();
        $this->assertSame(1, $this->temple->translations()->count());

        // Only translatable fields, only the apps' languages.
        $this->withToken($token)->putJson("/api/v1/trust/temples/{$this->temple->id}/translations", [
            'field' => 'slug', 'locale' => 'te', 'value' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors('field');
        $this->withToken($token)->putJson("/api/v1/trust/temples/{$this->temple->id}/translations", [
            'field' => 'name', 'locale' => 'en', 'value' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors('locale');
    }

    public function test_suggest_returns_a_draft_and_saves_nothing(): void
    {
        $this->fakeMyMemory('సాంప్రదాయ దుస్తులు మాత్రమే.');
        [$token] = $this->teamMember();

        $this->withToken($token)->postJson("/api/v1/trust/temples/{$this->temple->id}/translations/suggest", [
            'field' => 'dress_code', 'locale' => 'te',
        ])->assertOk()->assertJsonPath('data.value', 'సాంప్రదాయ దుస్తులు మాత్రమే.');

        $this->assertSame(0, $this->temple->translations()->count());

        $this->withToken($token)->postJson("/api/v1/trust/temples/{$this->temple->id}/translations/suggest", [
            'field' => 'history', 'locale' => 'te',
        ])->assertStatus(422);
    }

    public function test_another_temples_translations_are_not_found(): void
    {
        $other = Temple::create(['name' => 'Another', 'slug' => 'another', 'status' => TempleStatus::Published, 'published_at' => now()]);
        [$token] = $this->teamMember();

        $this->withToken($token)->getJson("/api/v1/trust/temples/{$other->id}/translations")->assertNotFound();
        $this->withToken($token)->putJson("/api/v1/trust/temples/{$other->id}/translations", ['field' => 'name', 'locale' => 'te', 'value' => 'x'])->assertNotFound();
        $this->withToken($token)->postJson("/api/v1/trust/temples/{$other->id}/translations/suggest", ['field' => 'name', 'locale' => 'te'])->assertNotFound();
    }

    public function test_admin_drafts_missing_translations_unreviewed(): void
    {
        $this->fakeMyMemory('draft');
        $this->temple->setTranslation('name', 'te', 'శ్రీ రామ ఆలయం', isReviewed: true);
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($admin);

        Livewire::test(TranslationsRelationManager::class, ['ownerRecord' => $this->temple, 'pageClass' => EditTemple::class])
            ->call('translateMissing', ['te', 'hi']);

        $rows = $this->temple->translations()->get();
        // name + dress_code in hi, dress_code in te; the reviewed Telugu name is kept.
        $this->assertCount(4, $rows);
        $this->assertSame('శ్రీ రామ ఆలయం', $rows->where('locale', 'te')->firstWhere('field', 'name')->value);
        $this->assertSame(3, $rows->where('is_reviewed', false)->count());
    }
}
