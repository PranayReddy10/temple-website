<?php

namespace App\Support\Translation;

use App\Models\Setting;
use App\Support\Locales;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A machine translation to start from, never to publish as it is.
 *
 * Two providers, chosen in Admin → Settings → Translation:
 *  - MyMemory (the default): free and needs no key. About 5,000 characters a
 *    day without an email, 50,000 with one. It takes at most 500 bytes per
 *    request, so longer text is sent in pieces cut at sentence ends.
 *  - Google Cloud Translation: needs an API key; the first 500,000
 *    characters a month are free.
 *
 * Callers save what comes back as unreviewed (or put it in a form for a
 * person to read), because a machine's Telugu for a deity's name is exactly
 * what should not reach a devotee unread. Results are cached for a month,
 * so translating the same dress code again costs no quota.
 */
final class AutoTranslator
{
    /** Longest text one call will translate. */
    public const MAX_CHARS = 5000;

    /** MyMemory refuses a query over 500 bytes; Telugu or Hindi never goes in, English does. */
    protected const MYMEMORY_PIECE = 450;

    public static function enabled(): bool
    {
        return (bool) setting('auto_translate_enabled', null, true);
    }

    public static function provider(): string
    {
        return setting('auto_translate_provider') === 'google' && filled(Setting::secret('google_translate_api_key'))
            ? 'google'
            : 'mymemory';
    }

    /**
     * @throws AutoTranslateFailed
     */
    public static function translate(string $text, string $to, string $from = 'en'): string
    {
        if (! self::enabled()) {
            throw new AutoTranslateFailed('Auto-translate is switched off.');
        }

        if (! Locales::isSupported($to) || ! Locales::isSupported($from)) {
            throw new AutoTranslateFailed('That language is not supported.');
        }

        $text = trim($text);

        if ($text === '' || $to === $from) {
            return $text;
        }

        if (mb_strlen($text) > self::MAX_CHARS) {
            throw new AutoTranslateFailed('This text is too long to auto-translate. Translate it in parts.');
        }

        $provider = self::provider();
        $key = 'auto-translate:'.$provider.':'.$from.':'.$to.':'.sha1($text);

        if (is_string($cached = Cache::get($key))) {
            return $cached;
        }

        $result = $provider === 'google'
            ? self::google($text, $to, $from)
            : self::myMemory($text, $to, $from);

        Cache::put($key, $result, now()->addDays(30));

        return $result;
    }

    protected static function google(string $text, string $to, string $from): string
    {
        try {
            $response = Http::timeout(15)->asForm()->post('https://translation.googleapis.com/language/translate/v2', [
                'key' => Setting::secret('google_translate_api_key'),
                'q' => $text,
                'source' => $from,
                'target' => $to,
                'format' => 'text',
            ]);
        } catch (ConnectionException) {
            throw new AutoTranslateFailed('The translation service could not be reached. Please try again.');
        }

        $translated = $response->json('data.translations.0.translatedText');

        if (! $response->successful() || ! is_string($translated) || $translated === '') {
            Log::warning('Google translation failed', ['status' => $response->status(), 'error' => $response->json('error.message')]);

            throw new AutoTranslateFailed('The translation service did not answer. Please try again later.');
        }

        return $translated;
    }

    protected static function myMemory(string $text, string $to, string $from): string
    {
        $out = [];

        foreach (self::pieces($text) as $piece) {
            if (trim($piece) === '') {
                $out[] = $piece;

                continue;
            }

            try {
                $response = Http::timeout(15)->get('https://api.mymemory.translated.net/get', array_filter([
                    'q' => $piece,
                    'langpair' => $from.'|'.$to,
                    // An email lifts the free allowance from 5,000 to 50,000 characters a day.
                    'de' => setting('auto_translate_email'),
                ]));
            } catch (ConnectionException) {
                throw new AutoTranslateFailed('The translation service could not be reached. Please try again.');
            }

            $translated = $response->json('responseData.translatedText');
            $status = (int) $response->json('responseStatus');

            if ($response->json('quotaFinished') === true || $status === 429) {
                throw new AutoTranslateFailed('Today\'s free translations are used up. Please try again tomorrow, or type the translation.');
            }

            if (! $response->successful() || $status !== 200 || ! is_string($translated) || $translated === '') {
                Log::warning('MyMemory translation failed', ['status' => $response->status(), 'detail' => $response->json('responseDetails')]);

                throw new AutoTranslateFailed('The translation service did not answer. Please try again later.');
            }

            $out[] = $translated;
        }

        return trim(implode('', $out));
    }

    /**
     * The text cut into pieces MyMemory accepts, keeping line breaks where
     * they were: lines first, then sentences, then words.
     *
     * @return array<int, string>
     */
    public static function pieces(string $text, int $max = self::MYMEMORY_PIECE): array
    {
        $pieces = [];

        foreach (preg_split('/(\R)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $line) {
            if (mb_strlen($line) <= $max) {
                $pieces[] = $line;

                continue;
            }

            $current = '';

            foreach (preg_split('/(?<=[.!?;])\s+|\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                // Joined at sentence ends where possible: the split above
                // prefers them, and a piece closes once it would be too long.
                $candidate = $current === '' ? $word : $current.' '.$word;

                if (mb_strlen($candidate) > $max && $current !== '') {
                    $pieces[] = $current;
                    $pieces[] = ' ';
                    $current = $word;
                } else {
                    $current = $candidate;
                }
            }

            if ($current !== '') {
                $pieces[] = $current;
            }
        }

        return $pieces;
    }
}
