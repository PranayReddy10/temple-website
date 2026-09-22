<?php

namespace App\Support;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Which language a request is in, decided in one place.
 *
 * Every caller that guesses for itself is a caller that will accept
 * `?lang=../../etc/passwd` or fall back differently from the next one. The
 * rules here are: an explicit query parameter wins, then the signed-in
 * devotee's stored preference, then Accept-Language, then English.
 */
final class Locales
{
    public static function fallback(): string
    {
        return (string) config('locales.fallback', 'en');
    }

    /** @return array<string, array{name: string, native: string, rtl: bool}> */
    public static function supported(): array
    {
        return config('locales.supported', []);
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, self::supported());
    }

    /** Anything unrecognised becomes the fallback rather than an error. */
    public static function resolve(?string $locale): string
    {
        return self::isSupported($locale) ? $locale : self::fallback();
    }

    public static function assertSupported(string $locale): void
    {
        if (! self::isSupported($locale)) {
            throw new InvalidArgumentException("Unsupported locale [{$locale}].");
        }
    }

    /**
     * @return array<string, string> code => native name, for a picker
     */
    public static function options(bool $launchOnly = false): array
    {
        $codes = $launchOnly
            ? (array) config('locales.launch', [self::fallback()])
            : array_keys(self::supported());

        $options = [];

        foreach ($codes as $code) {
            if (! self::isSupported($code)) {
                continue;
            }

            $language = self::supported()[$code];

            // English alongside the native name: a staff translator picking a
            // target language may not read the script they are working into.
            $options[$code] = $language['native'] === $language['name']
                ? $language['name']
                : $language['native'].' — '.$language['name'];
        }

        return $options;
    }

    /** Languages the app ships with today, as opposed to what the schema holds. */
    public static function launch(): array
    {
        return array_values(array_filter(
            (array) config('locales.launch', []),
            self::isSupported(...),
        ));
    }

    /**
     * The language for an API request.
     *
     * Accept-Language is parsed for its highest-quality supported tag rather
     * than trusting the first entry: a device sending `ta;q=0.9, en;q=1.0`
     * prefers English, and a naive `explode(',')` would serve Tamil.
     */
    public static function forRequest(Request $request): string
    {
        $explicit = $request->query('lang') ?? $request->query('locale');

        if (is_string($explicit) && self::isSupported($explicit)) {
            return $explicit;
        }

        $devotee = $request->user();

        if ($devotee !== null && self::isSupported($devotee->locale ?? null)) {
            return $devotee->locale;
        }

        return self::fromAcceptLanguage($request->header('Accept-Language'));
    }

    public static function fromAcceptLanguage(?string $header): string
    {
        if (blank($header)) {
            return self::fallback();
        }

        $best = null;
        $bestQuality = -1.0;

        foreach (explode(',', $header) as $part) {
            $segments = explode(';', trim($part));
            // "te-IN" is Telugu; the region is not a different language here.
            $tag = strtolower(trim(explode('-', trim($segments[0]))[0]));

            if (! self::isSupported($tag)) {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($segments, 1) as $parameter) {
                if (str_starts_with(trim($parameter), 'q=')) {
                    $quality = (float) substr(trim($parameter), 2);
                }
            }

            if ($quality > $bestQuality) {
                $best = $tag;
                $bestQuality = $quality;
            }
        }

        return $best ?? self::fallback();
    }
}
