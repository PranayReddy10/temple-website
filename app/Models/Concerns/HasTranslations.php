<?php

namespace App\Models\Concerns;

use App\Models\Translation;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Translated values for a model's text fields.
 *
 * The contract is that a caller always gets something readable. A missing
 * translation falls back to the base English column rather than returning
 * null, because a temple listing that renders half-blank in Telugu is worse
 * for a Telugu reader than one that renders in English — it looks broken
 * rather than untranslated.
 *
 * A model says which fields are translatable by declaring
 * `protected array $translatable = ['name', 'short_description'];`
 * Anything not in that list cannot be translated by accident, which matters
 * because a translated slug or a translated latitude is a bug.
 */
trait HasTranslations
{
    public function translations(): MorphMany
    {
        return $this->morphMany(Translation::class, 'translatable');
    }

    /** @return array<int, string> */
    public function translatableFields(): array
    {
        return property_exists($this, 'translatable') ? $this->translatable : [];
    }

    public function isTranslatable(string $field): bool
    {
        return in_array($field, $this->translatableFields(), true);
    }

    /**
     * The value of a field in a language, falling back to the base column.
     *
     * $reviewedOnly is what the public API passes: an unreviewed bulk import
     * of a deity's name is exactly the kind of thing that should not reach a
     * devotee before someone has read it.
     */
    public function translate(string $field, ?string $locale = null, bool $reviewedOnly = false): mixed
    {
        $base = $this->getAttribute($field);

        $locale = Locales::resolve($locale);

        if ($locale === Locales::fallback() || ! $this->isTranslatable($field)) {
            return $base;
        }

        $translation = $this->translations
            ->first(fn (Translation $t): bool => $t->locale === $locale
                && $t->field === $field
                && $t->isUsable()
                && (! $reviewedOnly || $t->is_reviewed));

        return $translation?->value ?? $base;
    }

    /**
     * Write a translation, creating or updating the single row for it.
     *
     * A blank value deletes the row rather than storing an empty string, so
     * "no translation" has exactly one representation and the coverage
     * figures cannot be inflated by rows nobody filled in.
     */
    public function setTranslation(string $field, string $locale, ?string $value, bool $isReviewed = false): ?Translation
    {
        if (! $this->isTranslatable($field)) {
            throw new \InvalidArgumentException(
                static::class." has no translatable field [{$field}].",
            );
        }

        Locales::assertSupported($locale);

        $keys = ['locale' => $locale, 'field' => $field];

        if (blank($value)) {
            $this->translations()->where($keys)->delete();
            $this->unsetRelation('translations');

            return null;
        }

        $translation = $this->translations()->updateOrCreate($keys, [
            'value' => $value,
            'is_reviewed' => $isReviewed,
        ]);

        $this->unsetRelation('translations');

        return $translation;
    }

    /**
     * How much of this record exists in a language, as a fraction of its
     * translatable fields that actually hold a base value.
     *
     * Counting against fields that are themselves empty would report a
     * sparsely filled temple as well translated, which is the opposite of
     * what the coverage screen is for.
     */
    public function translationCoverage(string $locale): float
    {
        $fields = array_filter(
            $this->translatableFields(),
            fn (string $field): bool => filled($this->getAttribute($field)),
        );

        if ($fields === []) {
            return 0.0;
        }

        if ($locale === Locales::fallback()) {
            return 1.0;
        }

        $done = $this->translations
            ->filter(fn (Translation $t): bool => $t->locale === $locale
                && in_array($t->field, $fields, true)
                && $t->isUsable())
            ->count();

        return min(1.0, $done / count($fields));
    }
}
