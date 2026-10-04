<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Models\Temple;
use App\Models\Translation;
use App\Support\Locales;
use App\Support\Translation\AutoTranslateFailed;
use App\Support\Translation\AutoTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A temple's own listing in other languages, kept by its team.
 *
 * What the team may change in English (Temple::TEAM_EDITABLE) it may also
 * publish in other languages straight away: a temple's secretary knows its
 * dress code in Telugu better than we do. The name, history and the other
 * fields our editors own are saved for them to review first, like a change
 * to the English would be. Auto-translate only suggests: nothing it returns
 * is saved until a person reads it and taps Save.
 */
class TrustTranslationController extends Controller
{
    use ScopesToTrustTemples;

    public function index(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple)->load('translations');

        return response()->json(['data' => $this->payload($request, $record)]);
    }

    public function update(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);

        $validated = $request->validate([
            'field' => ['required', 'string', Rule::in($record->translatableFields())],
            'locale' => ['required', 'string', Rule::in($this->languages())],
            'value' => ['nullable', 'string', 'max:5000'],
        ]);

        $published = $this->publishesDirectly($request, $validated['field']);

        $translation = $record->setTranslation($validated['field'], $validated['locale'], $validated['value'] ?? null, isReviewed: $published);

        if ($translation !== null && $published) {
            $translation->forceFill(['reviewed_by' => $this->trustUser($request)->getKey()])->save();
        }

        return response()->json(['data' => $this->payload($request, $record->load('translations'))]);
    }

    /** A suggestion from the English; the app shows it to edit, and saves nothing. */
    public function suggest(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);

        $validated = $request->validate([
            'field' => ['required', 'string', Rule::in($record->translatableFields())],
            'locale' => ['required', 'string', Rule::in($this->languages())],
        ]);

        $english = $record->getAttribute($validated['field']);

        abort_if(! is_string($english) || blank($english), 422, 'This field has no English text to translate yet.');

        try {
            $text = AutoTranslator::translate($english, $validated['locale']);
        } catch (AutoTranslateFailed $e) {
            abort(503, $e->getMessage());
        }

        return response()->json(['data' => ['value' => $text]]);
    }

    /** @return array<string, mixed> */
    protected function payload(Request $request, Temple $temple): array
    {
        $languages = $this->languages();

        return [
            'auto_translate' => AutoTranslator::enabled(),
            'languages' => collect($languages)->map(fn (string $code): array => [
                'code' => $code,
                'name' => config("locales.supported.{$code}.name"),
                'native' => config("locales.supported.{$code}.native"),
            ])->values(),
            'fields' => collect($temple->translatableFields())
                ->filter(fn (string $field): bool => filled($temple->getAttribute($field)))
                ->map(fn (string $field): array => [
                    'field' => $field,
                    'english' => (string) $temple->getAttribute($field),
                    'publishes_directly' => $this->publishesDirectly($request, $field),
                    'translations' => (object) $temple->translations
                        ->where('field', $field)
                        ->filter(fn (Translation $t): bool => in_array($t->locale, $languages, true) && $t->isUsable())
                        ->mapWithKeys(fn (Translation $t): array => [$t->locale => [
                            'value' => $t->value,
                            'is_reviewed' => $t->is_reviewed,
                        ]])
                        ->all(),
                ])
                ->values(),
        ];
    }

    protected function publishesDirectly(Request $request, string $field): bool
    {
        return $this->trustUser($request)->isSuperAdmin() || in_array($field, Temple::TEAM_EDITABLE, true);
    }

    /** @return array<int, string> The apps' languages but English. */
    protected function languages(): array
    {
        return Locales::appTranslations();
    }
}
