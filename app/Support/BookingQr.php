<?php

namespace App\Support;

use App\Models\PujaBooking;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * The code on a seva booking, shown in the app for the temple counter to scan.
 *
 * It carries the booking's random code, never its id or reference: the
 * reference is meant to be read aloud, and a code that could be typed from
 * a reference would let anyone "scan" a booking they had only heard about.
 * Like the other codes here it is a plain https link, so a phone camera
 * without the panel opens a page that says what the booking is.
 */
final class BookingQr
{
    private const TOKEN = '/^[A-Za-z0-9]{20,40}$/';

    public static function url(PujaBooking $booking): string
    {
        return rtrim((string) config('brand.url'), '/').'/bookings/'.$booking->code;
    }

    /** The token in a scanned code: the URL, `templepassport://booking/<code>`, or the bare code. */
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

        $at = array_search('bookings', $segments, true);
        if ($at === false) {
            $at = array_search('booking', $segments, true);
        }

        if ($at === false || ! isset($segments[$at + 1])) {
            return null;
        }

        $token = $segments[$at + 1];

        return preg_match(self::TOKEN, $token) === 1 ? $token : null;
    }

    public static function svg(PujaBooking $booking): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'svgAddXmlHeader' => false,
        ]);

        return (new QRCode($options))->render(self::url($booking));
    }
}
