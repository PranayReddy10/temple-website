<?php

namespace App\Support;

use App\Models\Temple;
use App\Models\Translation;

/**
 * The website's temple pages in the apps' other languages.
 *
 * A devotee who types a temple's name in Telugu or Tamil script finds few
 * pages written that way, so a page in their language has a real chance of
 * being the answer. Each language lives at its own address (/te/temples/…),
 * linked to the others with hreflang, which is how Google is told they are
 * the same page in different languages rather than copies.
 *
 * A language version exists only once the temple's name has a reviewed
 * translation in it: without that it would be the English page again under
 * a second address, which search engines treat as duplicate content.
 */
final class SiteLocale
{
    /** @return array<int, string> te, hi, ta, kn */
    public static function languages(): array
    {
        return Locales::appTranslations();
    }

    /** @return array<int, string> the languages this temple's page is published in, besides English */
    public static function languagesOf(Temple $temple): array
    {
        $temple->loadMissing('translations');

        return array_values(array_filter(self::languages(), fn (string $code): bool => $temple->translations->contains(
            fn (Translation $t): bool => $t->field === 'name' && $t->locale === $code && $t->is_reviewed && $t->isUsable()
        )));
    }

    public static function templeUrl(Temple $temple, ?string $locale): string
    {
        return Seo::url(($locale !== null && $locale !== Locales::fallback() ? $locale.'/' : '').'temples/'.$temple->slug);
    }

    /**
     * hreflang links: every version of the page, English as the default.
     *
     * @param  array<int, string>  $languages
     * @return array<string, string> hreflang => URL
     */
    public static function alternates(Temple $temple, array $languages): array
    {
        if ($languages === []) {
            return [];
        }

        $out = ['en' => self::templeUrl($temple, null)];
        foreach ($languages as $code) {
            $out[$code] = self::templeUrl($temple, $code);
        }
        $out['x-default'] = $out['en'];

        return $out;
    }

    /** Sets the language the page's own words (headings, labels) are in. */
    public static function use(?string $locale): void
    {
        app()->setLocale($locale ?? Locales::fallback());
    }

    /** Native name of a language, for the language switcher. */
    public static function native(string $code): string
    {
        return (string) (config("locales.supported.{$code}.native") ?? $code);
    }
}
