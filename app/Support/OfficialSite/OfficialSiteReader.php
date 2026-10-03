<?php

namespace App\Support\OfficialSite;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a temple's own website for the facts a devotee needs: phone,
 * email, address and PIN code, where it is on the map, darshan timings,
 * sevas with their fees, its own short description and main image.
 *
 * It only suggests. Everything it finds goes to a staff member to check
 * and tick before anything on the listing changes, and facts are kept with
 * the site as their source. A temple's own wording and photos are its
 * copyright, so those are offered last, off by default.
 *
 * The home page is read, then up to four of its own pages whose link says
 * timings, darshan, seva, pooja, contact or about.
 */
final class OfficialSiteReader
{
    public const MAX_PAGES = 5;

    private const LINK_HINTS = '/timing|darshan|darsan|seva|sevas|pooja|puja|arjitha|contact|about|festival|schedule|booking/i';

    /** @return array<string, mixed> what was found, or ['error' => …] */
    public static function read(string $url): array
    {
        $url = self::normaliseUrl($url);
        if ($url === null) {
            return ['error' => 'That is not a web address.'];
        }

        $home = self::fetch($url);
        if ($home === null) {
            return ['error' => 'Could not open '.$url.'. Check the address, or try again later.'];
        }

        $pages = [$url => $home];
        foreach (self::likelyPages($home, $url) as $link) {
            if (count($pages) >= self::MAX_PAGES) {
                break;
            }
            if (! isset($pages[$link]) && ($html = self::fetch($link)) !== null) {
                $pages[$link] = $html;
            }
        }

        return self::extract($pages) + ['source_url' => $url, 'pages' => array_keys($pages), 'read_at' => now()->toIso8601String()];
    }

    /**
     * The facts in a set of pages (url => html). Pure, for testing.
     *
     * @param  array<string, string>  $pages
     * @return array<string, mixed>
     */
    public static function extract(array $pages): array
    {
        $found = ['phones' => [], 'emails' => [], 'timings' => [], 'sevas' => []];

        foreach ($pages as $pageUrl => $html) {
            [$xpath, $lines] = self::parse($html);

            // Structured data first: a site that publishes it means it.
            foreach (self::jsonLd($xpath) as $node) {
                $found['name'] ??= self::str($node['name'] ?? null);
                $found['description'] ??= self::str($node['description'] ?? null);
                foreach ((array) ($node['telephone'] ?? []) as $t) {
                    $found['phones'][] = self::str($t);
                }
                if ($e = self::str($node['email'] ?? null)) {
                    $found['emails'][] = $e;
                }
                $address = $node['address'] ?? null;
                if (is_array($address)) {
                    $found['address'] ??= self::str(implode(', ', array_filter([
                        $address['streetAddress'] ?? null, $address['addressLocality'] ?? null, $address['addressRegion'] ?? null,
                    ], 'is_string'))) ?: null;
                    $found['pincode'] ??= self::pincodeIn((string) ($address['postalCode'] ?? ''));
                } elseif (is_string($address)) {
                    $found['address'] ??= self::str($address);
                }
                $geo = $node['geo'] ?? null;
                if (is_array($geo) && is_numeric($geo['latitude'] ?? null) && is_numeric($geo['longitude'] ?? null)) {
                    $found['latitude'] ??= (float) $geo['latitude'];
                    $found['longitude'] ??= (float) $geo['longitude'];
                }
            }

            $found['description'] ??= self::meta($xpath, 'description') ?? self::meta($xpath, 'og:description');
            $found['name'] ??= self::meta($xpath, 'og:site_name') ?? self::str($xpath->evaluate('string(//title)'));
            if (! isset($found['image']) && ($img = self::meta($xpath, 'og:image'))) {
                $found['image'] = self::absolute($img, $pageUrl);
            }

            foreach ($xpath->query('//a[@href]') as $a) {
                $href = trim((string) $a->getAttribute('href'));
                if (str_starts_with(strtolower($href), 'tel:')) {
                    $found['phones'][] = rawurldecode(substr($href, 4));
                } elseif (str_starts_with(strtolower($href), 'mailto:')) {
                    $found['emails'][] = strtok(rawurldecode(substr($href, 7)), '?');
                } elseif (! isset($found['latitude']) && preg_match('#(?:maps|goo\.gl)#i', $href) && preg_match('#[@=/](-?\d{1,2}\.\d{3,}),\s*(-?\d{1,3}\.\d{3,})#', rawurldecode($href), $m)) {
                    $found['latitude'] = (float) $m[1];
                    $found['longitude'] = (float) $m[2];
                }
            }
            foreach ($xpath->query('//iframe[@src]') as $f) {
                if (! isset($found['latitude']) && preg_match('#maps.*?[@=!](-?\d{1,2}\.\d{3,})[,!].*?(-?\d{2,3}\.\d{3,})#', rawurldecode($f->getAttribute('src')), $m)) {
                    // Embedded Google Maps: !3d<lat>!2d<lng> or q=lat,lng.
                    $found['latitude'] = (float) $m[1];
                    $found['longitude'] = (float) $m[2];
                }
            }

            foreach ($lines as $i => $line) {
                foreach (self::phonesIn($line) as $p) {
                    $found['phones'][] = $p;
                }
                if (preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $line, $m)) {
                    array_push($found['emails'], ...$m[0]);
                }
                if (! isset($found['pincode']) && ($pin = self::pincodeIn($line)) && preg_match('/,|pin|dist|mandal|village|road|state|india/i', $line)) {
                    $found['pincode'] = $pin;
                    $found['address'] ??= Str::limit(self::clean($line), 250, '');
                }
                foreach (self::timingsIn($line, $lines[$i - 1] ?? null) as $t) {
                    $found['timings'][] = $t + ['source' => $pageUrl];
                }
                if ($seva = self::sevaIn($line, $lines[$i - 1] ?? null)) {
                    $found['sevas'][] = $seva + ['source' => $pageUrl];
                }
            }
        }

        $found['phones'] = self::unique(array_map(fn ($p) => self::clean((string) $p), $found['phones']), fn ($p) => substr(preg_replace('/\D/', '', $p), -10));
        $found['phones'] = array_values(array_filter($found['phones'], fn ($p) => strlen(preg_replace('/\D/', '', $p)) >= 8));
        $found['emails'] = self::unique(array_map(fn ($e) => strtolower(trim((string) $e)), $found['emails']), fn ($e) => $e);
        $found['emails'] = array_values(array_filter($found['emails'], fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && ! preg_match('/\.(png|jpe?g|gif|webp)$/', $e)));
        $found['timings'] = array_slice(self::unique($found['timings'], fn ($t) => strtolower($t['label']).$t['opens_at'].$t['closes_at']), 0, 20);
        $found['sevas'] = array_slice(self::unique($found['sevas'], fn ($s) => strtolower($s['name'])), 0, 40);
        $found['description'] = isset($found['description']) ? Str::limit(self::clean($found['description']), 500, '') : null;
        if (isset($found['address'])) {
            $found['address'] = trim(preg_replace('/^(temple\s+)?(address|location|reach us)\s*[:\-]\s*/i', '', $found['address']));
        }
        if (isset($found['name'])) {
            // "Temple Name | Official Website" from a page title.
            $found['name'] = trim(preg_split('/\s+[|–—-]\s+/u', $found['name'])[0]);
        }

        return array_filter($found, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** "6:00 AM to 12:30 PM" and similar, with the words before it as the label. */
    public static function timingsIn(string $line, ?string $previous = null): array
    {
        $time = '(\d{1,2})(?:[:.](\d{2}))?\s*(a\.?\s?m\.?|p\.?\s?m\.?|hrs|hours)?';
        if (! preg_match_all('/'.$time.'\s*(?:to|till|until|-|–|—)\s*'.$time.'/iu', $line, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $out = [];
        foreach ($all as $m) {
            // Groups that did not match are left out of the result at the end.
            $g = fn (int $i): string => (string) ($m[$i][0] ?? '');
            // Bare numbers ("2018-2023", "10-12 buses") are not times.
            if ($g(2) === '' && $g(5) === '' && $g(3) === '' && $g(6) === '') {
                continue;
            }
            $opens = self::hhmm($g(1), $g(2), $g(3), $g(6));
            $closes = self::hhmm($g(4), $g(5), $g(6), '', $opens);
            if ($opens === null || $closes === null) {
                continue;
            }
            $label = self::clean(trim(substr($line, 0, $m[0][1]), " \t:-–—|•*"));
            if ($label === '' || preg_match('/^\d/', $label)) {
                $label = self::clean((string) $previous);
            }
            $label = Str::limit(preg_replace('/\b(timings?|time)\b\s*:?$/i', '', $label) ?: 'Temple timings', 60, '');
            $out[] = ['label' => $label !== '' ? $label : 'Temple timings', 'opens_at' => $opens, 'closes_at' => $closes, 'kind' => self::kindFor($label)];
        }

        return $out;
    }

    /** "Abhishekam ₹ 500", "Archana - Rs.50/-", "Kalyanam INR 1,116". */
    public static function sevaIn(string $line, ?string $previous = null): ?array
    {
        if (mb_strlen($line) > 140 || ! preg_match('/(?:₹|rs\.?|inr)\s*([\d,]{1,7})(?:\.\d{1,2})?|([\d,]{2,7})\s*(?:\/-|rupees)/iu', $line, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $amount = (int) str_replace(',', '', ($m[1][0] ?? '') !== '' ? $m[1][0] : ($m[2][0] ?? '0'));
        $name = self::clean(trim(substr($line, 0, $m[0][1]), " \t:-–—|•*.("));
        $name = preg_replace('/^\d+[\).\s]+/', '', $name);
        if ($name === '' || mb_strlen($name) < 3) {
            $name = self::clean((string) $previous);
        }
        if ($amount < 1 || $amount > 1000000 || $name === '' || mb_strlen($name) > 80 || preg_match('/total|donat|hundi|account|ifsc|gst|bank/i', $name)) {
            return null;
        }

        return ['name' => $name, 'fee' => $amount];
    }

    // --- Helpers ---

    private static function fetch(string $url): ?string
    {
        try {
            $response = Http::timeout(15)->withHeaders(['User-Agent' => config('brand.name').' temple directory (+'.config('brand.website').')', 'Accept' => 'text/html'])->get($url);
            if (! $response->successful() || ! str_contains(strtolower((string) $response->header('Content-Type')), 'html')) {
                return null;
            }

            return mb_substr((string) $response->body(), 0, 2_000_000);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, string> this site's own pages that look like timings, sevas or contact */
    private static function likelyPages(string $html, string $base): array
    {
        [$xpath] = self::parse($html);
        $host = parse_url($base, PHP_URL_HOST);
        $links = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            $href = self::absolute(trim($a->getAttribute('href')), $base);
            if ($href === null || parse_url($href, PHP_URL_HOST) !== $host || preg_match('/\.(pdf|jpe?g|png|gif|zip|docx?)$/i', $href)) {
                continue;
            }
            if (preg_match(self::LINK_HINTS, $href.' '.$a->textContent)) {
                $links[] = strtok($href, '#');
            }
        }

        return array_values(array_unique($links));
    }

    /** @return array{0: DOMXPath, 1: array<int, string>} */
    private static function parse(string $html): array
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        $lines = [];
        foreach ($xpath->query('//body//*[self::p or self::li or self::td or self::th or self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::span or self::div or self::tr or self::dt or self::dd][not(self::script)][not(self::style)]') as $node) {
            // Only the innermost text-bearing blocks, so a line is not
            // repeated by every element that contains it.
            $hasBlockChild = $xpath->query('./*[self::p or self::li or self::td or self::div or self::tr or self::table or self::ul]', $node)->length > 0;
            if ($hasBlockChild && ! in_array($node->nodeName, ['tr', 'li'], true)) {
                continue;
            }
            $text = self::clean($node->textContent);
            if ($text !== '' && mb_strlen($text) <= 400) {
                $lines[] = $text;
            }
        }

        return [$xpath, array_values(array_unique($lines))];
    }

    /** @return array<int, array<string, mixed>> */
    private static function jsonLd(DOMXPath $xpath): array
    {
        $nodes = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $s) {
            $data = json_decode(trim($s->textContent), true);
            if (! is_array($data)) {
                continue;
            }
            foreach (isset($data['@graph']) ? $data['@graph'] : (array_is_list($data) ? $data : [$data]) as $node) {
                $type = implode(' ', (array) ($node['@type'] ?? []));
                if (preg_match('/Temple|Place|Worship|Organization|LocalBusiness|TouristAttraction/i', $type)) {
                    $nodes[] = $node;
                }
            }
        }

        return $nodes;
    }

    private static function meta(DOMXPath $xpath, string $name): ?string
    {
        $v = $xpath->evaluate('string(//meta[@name="'.$name.'" or @property="'.$name.'"]/@content)');

        return self::str($v);
    }

    /** @return array<int, string> */
    private static function phonesIn(string $line): array
    {
        if (! preg_match('/phone|ph\b|ph\.|mobile|mob|cell|contact|call|tel|whatsapp|office|enquir/i', $line)) {
            return [];
        }
        preg_match_all('/(?:\+?91[\s-]?)?(?:0\d{2,4}[\s-]?\d{6,8}|[6-9]\d{4}[\s-]?\d{5})/', $line, $m);

        return $m[0];
    }

    private static function pincodeIn(string $text): ?string
    {
        return preg_match('/(?<!\d)([1-9]\d{2})\s?(\d{3})(?!\d)/', $text, $m) ? $m[1].$m[2] : null;
    }

    private static function hhmm(string $h, string $m, string $ampm, string $otherAmpm, ?string $after = null): ?string
    {
        $hour = (int) $h;
        $min = $m === '' ? 0 : (int) $m;
        if ($hour > 24 || $min > 59) {
            return null;
        }
        $marker = strtolower(str_replace(['.', ' '], '', $ampm ?: $otherAmpm));
        if ($marker === 'pm' && $hour < 12) {
            $hour += 12;
        } elseif ($marker === 'am' && $hour === 12) {
            $hour = 0;
        } elseif ($marker === '' && $after !== null && $hour * 60 + $min <= (int) substr($after, 0, 2) * 60 + (int) substr($after, 3, 2) && $hour < 12) {
            // "6 – 9" after a morning opening reads as evening.
            $hour += 12;
        }
        if ($hour === 24) {
            $hour = 0;
        }

        return sprintf('%02d:%02d', $hour, $min);
    }

    private static function kindFor(string $label): string
    {
        return match (true) {
            (bool) preg_match('/aarti|arati|harathi|haarathi/i', $label) => 'aarti',
            (bool) preg_match('/darshan|darsan|sarva|dharma/i', $label) => 'darshan',
            (bool) preg_match('/special|vip|seva|abhishek|kalyan/i', $label) => 'special',
            default => 'general',
        };
    }

    public static function normaliseUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    }

    private static function absolute(?string $href, string $base): ?string
    {
        if ($href === null || $href === '' || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $p = parse_url($base);
        $root = ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '');
        if (str_starts_with($href, '//')) {
            return ($p['scheme'] ?? 'https').':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $root.$href;
        }
        $dir = rtrim(preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/'), '/');

        return $root.$dir.'/'.$href;
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function str(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = self::clean($v);

        return $v === '' ? null : $v;
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return array<int, T>
     */
    private static function unique(array $items, callable $key): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $k = $key($item);
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $item;
        }

        return $out;
    }
}
