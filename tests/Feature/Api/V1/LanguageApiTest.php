<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TempleStatus;
use App\Models\Devotee;
use App\Models\Temple;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Serving the API in a devotee's own language.
 *
 * Two rules carry the feature. A missing translation falls back to English
 * rather than to nothing, because a listing that renders half-blank in Telugu
 * looks broken rather than untranslated. And an unreviewed translation is not
 * served at all: a deity's name rendered wrongly in someone's own language is
 * worse than the English they can at least recognise.
 */
class LanguageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function temple(): Temple
    {
        return Temple::create([
            'name' => 'Kashi Vishwanath Temple',
            'short_description' => 'One of the twelve Jyotirlingas.',
            'dress_code' => 'Traditional dress is expected.',
            'status' => TempleStatus::Published,
        ]);
    }

    public function test_the_language_list_is_served_rather_than_compiled_into_the_app(): void
    {
        $response = $this->getJson('/api/v1/languages')->assertOk();

        $codes = collect($response->json('data.languages'))->pluck('code');

        $this->assertTrue($codes->contains('te'));
        $this->assertTrue($codes->contains('hi'));
        $this->assertSame('en', $response->json('data.fallback'));

        $telugu = collect($response->json('data.languages'))->firstWhere('code', 'te');

        // The native name, because a picker offering "Telugu" to someone who
        // reads Telugu has already failed them once.
        $this->assertSame('తెలుగు', $telugu['native_name']);
        $this->assertTrue($telugu['is_available']);
    }

    public function test_a_reviewed_translation_is_served_in_that_language(): void
    {
        $temple = $this->temple();
        $temple->setTranslation('name', 'te', 'కాశీ విశ్వనాథ ఆలయం', isReviewed: true);

        $response = $this->getJson("/api/v1/temples/{$temple->slug}?lang=te")->assertOk();

        $this->assertSame('కాశీ విశ్వనాథ ఆలయం', $response->json('data.name'));
        $this->assertSame('te', $response->json('data.language'));
        $this->assertSame('te', $response->headers->get('Content-Language'));
    }

    public function test_an_untranslated_field_falls_back_to_english(): void
    {
        $temple = $this->temple();
        $temple->setTranslation('name', 'te', 'కాశీ విశ్వనాథ ఆలయం', isReviewed: true);

        $response = $this->getJson("/api/v1/temples/{$temple->slug}?lang=te")->assertOk();

        // Name translated, description not: the card still renders.
        $this->assertSame('One of the twelve Jyotirlingas.', $response->json('data.about.short_description'));
    }

    public function test_an_unreviewed_translation_is_not_served(): void
    {
        $temple = $this->temple();
        $temple->setTranslation('name', 'te', 'MACHINE OUTPUT', isReviewed: false);

        $response = $this->getJson("/api/v1/temples/{$temple->slug}?lang=te")->assertOk();

        $this->assertSame('Kashi Vishwanath Temple', $response->json('data.name'));
    }

    public function test_the_list_endpoint_is_translated_too(): void
    {
        $temple = $this->temple();
        $temple->setTranslation('name', 'hi', 'काशी विश्वनाथ मंदिर', isReviewed: true);

        $response = $this->getJson('/api/v1/temples?lang=hi')->assertOk();

        $this->assertSame('काशी विश्वनाथ मंदिर', $response->json('data.0.name'));
    }

    /** Dress code first among all of them: not understanding it means being turned away. */
    public function test_visitor_rules_are_translated(): void
    {
        $temple = $this->temple();
        $temple->setTranslation('dress_code', 'ta', 'பாரம்பரிய உடை அணிய வேண்டும்.', isReviewed: true);

        $response = $this->getJson("/api/v1/temples/{$temple->slug}?lang=ta")->assertOk();

        $this->assertSame('பாரம்பரிய உடை அணிய வேண்டும்.', $response->json('data.visitor_rules.dress_code'));
    }

    public function test_an_unknown_language_falls_back_rather_than_erroring(): void
    {
        $temple = $this->temple();

        $response = $this->getJson("/api/v1/temples/{$temple->slug}?lang=../../etc/passwd")->assertOk();

        $this->assertSame('en', $response->json('data.language'));
    }

    /**
     * A device sending `ta;q=0.9, en` prefers English. A naive explode(',')
     * would serve Tamil.
     */
    public function test_accept_language_honours_quality_values(): void
    {
        $temple = $this->temple();
        $temple->setTranslation('name', 'ta', 'TAMIL', isReviewed: true);

        $this->getJson("/api/v1/temples/{$temple->slug}", ['Accept-Language' => 'ta;q=0.5, en;q=1.0'])
            ->assertOk()
            ->assertJsonPath('data.language', 'en');

        $this->getJson("/api/v1/temples/{$temple->slug}", ['Accept-Language' => 'ta;q=1.0, en;q=0.5'])
            ->assertOk()
            ->assertJsonPath('data.language', 'ta');
    }

    public function test_a_region_tag_resolves_to_its_language(): void
    {
        $this->assertSame('te', Locales::fromAcceptLanguage('te-IN,en;q=0.8'));
    }

    public function test_an_explicit_parameter_beats_the_devotee_s_stored_preference(): void
    {
        $temple = $this->temple();
        $temple->setTranslation('name', 'hi', 'HINDI', isReviewed: true);
        $temple->setTranslation('name', 'te', 'TELUGU', isReviewed: true);

        Sanctum::actingAs(Devotee::factory()->create(['locale' => 'hi']), guard: 'devotee');

        $this->getJson("/api/v1/temples/{$temple->slug}")->assertJsonPath('data.name', 'HINDI');
        $this->getJson("/api/v1/temples/{$temple->slug}?lang=te")->assertJsonPath('data.name', 'TELUGU');
    }

    /**
     * Without Vary, a cache in front of this serves the Telugu response to
     * the next devotee who asked for Tamil.
     */
    public function test_the_response_varies_on_accept_language(): void
    {
        $temple = $this->temple();

        $this->getJson("/api/v1/temples/{$temple->slug}")
            ->assertOk()
            ->assertHeader('Vary', 'Accept-Language');
    }

    // --- The trait's own rules ---

    public function test_a_field_that_is_not_translatable_is_refused(): void
    {
        $temple = $this->temple();

        $this->expectException(\InvalidArgumentException::class);

        // Translating a slug produces a broken URL, not a localised one.
        $temple->setTranslation('slug', 'te', 'whatever');
    }

    public function test_an_unsupported_language_is_refused(): void
    {
        $temple = $this->temple();

        $this->expectException(\InvalidArgumentException::class);

        $temple->setTranslation('name', 'zz', 'whatever');
    }

    /** "No translation" must have exactly one representation, or coverage lies. */
    public function test_clearing_a_translation_removes_the_row(): void
    {
        $temple = $this->temple();
        $temple->setTranslation('name', 'te', 'TELUGU');

        $this->assertDatabaseCount('translations', 1);

        $temple->setTranslation('name', 'te', '');

        $this->assertDatabaseCount('translations', 0);
    }

    public function test_coverage_counts_only_fields_that_have_something_to_translate(): void
    {
        $temple = $this->temple();

        // Three of the translatable fields carry a value: name, short
        // description, dress code. History and the rest are empty, and
        // counting against them would report a sparse temple as neglected.
        $this->assertSame(0.0, $temple->translationCoverage('te'));

        $temple->setTranslation('name', 'te', 'A');
        $temple->setTranslation('short_description', 'te', 'B');
        $temple->setTranslation('dress_code', 'te', 'C');

        $this->assertSame(1.0, $temple->fresh()->translationCoverage('te'));
    }

    public function test_english_is_always_fully_covered(): void
    {
        $this->assertSame(1.0, $this->temple()->translationCoverage('en'));
    }
}
