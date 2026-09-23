<?php

namespace App\Support;

use App\Models\Temple;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use RuntimeException;

/**
 * The check-in code a temple displays at its gate.
 *
 * A code is a URL carrying the temple's slug and a signature made with the
 * application key, so a code printed by anyone else — or one for a different
 * temple — does not verify. It is a plain https link on purpose: a phone
 * camera that knows nothing of the app opens a page saying whether the code
 * is genuine, and older builds of the app, which read the slug from any
 * /temples/<slug> URL, keep working.
 */
final class TempleQr
{
    public static function url(Temple $temple): string
    {
        return rtrim((string) config('brand.url'), '/').'/temples/'.$temple->slug.'/checkin?s='.self::signature($temple);
    }

    public static function signature(Temple $temple): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set, so temple codes cannot be signed.');
        }

        $mac = hash_hmac('sha256', 'temple-qr|v1|'.$temple->getKey().'|'.$temple->slug, $key, true);

        // 96 bits is far beyond guessing, and keeps the code small enough to
        // scan from across a gateway.
        return rtrim(strtr(base64_encode(substr($mac, 0, 12)), '+/', '-_'), '=');
    }

    /**
     * Whether a scanned code is one of ours, and for which temple.
     *
     * @return array{valid: bool, temple: ?Temple, reason: string}
     */
    public static function verify(?string $code): array
    {
        [$slug, $signature] = self::parse((string) $code);

        if ($slug === null) {
            return ['valid' => false, 'temple' => null, 'reason' => 'This is not a temple check-in code.'];
        }

        $temple = Temple::query()->where('slug', $slug)->first();

        if ($temple === null) {
            return ['valid' => false, 'temple' => null, 'reason' => 'No temple matches this code.'];
        }

        if ($signature === null || ! hash_equals(self::signature($temple), $signature)) {
            return ['valid' => false, 'temple' => $temple, 'reason' => 'This code names '.$temple->name.' but was not issued by us.'];
        }

        return ['valid' => true, 'temple' => $temple, 'reason' => 'Genuine check-in code for '.$temple->name.'.'];
    }

    /**
     * The slug and signature in a code, whichever form it arrived in.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function parse(string $code): array
    {
        $code = trim($code);
        $parts = parse_url($code);

        if ($parts === false || ! isset($parts['scheme'])) {
            return [null, null];
        }

        parse_str($parts['query'] ?? '', $query);
        $signature = is_string($query['s'] ?? null) ? $query['s'] : null;

        if ($parts['scheme'] === 'templepassport' && ($parts['host'] ?? null) === 'checkin') {
            $slug = trim($parts['path'] ?? '', '/');

            return [$slug !== '' ? $slug : null, $signature];
        }

        $segments = array_values(array_filter(explode('/', $parts['path'] ?? '')));
        $at = array_search('temples', $segments, true);

        if ($at === false || ! isset($segments[$at + 1])) {
            return [null, null];
        }

        return [$segments[$at + 1], $signature];
    }

    public static function svg(Temple $temple): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'svgAddXmlHeader' => false,
        ]);

        return (new QRCode($options))->render(self::url($temple));
    }
}
