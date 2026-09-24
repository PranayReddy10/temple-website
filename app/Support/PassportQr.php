<?php

namespace App\Support;

use App\Models\Devotee;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * The code on a devotee's own passport.
 *
 * Shown in the app for another devotee, a temple counter or staff to scan.
 * It carries a random token, never the account id: the token is what the
 * holder chose to show, and resetting it retires every copy already out
 * there. Like a temple's code it is a plain https link, so a phone camera
 * without the app still opens something that makes sense.
 */
final class PassportQr
{
    /** Tokens are 20 characters; the pattern leaves room to lengthen them. */
    private const TOKEN = '/^[A-Za-z0-9]{16,32}$/';

    public static function url(Devotee $devotee): string
    {
        return rtrim((string) config('brand.url'), '/').'/passport/'.$devotee->passportCode();
    }

    /**
     * The token in a scanned code, whichever form it arrived in: the URL,
     * the app's own `templepassport://passport/<code>`, or the bare token.
     */
    public static function parse(string $code): ?string
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        if (preg_match(self::TOKEN, $code) === 1) {
            return $code;
        }

        $parts = parse_url($code);

        if ($parts === false || ! isset($parts['scheme'])) {
            return null;
        }

        if ($parts['scheme'] === 'templepassport') {
            $segments = array_values(array_filter([$parts['host'] ?? '', ...explode('/', $parts['path'] ?? '')]));
        } else {
            $segments = array_values(array_filter(explode('/', $parts['path'] ?? '')));
        }

        $at = array_search('passport', $segments, true);

        if ($at === false || ! isset($segments[$at + 1])) {
            return null;
        }

        $token = $segments[$at + 1];

        return preg_match(self::TOKEN, $token) === 1 ? $token : null;
    }

    public static function svg(Devotee $devotee): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'svgAddXmlHeader' => false,
        ]);

        return (new QRCode($options))->render(self::url($devotee));
    }
}
