<?php

namespace App\Support\OfficialSite;

use App\Support\Clock;
use App\Support\Http\CappedSink;
use App\Support\Http\PublicAddress;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a temple's own website for the facts a devotee needs: phone,
 * email, address and PIN code, where it is on the map, darshan and aarti
 * timings, sevas with their fees, its own short description and main image.
 *
 * It only suggests. Everything it finds goes to a staff member to check,
 * correct and tick before anything on the listing changes, and facts are
 * kept with the site as their source. A temple's own wording and photos are
 * its copyright, so those are offered last, off by default.
 *
 * Pages are read as records, not loose lines: a table row, or a name with
 * the time and price lines under it (a "card"), is one seva or one timing,
 * so "Abhishekam | 7:00 AM | ₹172" never comes out as three things.
 *
 * The page given is read first, then any pages staff listed, then the
 * site's own pages whose link or sitemap entry says timings, darshan, seva,
 * pooja, aarti, tickets, contact or about (two links deep).
 */
final class OfficialSiteReader
{
    /** Pages found by following links; pages staff list are read on top. */
    public const MAX_PAGES = 12;

    public const MAX_EXTRA_PAGES = 10;

    private const LINK_HINTS = '/timing|darshan|darsan|dharshan|seva|pooja|puja|arjith|arjit|ticket|tariff|price|schedule|hours|aart?i|arati|harathi|contact|about|festival|utsav|booking|visit|reach|సేవ|దర్శన|పూజ|సమయ|సంప్రదించ|सेवा|दर्शन|पूजा|समय|संपर्क/iu';

    /** Links worth reading before contact and about pages. */
    private const STRONG_HINTS = '/timing|darshan|darsan|dharshan|seva|pooja|puja|arjith|arjit|ticket|tariff|price|schedule|aart?i|arati|harathi|సేవ|దర్శన|పూజ|సమయ|सेवा|दर्शन|पूजा|समय/iu';

    /** One clock time: hour, separator, minutes, marker. */
    private const TIME = '(\d{1,2})(?:\s*([:.])\s*(\d{2}))?\s*(a\.?\s?m\b\.?|p\.?\s?m\b\.?|hrs\b\.?|hours\b|noon\b|midnight\b)?';

    private const PRICE = '/(?:₹|\brs\b\.?|\binr\b|\brupees\b)\s*:?\s*([\d,]+)(?:\.\d{1,2})?|(?<![\d:.])([\d,]{2,9})(?:\.\d{1,2})?\s*(?:\/-|₹|\brupees\b|\brs\b\.?|\binr\b)/iu';

    private const DAY = '(?:mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun)(?:day)?s?';

    private const NOT_A_SEVA = '/\btotal\b|donat|hundi|account|ifsc|gst|bank|\broom|cottage|accommodation|guest\s*house|parking|locker|\ba\/c\b|\bupi\b|salary|minimum|maximum|\bpin\b|phone|mobile/i';

    private const BLOCKS = ['p', 'div', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'article',
        'header', 'footer', 'main', 'aside', 'nav', 'blockquote', 'address', 'pre', 'figure', 'figcaption', 'form', 'hr',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'center', 'fieldset', 'details', 'summary'];

    private const SKIP = ['script', 'style', 'noscript', 'svg', 'template', 'iframe', 'select', 'option', 'button', 'head', 'canvas', 'video', 'audio', 'object'];

    /**
     * @param  array<int, string>  $extraPages  more pages of the site to read, as staff listed them
     * @return array<string, mixed> what was found, or ['error' => …]
     */
    public static function read(string $url, array $extraPages = []): array
    {
        $url = self::normaliseUrl($url);
        if ($url === null) {
            return ['error' => 'That is not a web address.'];
        }

        [$home, $reason, $url] = self::fetchHome($url);
        if ($home === null) {
            return ['error' => 'Could not open '.$url.' ('.$reason.'). Check the address, or try again later.'];
        }

        $pages = [$url => $home];
        $failed = [];
        foreach (array_slice(array_unique(array_filter(array_map(fn ($u) => self::normaliseUrl((string) $u), $extraPages))), 0, self::MAX_EXTRA_PAGES) as $extra) {
            if (isset($pages[$extra])) {
                continue;
            }
            [$html, $why] = self::fetch($extra);
            $html === null ? $failed[] = $extra.' ('.$why.')' : $pages[$extra] = $html;
        }

        // The site's own pages about timings and sevas, best first, two links deep.
        $queue = self::likelyPages($home, $url) + self::sitemapPages($url);
        $documents = self::documents($home, $url);
        foreach (array_slice($pages, 1, null, true) as $pageUrl => $html) {
            $queue += self::likelyPages($html, $pageUrl);
            $documents += self::documents($html, $pageUrl);
        }
        $crawled = 0;
        $deeper = [];
        while ($queue !== [] && $crawled < self::MAX_PAGES - 1) {
            arsort($queue);
            $next = array_key_first($queue);
            unset($queue[$next]);
            if (isset($pages[$next])) {
                continue;
            }
            [$html] = self::fetch($next);
            $crawled++;
            if ($html === null) {
                continue;
            }
            $pages[$next] = $html;
            $documents += self::documents($html, $next);
            if (! isset($deeper[$next])) {
                foreach (self::likelyPages($html, $next) as $link => $score) {
                    if (! isset($pages[$link]) && ! isset($queue[$link])) {
                        $queue[$link] = $score - 1;
                        $deeper[$link] = true;
                    }
                }
            }
        }

        $found = self::extract($pages);

        // What each page gave, so staff can see where to look for more.
        $found['pages'] = array_map(fn (string $page): array => [
            'url' => $page,
            'timings' => count(array_filter($found['timings'] ?? [], fn ($t) => ($t['source'] ?? null) === $page)),
            'sevas' => count(array_filter($found['sevas'] ?? [], fn ($s) => ($s['source'] ?? null) === $page)),
        ], array_keys($pages));
        if ($documents !== []) {
            $found['documents'] = array_slice(array_map(fn ($u, $t) => ['url' => $u, 'text' => $t], array_keys($documents), $documents), 0, 10);
        }
        if ($failed !== []) {
            $found['failed_pages'] = $failed;
        }
        if (mb_strlen(self::visibleText($home)) < 200 && preg_match('/<script/i', $home)) {
            $found['warning'] = 'This site builds its pages in the browser with JavaScript, so little of it could be read. Add the links of its seva and timings pages, or enter them by hand.';
        }

        return $found + ['source_url' => $url, 'read_at' => now()->toIso8601String()];
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
            [$xpath, $records] = self::parse($html);

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

            foreach ($records as $r) {
                $line = $r['text'];
                foreach (self::phonesIn($line) as $p) {
                    $found['phones'][] = $p;
                }
                if (preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $line, $m)) {
                    array_push($found['emails'], ...$m[0]);
                }
                if (! isset($found['pincode']) && mb_strlen($line) <= 300 && ($pin = self::pincodeIn($line)) && preg_match('/,|pin|dist|mandal|village|road|state|india|telangana|andhra|karnataka|tamil|kerala|maharashtra/i', $line)) {
                    $found['pincode'] = $pin;
                    $found['address'] ??= Str::limit(self::clean($line), 250, '');
                }
            }

            [$timings, $sevas] = self::schedule($records);
            foreach ($timings as $t) {
                $found['timings'][] = $t + ['source' => $pageUrl];
            }
            foreach ($sevas as $s) {
                $found['sevas'][] = $s + ['source' => $pageUrl];
            }
        }

        $found['phones'] = self::unique(array_map(fn ($p) => self::clean((string) $p), $found['phones']), fn ($p) => substr(preg_replace('/\D/', '', $p), -10));
        $found['phones'] = array_values(array_filter($found['phones'], fn ($p) => strlen(preg_replace('/\D/', '', $p)) >= 8));
        $found['emails'] = self::unique(array_map(fn ($e) => strtolower(trim((string) $e)), $found['emails']), fn ($e) => $e);
        $found['emails'] = array_values(array_filter($found['emails'], fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && ! preg_match('/\.(png|jpe?g|gif|webp)$/', $e)));
        $found['timings'] = array_slice(self::unique($found['timings'], fn ($t) => mb_strtolower($t['label'].'|'.($t['notes'] ?? '').'|'.implode(',', $t['days'] ?? [])).$t['opens_at'].$t['closes_at']), 0, 30);
        $found['sevas'] = array_slice(self::mergeSevas($found['sevas']), 0, 60);
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

    // --- Timings and sevas ---

    /**
     * Timings and sevas in a page's records, read in order so a heading
     * names the timings under it and a name owns the time and price lines
     * that follow it.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private static function schedule(array $records): array
    {
        $timings = [];
        $sevas = [];
        $heading = null;
        $card = null;
        $columns = [];

        $close = function () use (&$card, &$heading, &$timings, &$sevas): void {
            if ($card !== null && $card['parts'] !== []) {
                self::row([$card['name'], ...$card['parts']], [], $heading, $timings, $sevas);
            }
            $card = null;
        };

        foreach ($records as $r) {
            $text = $r['text'];

            if ($r['cells'] !== null) {
                $close();
                if ($r['header']) {
                    $columns[$r['table']] = self::columns($r['cells']);
                } else {
                    self::row($r['cells'], $columns[$r['table']] ?? [], $heading, $timings, $sevas);
                }

                continue;
            }

            $shape = self::shapeOf($text);

            if ($r['heading']) {
                $close();
                if ($shape === 'name') {
                    $heading = $text;
                    $card = ['name' => $text, 'parts' => []];

                    continue;
                }
            }

            if ($shape === 'name') {
                $close();
                $card = ['name' => $text, 'parts' => []];
            } elseif (in_array($shape, ['time', 'price'], true) && $card !== null) {
                $card['parts'][] = $text;
            } else {
                $close();
                self::line($text, $heading, $timings, $sevas);
            }
        }
        $close();

        return [$timings, $sevas];
    }

    /**
     * One record split into cells: a table row, or a card's name with its
     * time and price lines. With a price it is a seva; otherwise its times
     * are timings named by the row.
     *
     * @param  array<int, string>  $cells
     * @param  array<string, mixed>  $columns  what the table's header said each column holds
     */
    private static function row(array $cells, array $columns, ?string $heading, array &$timings, array &$sevas): void
    {
        $fee = null;
        $nameAt = $columns['name'] ?? null;
        foreach ($cells as $i => $cell) {
            if ($fee === null && ($columns['price'] ?? null) === $i && preg_match('/^\s*(?:₹|rs\.?)?\s*([\d,]+)(?:\.\d{1,2})?\s*(?:\/-)?\s*$/iu', $cell, $m)) {
                $fee = (int) str_replace(',', '', $m[1]);
            } elseif ($fee === null && ($price = self::priceIn($cell)) !== null) {
                $fee = $price;
            }
            if ($nameAt === null && self::shapeOf($cell) === 'name' && ! self::isDaysOnly($cell) && ! preg_match('/^\s*(s\.?\s*no|sl\.?\s*no)/i', $cell)) {
                $nameAt = $i;
            }
        }
        $name = $nameAt !== null ? ($cells[$nameAt] ?? '') : '';

        if ($fee !== null) {
            $times = [];
            $notes = [];
            foreach ($cells as $i => $cell) {
                if ($i === $nameAt) {
                    continue;
                }
                $times = [...$times, ...self::timesIn($cell)];
                if (self::isDaysOnly($cell)) {
                    $notes[] = self::clean($cell);
                }
            }
            if ($seva = self::seva($name, $fee, $times, $notes, implode(' ', $cells))) {
                $sevas[] = $seva;
            }

            return;
        }

        foreach ($cells as $i => $cell) {
            if ($i === $nameAt) {
                continue;
            }
            // "Weekdays | 7:15 AM – 1:00 PM | 5:15 PM – 8:45 PM" under a
            // "Day | Morning | Evening" header: the column says which.
            $column = $columns['labels'][$i] ?? null;
            $label = $column !== null && ! preg_match('/^(time|timings?|hours|slot|schedule)$/i', $column) && ! preg_match('/^(time|timings?)$/i', (string) $name)
                ? trim($name.' – '.$column, ' –')
                : $name;
            foreach (self::timingsIn($cell, $label !== '' ? $label : null, $heading) as $t) {
                $timings[] = $t;
            }
        }
        // Times in the name cell itself ("Sandhya Arati 7:00 PM").
        if ($nameAt !== null && self::timesIn($name) !== []) {
            array_push($timings, ...self::timingsIn($name, null, $heading));
        }
    }

    /** A line of running text: a seva if it carries a price, else any timings in it. */
    private static function line(string $text, ?string $heading, array &$timings, array &$sevas): void
    {
        if (mb_strlen($text) > 300) {
            return;
        }
        $fee = mb_strlen($text) <= 160 ? self::priceIn($text) : null;
        if ($fee !== null) {
            if ($seva = self::seva($text, $fee, self::timesIn($text), [], $text)) {
                $sevas[] = $seva;
            }

            return;
        }
        array_push($timings, ...self::timingsIn($text, null, $heading));
    }

    /**
     * A seva from its name text (which may still carry the price, times and
     * a note in brackets), cleaned to the name a devotee would recognise.
     *
     * @param  array<int, array{0: string, 1: ?string, 2: int}>  $times
     * @param  array<int, string>  $notes
     */
    private static function seva(string $name, int $fee, array $times, array $notes, string $whole): ?array
    {
        if ($fee < 1 || $fee > 1_000_000 || preg_match(self::NOT_A_SEVA, $whole)) {
            return null;
        }

        $duration = preg_match('/(\d{1,3})\s*(?:mins?|minutes)\b/i', $whole, $d) ? (int) $d[1] : null;
        $text = preg_replace(self::PRICE, ' ', $name);
        $text = preg_replace_callback('/'.self::rangePattern().'|'.self::TIME.'/iu', fn ($m) => self::isTimeMatch($m[0]) ? ' ' : $m[0], $text);
        $text = preg_replace('/~?\s*\d{1,3}\s*(?:-\s*\d{1,3}\s*)?(?:mins?|minutes)\b/i', ' ', $text);
        $text = preg_replace('/\b(per\s+(person|head|couple|family|ticket)|each|only|amount|fee|price|cost|ticket\s+price)\b\s*:?/i', ' ', $text);

        // A note in brackets ("(Tue/Thu/Fri)", "(per couple)") is kept apart.
        if (preg_match_all('/\(([^)]*)\)/u', $text, $paren)) {
            foreach ($paren[1] as $p) {
                $p = self::clean(trim($p, ' ~-–,;:'));
                if ($p !== '' && preg_match('/\p{L}/u', $p)) {
                    $notes[] = $p;
                }
            }
            $text = preg_replace('/\([^)]*\)?/u', ' ', $text);
        }
        $text = preg_replace('/^\s*(\d{1,3}|[ivx]{1,4})\s*[\).:\-]\s*|^\s*\d{1,3}\s+(?=\p{L})/iu', '', $text);
        $text = self::clean(trim(self::clean($text), " \t:-–—|•*.,/;~"));
        if (preg_match('/\b'.self::DAY.'\b.*$/iu', $text, $dm) && self::isDaysOnly($dm[0]) && mb_strlen($text) - mb_strlen($dm[0]) >= 3) {
            // "Thomala Seva Tue, Thu, Fri"
            $notes[] = self::clean($dm[0]);
            $text = self::clean(trim(mb_substr($text, 0, mb_strlen($text) - mb_strlen($dm[0])), ' :-–,'));
        }

        if (mb_strlen($text) < 3 || mb_strlen($text) > 80 || str_word_count($text) > 10 || ! preg_match('/\p{L}{2}/u', $text)
            || preg_match('/^(seva|sevas|amount|fee|fees|price|rate|cost|ticket|tickets|total|rs|inr|name|details?)$/i', $text)) {
            return null;
        }

        $first = $times[0] ?? null;

        return array_filter([
            'name' => $text,
            'fee' => $fee,
            'kind' => self::pujaKindFor($text),
            'starts_at' => $first[0] ?? null,
            'duration_minutes' => $duration,
            'note' => $notes === [] ? null : Str::limit(implode('; ', array_unique($notes)), 120, ''),
        ], fn ($v) => $v !== null);
    }

    /**
     * Timings in a piece of text. Each time or time range is named by the
     * words just before it; "Morning 5 AM – 12 PM, Evening 4 – 9 PM" is two
     * timings, and "Weekdays (Mon–Fri):" before them becomes their note.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function timingsIn(string $text, ?string $label = null, ?string $heading = null): array
    {
        $times = self::timesIn($text);
        if ($times === []) {
            return [];
        }

        $out = [];
        $previousEnd = 0;
        $carried = $label !== null ? self::cleanLabel($label) : null;
        $qualifier = null;
        foreach ($times as [$opens, $closes, $at, $length]) {
            $before = mb_substr($text, $previousEnd, max(0, $at - $previousEnd));
            $previousEnd = $at + $length;

            // "Weekdays (Mon–Fri): Morning" — what is before the colon
            // applies to every timing after it.
            if (preg_match('/^(.*\S)\s*:\s*(.*)$/su', $before, $q) && preg_match('/\p{L}/u', $q[2])) {
                $qualifier = self::clean(trim($q[1], ' ,;|&-–'));
                $before = $q[2];
            }
            $own = self::cleanLabel($before);
            $notes = $qualifier;
            if ($own !== '' && self::isDaysOnly($own)) {
                $notes = $own;
                $own = '';
            }
            if ($qualifier !== null && $own === '' && ! self::isDaysOnly($qualifier)) {
                $own = self::cleanLabel($qualifier);
                $notes = null;
            }

            $name = $own !== '' ? $own : ($carried ?? '');
            if ($own !== '' && $label !== null && $own !== self::cleanLabel($label) && self::isGeneric($own)) {
                $name = self::cleanLabel($label).' – '.$own;
            }
            if ($name === '' || self::isGeneric($name)) {
                $context = $heading !== null ? self::cleanLabel($heading) : '';
                $name = $context !== '' && mb_strtolower($context) !== mb_strtolower($name)
                    ? trim($context.' – '.$name, ' –')
                    : ($name !== '' ? $name : 'Temple timings');
            }
            if ($own !== '') {
                $carried = $own;
            }

            // "Weekdays (Mon–Fri)", "Sat & Sun" become the timing's days.
            $days = $notes !== null ? self::daysIn($notes) : null;
            if ($days !== null && self::isDaysOnly($notes)) {
                $notes = null;
            }

            $out[] = array_filter([
                'label' => Str::limit(Str::ucfirst($name), 60, ''),
                'days' => $days,
                'opens_at' => $opens,
                'closes_at' => $closes,
                'kind' => self::kindFor($name) !== 'general' ? self::kindFor($name) : self::kindFor((string) $heading),
                'notes' => $notes !== null && $notes !== '' ? Str::limit($notes, 80, '') : null,
            ], fn ($v) => $v !== null) + ['closes_at' => null];
        }

        return $out;
    }

    /**
     * Times and time ranges in text, in order: [opens, closes|null, offset, length].
     *
     * @return array<int, array{0: string, 1: ?string, 2: int, 3: int}>
     */
    public static function timesIn(string $text): array
    {
        $out = [];
        $taken = [];
        if (preg_match_all('/'.self::rangePattern().'/iu', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                $g = fn (int $i): string => (string) ($m[$i][0] ?? '');
                [$opens, $closes] = self::range([$g(1), $g(2), $g(3), $g(4)], [$g(5), $g(6), $g(7), $g(8)]);
                if ($opens === null) {
                    continue;
                }
                $at = mb_strlen(substr($text, 0, $m[0][1]));
                $len = mb_strlen($m[0][0]);
                $out[] = [$opens, $closes, $at, $len];
                $taken[] = [$at, $at + $len];
            }
        }
        if (preg_match_all('/(?<![\d:.₹\/])'.self::TIME.'(?![\d\/])/iu', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                $at = mb_strlen(substr($text, 0, $m[0][1]));
                if (array_filter($taken, fn ($span) => $at >= $span[0] && $at < $span[1])) {
                    continue;
                }
                $g = fn (int $i): string => (string) ($m[$i][0] ?? '');
                if (! self::looksLikeTime($g(2), $g(4))) {
                    continue;
                }
                if (($t = self::hhmm($g(1), $g(3), $g(4))) !== null) {
                    $out[] = [$t, null, $at, mb_strlen(rtrim($m[0][0]))];
                }
            }
        }
        usort($out, fn ($a, $b) => $a[2] <=> $b[2]);

        return $out;
    }

    /**
     * A range from its two ends, borrowing the marker the other end has:
     * "6 – 9 PM" is evening, "11 – 1 PM" runs over noon.
     *
     * @param  array{0: string, 1: string, 2: string, 3: string}  $a  hour, separator, minutes, marker
     * @param  array{0: string, 1: string, 2: string, 3: string}  $b
     * @return array{0: ?string, 1: ?string}
     */
    private static function range(array $a, array $b): array
    {
        $aOk = self::looksLikeTime($a[1], $a[3]);
        $bOk = self::looksLikeTime($b[1], $b[3]);
        // Bare numbers ("2018-2023", "10-12 buses") are not times.
        if (! ($aOk && $bOk) && ! (($aOk || $bOk) && ($a[3] !== '' || $b[3] !== ''))) {
            return [null, null];
        }

        $closes = self::hhmm($b[0], $b[2], $b[3] !== '' ? $b[3] : $a[3]);
        $marker = $a[3];
        if ($marker === '' && preg_match('/^p/i', $b[3])) {
            // Same half of the day unless that would end before it starts.
            $marker = (int) $a[0] < 12 && (int) $a[0] <= (int) $b[0] && (int) $b[0] !== 12 ? 'pm' : 'am';
        } elseif ($marker === '') {
            $marker = $b[3];
        }
        $opens = self::hhmm($a[0], $a[2], $marker);
        if ($opens === null || $closes === null) {
            return [null, null];
        }
        if ($b[3] === '' && $a[3] === '' && $closes < $opens && (int) $b[0] < 12) {
            $closes = self::hhmm((string) ((int) $b[0] + 12), $b[2], '');
        }

        return [$opens, $closes];
    }

    private static function rangePattern(): string
    {
        return self::TIME.'\s*(?:to|till|until|up\s*to|upto|-|–|—|~)\s*'.self::TIME;
    }

    private static function looksLikeTime(string $separator, string $marker): bool
    {
        return $marker !== '' || $separator === ':';
    }

    private static function isTimeMatch(string $match): bool
    {
        return self::timesIn($match) !== [];
    }

    /** 24-hour "HH:MM" from an hour, minutes and an am/pm/noon/hrs marker. */
    private static function hhmm(string $h, string $m, string $marker): ?string
    {
        if ($h === '') {
            return null;
        }
        $hour = (int) $h;
        $min = $m === '' ? 0 : (int) $m;
        if ($hour > 24 || $min > 59) {
            return null;
        }
        $marker = strtolower(str_replace(['.', ' '], '', $marker));
        if (str_starts_with($marker, 'p') && $hour < 12) {
            $hour += 12;
        } elseif ((str_starts_with($marker, 'a') || $marker === 'midnight') && $hour === 12) {
            $hour = 0;
        } elseif (in_array($marker, ['am', 'pm', 'a', 'p'], true) && $hour > 12) {
            return null;
        }
        if ($hour === 24) {
            $hour = 0;
        }

        return sprintf('%02d:%02d', $hour, $min);
    }

    /** The money in a piece of text, in whole rupees. */
    private static function priceIn(string $text): ?int
    {
        if (! preg_match(self::PRICE, $text, $m)) {
            return null;
        }
        $amount = (int) str_replace(',', '', ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '0'));

        return $amount > 0 ? $amount : null;
    }

    /**
     * What a short line is on its own: a name, only a time, only a price,
     * or running text.
     */
    private static function shapeOf(string $text): string
    {
        $len = mb_strlen($text);
        $hasTime = self::timesIn($text) !== [];
        $hasPrice = self::priceIn($text) !== null;
        if (! $hasTime && ! $hasPrice) {
            return $len <= 70 && str_word_count($text) <= 9 && preg_match('/\p{L}{3}/u', $text) && ! preg_match('/[.!?]$/', $text)
                && self::phonesIn('phone '.$text) === [] && ! str_contains($text, '@') ? 'name' : 'text';
        }
        // Take out the times and prices; what is left is filler, or a name.
        $rest = preg_replace(self::PRICE, ' ', $text);
        $rest = preg_replace('/'.self::rangePattern().'|'.self::TIME.'/iu', ' ', $rest);
        $rest = preg_replace('/\b(per\s+(person|head|couple|family|ticket)|each|only|onwards|amount|fee|price|cost|time|timings?|hrs|from|at|daily|rs|inr)\b|'.self::DAY.'|[\W\d_]+/iu', '', $rest);
        if (trim($rest) !== '' && ! self::isDaysOnly($rest)) {
            return 'text';
        }

        return $hasPrice ? 'price' : 'time';
    }

    /**
     * The days a phrase names, Monday first: "Weekdays (Mon–Fri)", "Sat &
     * Sun", "Monday to Saturday", "Tue/Thu/Fri". Null when it names none,
     * or every day.
     *
     * @return array<int, int>|null
     */
    public static function daysIn(string $text): ?array
    {
        $week = [1, 2, 3, 4, 5, 6, 0];
        $index = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
        $t = mb_strtolower($text);
        if (preg_match('/daily|every\s*day|all\s*days/', $t)) {
            return null;
        }
        $days = [];
        // Ranges first: "Mon–Fri", "Monday to Saturday".
        $t = preg_replace_callback('/\b(sun|mon|tue|wed|thu|fri|sat)[a-z]*\.?\s*(?:-|–|—|to|till|through)\s*(sun|mon|tue|wed|thu|fri|sat)[a-z]*\b/u', function ($m) use (&$days, $week, $index) {
            $from = array_search($index[$m[1]], $week, true);
            $to = array_search($index[$m[2]], $week, true);
            for ($i = $from; $i !== $to; $i = ($i + 1) % 7) {
                $days[] = $week[$i];
            }
            $days[] = $week[$to];

            return ' ';
        }, $t);
        if (preg_match_all('/\b(sun|mon|tue|wed|thu|fri|sat)[a-z]*\b/u', $t, $m)) {
            foreach ($m[1] as $d) {
                $days[] = $index[$d];
            }
        }
        if ($days === [] && preg_match('/week\s*days?/', $t)) {
            $days = [1, 2, 3, 4, 5];
        }
        if ($days === [] && preg_match('/week\s*ends?/', $t)) {
            $days = [6, 0];
        }
        $days = array_values(array_intersect($week, array_unique($days)));

        return $days === [] || count($days) === 7 ? null : $days;
    }

    private static function isDaysOnly(string $text): bool
    {
        $rest = preg_replace('/\b'.self::DAY.'\b|\b(week\s*days?|week\s*ends?|daily|every\s*day|all\s*days|and|to|only|on|except|days?|festival|festivals|holidays?|special|amavasya|pournami|ekadasi)\b|[\s&,\/()\-–—.:;]+/iu', '', $text);

        return $rest === '' && preg_match('/\b'.self::DAY.'\b|week\s*days?|week\s*ends?|daily|every\s*day|all\s*days|festival|holiday|amavasya|pournami|ekadasi/iu', $text);
    }

    private static function isGeneric(string $label): bool
    {
        return (bool) preg_match('/^(morning|forenoon|afternoon|noon|evening|night|daily|every\s*day|week\s*days?|week\s*ends?|general|open|opening|temple|timings?|hours|darshan|darshanam|temple\s+timings|timings?\s+of\s+the\s+temple|temple\s+(is\s+)?open)$/i', trim($label));
    }

    /** "Darshan Timings :" → "Darshan"; "The temple is open from" → "Temple open". */
    private static function cleanLabel(string $text): string
    {
        $text = self::clean(trim($text, " \t:-–—|•*,;&.(/"));
        $text = trim(preg_replace('/^(and|&|,|also|then)(\s+|$)/i', '', $text));
        do {
            $before = $text;
            $text = trim(preg_replace('/\s*\b(from|between|at|is|are|opens?|timings?|time|hours|schedule|details|starts?|begins?|will\s+be|:)\s*$/i', '', $text), " \t:-–—|•*,;&.(");
        } while ($text !== $before && $text !== '');
        $text = preg_replace('/^the\s+/i', '', $text);
        if (str_word_count($text) > 7) {
            return preg_match('/\btemple\b.*\b(open|close)/i', $text) ? 'Temple open' : '';
        }
        if (preg_match('/^\d+$/', $text)) {
            return '';
        }

        return Str::ucfirst($text);
    }

    private static function kindFor(string $label): string
    {
        return match (true) {
            (bool) preg_match('/aart?i|arati|harathi|haarathi|aarathi|bhog|shayana|sandhya|mangala/i', $label) => 'aarti',
            (bool) preg_match('/darshan|darsan|dharshan|sarva|dharma/i', $label) => 'darshan',
            (bool) preg_match('/special|vip|seva|abhishek|kalyan|suprabhat|pooja|puja/i', $label) => 'special',
            default => 'general',
        };
    }

    private static function pujaKindFor(string $name): string
    {
        return match (true) {
            (bool) preg_match('/prasad|laddu|laddoo|pulihora|vada|pongal|prasadam/i', $name) => 'prasadam',
            (bool) preg_match('/archana|abhishek|puja|pooja|homam|havan|vratham|vratam/i', $name) => 'puja',
            default => 'seva',
        };
    }

    /**
     * One seva found twice (on two pages, or as "Abhishekam" and
     * "Abhishekam Seva") is one seva; a second fee goes in its note.
     *
     * @param  array<int, array<string, mixed>>  $sevas
     * @return array<int, array<string, mixed>>
     */
    private static function mergeSevas(array $sevas): array
    {
        $out = [];
        foreach ($sevas as $s) {
            $key = preg_replace('/(seva|pooja|puja|ticket|tickets)$/u', '', preg_replace('/[^\p{L}]+/u', '', mb_strtolower($s['name'])));
            $key = $key === '' ? mb_strtolower($s['name']) : $key;
            if (! isset($out[$key])) {
                $out[$key] = $s;

                continue;
            }
            $kept = &$out[$key];
            if ($kept['fee'] !== $s['fee'] && ! str_contains((string) ($kept['note'] ?? ''), '₹'.number_format($s['fee']))) {
                $kept['note'] = trim(($kept['note'] ?? '').'; also ₹'.number_format($s['fee']).(isset($s['note']) ? ' ('.$s['note'].')' : ''), '; ');
            }
            foreach (['starts_at', 'duration_minutes', 'note'] as $field) {
                if (! isset($kept[$field]) && isset($s[$field])) {
                    $kept[$field] = $s[$field];
                }
            }
            unset($kept);
        }

        return array_values($out);
    }

    /**
     * What a table's header row says each column holds.
     *
     * @param  array<int, string>  $cells
     * @return array<string, mixed>
     */
    private static function columns(array $cells): array
    {
        $columns = ['labels' => []];
        foreach ($cells as $i => $cell) {
            $c = mb_strtolower($cell);
            $columns['labels'][$i] = self::clean(trim($cell, ' :'));
            if (! isset($columns['price']) && preg_match('/amount|fee|price|cost|rate|tariff|₹|\brs\b|inr|rupees|charges?/u', $c)) {
                $columns['price'] = $i;
            } elseif (! isset($columns['name']) && preg_match('/seva|pooja|puja|name|particulars|darshan|aart?i|arati|ritual|item|description|programme|program|event/u', $c) && ! preg_match('/time|timing/', $c)) {
                $columns['name'] = $i;
            }
        }

        return $columns;
    }

    // --- Reading pages ---

    /** @return array{0: ?string, 1: string, 2: string} html, why not, the address that answered */
    private static function fetchHome(string $url): array
    {
        [$html, $why] = self::fetch($url);
        if ($html !== null) {
            return [$html, '', $url];
        }
        // An address that is not on the public internet stays refused; a
        // "www." in front of an IP is not another site either.
        if ($why === self::NOT_PUBLIC || filter_var(trim((string) parse_url($url, PHP_URL_HOST), '[]'), FILTER_VALIDATE_IP)) {
            return [null, $why, $url];
        }
        // Temple sites often have an expired certificate, or answer only
        // with (or without) "www.".
        $host = (string) parse_url($url, PHP_URL_HOST);
        $alternatives = [
            preg_replace('#^https://#i', 'http://', $url),
            str_starts_with($host, 'www.') ? str_replace('://www.', '://', $url) : str_replace('://'.$host, '://www.'.$host, $url),
        ];
        foreach (array_unique($alternatives) as $alt) {
            if ($alt !== $url && ([$h] = self::fetch($alt)) && $h !== null) {
                return [$h, '', $alt];
            }
        }

        return [null, $why, $url];
    }

    /** The most read of any one page or sitemap, after decompression. */
    public const MAX_BYTES = 3_000_000;

    private const NOT_PUBLIC = 'not a public web address';

    /** Redirects followed, each checked like the first address. */
    private const MAX_REDIRECTS = 3;

    /** @return array{0: ?string, 1: string} the page's html, or why it could not be read */
    private static function fetch(string $url, bool $xml = false): array
    {
        try {
            for ($hop = 0; ; $hop++) {
                // The address comes from a temple team: the server only ever
                // goes to the public internet, never to itself or its network.
                $ip = PublicAddress::for($url);
                if ($ip === null) {
                    return [null, self::NOT_PUBLIC];
                }
                $sink = new CappedSink(self::MAX_BYTES);
                $port = (int) (parse_url($url, PHP_URL_PORT) ?? (str_starts_with(strtolower($url), 'https:') ? 443 : 80));
                $response = Http::timeout(20)->connectTimeout(10)->withOptions([
                    // Redirects are followed here, one checked hop at a time.
                    'allow_redirects' => false,
                    'sink' => $sink,
                    // Connect to the address that was checked, so a DNS answer
                    // that changes in between cannot lead somewhere else.
                    'curl' => [CURLOPT_RESOLVE => [parse_url($url, PHP_URL_HOST).':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)]],
                ])->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36 '.config('brand.name').'/1.0',
                    'Accept' => $xml ? 'application/xml,text/xml,*/*' : 'text/html,application/xhtml+xml,*/*;q=0.8',
                    'Accept-Language' => 'en-IN,en;q=0.9,te;q=0.8,hi;q=0.7',
                ])->get($url);
                if ($response->redirect() && filled($location = $response->header('Location'))) {
                    if ($hop >= self::MAX_REDIRECTS || ($next = self::absolute($location, $url)) === null) {
                        return [null, 'too many redirects'];
                    }
                    $url = $next;

                    continue;
                }
                break;
            }
            if (! $response->successful()) {
                return [null, 'the site answered '.$response->status()];
            }
            $type = strtolower((string) $response->header('Content-Type'));
            $body = substr((string) $response->body(), 0, self::MAX_BYTES);
            $looksRight = $xml ? (str_contains($type, 'xml') || str_starts_with(ltrim($body), '<?xml')) : (str_contains($type, 'html') || ($type === '' && str_starts_with(ltrim($body), '<')));
            if (! $looksRight) {
                return [null, 'not a web page'];
            }

            return [mb_substr($body, 0, self::MAX_BYTES), ''];
        } catch (Throwable) {
            return [null, 'no answer'];
        }
    }

    /** @return array<string, int> this site's own pages that look like timings, sevas or contact, with how likely */
    private static function likelyPages(string $html, string $base): array
    {
        [$xpath] = self::parse($html, false);
        $links = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            $href = self::absolute(trim($a->getAttribute('href')), $base);
            if ($href === null || ! self::sameSite($href, $base) || preg_match('/\.(pdf|jpe?g|png|gif|webp|zip|docx?|xlsx?|mp4|mp3)(\?|$)/i', $href)) {
                continue;
            }
            $href = strtok($href, '#');
            $hint = urldecode($href).' '.$a->textContent;
            if (preg_match(self::LINK_HINTS, $hint)) {
                $links[$href] = max($links[$href] ?? 0, preg_match(self::STRONG_HINTS, $hint) ? 10 : 5);
            }
        }
        unset($links[strtok($base, '#')]);

        return $links;
    }

    /** @return array<string, int> hinted pages listed in the site's sitemap */
    private static function sitemapPages(string $url): array
    {
        $p = parse_url($url);
        $root = ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '');
        [$xml] = self::fetch($root.'/sitemap.xml', true);
        if ($xml === null) {
            return [];
        }
        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m);
        $locs = $m[1];
        if (str_contains($xml, '<sitemapindex')) {
            $locs = [];
            foreach (array_slice($m[1], 0, 3) as $child) {
                [$inner] = self::fetch(html_entity_decode($child), true);
                if ($inner !== null && preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $inner, $mm)) {
                    array_push($locs, ...$mm[1]);
                }
            }
        }
        $pages = [];
        foreach (array_slice($locs, 0, 500) as $loc) {
            $loc = html_entity_decode($loc);
            if (self::sameSite($loc, $url) && ! preg_match('/\.(pdf|jpe?g|png|gif|webp)$/i', $loc) && preg_match(self::STRONG_HINTS, urldecode($loc))) {
                $pages[$loc] = 8;
            }
        }

        return $pages;
    }

    /** @return array<string, string> PDFs and images linked as timings or seva lists, which cannot be read */
    private static function documents(string $html, string $base): array
    {
        [$xpath] = self::parse($html, false);
        $docs = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            $href = self::absolute(trim($a->getAttribute('href')), $base);
            if ($href !== null && preg_match('/\.(pdf|jpe?g|png|webp)(\?|$)/i', $href) && preg_match(self::STRONG_HINTS, urldecode($href).' '.$a->textContent)) {
                $docs[$href] = Str::limit(self::clean($a->textContent) ?: basename(parse_url($href, PHP_URL_PATH) ?: $href), 80, '');
            }
        }

        return $docs;
    }

    private static function sameSite(string $url, string $base): bool
    {
        $strip = fn (string $u): string => preg_replace('/^www\./i', '', strtolower((string) parse_url($u, PHP_URL_HOST)));

        return $strip($url) !== '' && $strip($url) === $strip($base);
    }

    /**
     * The page as records: a block of text, or a table row with its cells.
     *
     * @return array{0: DOMXPath, 1: array<int, array<string, mixed>>}
     */
    private static function parse(string $html, bool $records = true): array
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);
        if (! $records) {
            return [$xpath, []];
        }

        $out = [];
        $buf = '';
        $table = 0;
        $body = $doc->getElementsByTagName('body')->item(0) ?? $doc;
        self::walk($body, $out, $buf, $table);
        self::flush($out, $buf);

        return [$xpath, $out];
    }

    /** @param array<int, array<string, mixed>> $out */
    private static function walk(DOMNode $node, array &$out, string &$buf, int &$table): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $buf .= $child->nodeValue;

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::SKIP, true) || preg_match('/display\s*:\s*none/i', (string) $child->getAttribute('style'))) {
                continue;
            }
            if ($tag === 'br') {
                self::flush($out, $buf);

                continue;
            }
            if ($tag === 'table' && ($rows = self::dataRows($child)) !== null) {
                self::flush($out, $buf);
                $id = ++$table;
                foreach ($rows as $n => [$cells, $allTh]) {
                    $texts = array_values(array_filter($cells, fn ($c) => $c !== ''));
                    if ($texts === []) {
                        continue;
                    }
                    if (count($texts) === 1) {
                        $out[] = self::record($texts[0], heading: $allTh);

                        continue;
                    }
                    $header = $allTh || ($n === 0 && ! preg_match('/\d/', implode(' ', $texts)));
                    $out[] = ['text' => implode(' · ', $texts), 'cells' => $cells, 'table' => $id, 'header' => $header, 'heading' => false];
                }

                continue;
            }

            $block = in_array($tag, self::BLOCKS, true);
            if ($block) {
                self::flush($out, $buf);
            }
            $start = count($out);
            self::walk($child, $out, $buf, $table);
            if ($block) {
                self::flush($out, $buf);
                if (preg_match('/^h[1-6]$/', $tag)) {
                    for ($i = $start; $i < count($out); $i++) {
                        $out[$i]['heading'] = true;
                    }
                }
            } else {
                $buf .= ' ';
            }
        }
    }

    /**
     * A table's rows as cells, or null for a table used only for layout
     * (long text in its cells, or tables inside it), which is read as text.
     *
     * @return array<int, array{0: array<int, string>, 1: bool}>|null
     */
    private static function dataRows(DOMElement $table): ?array
    {
        if ($table->getElementsByTagName('table')->length > 0) {
            return null;
        }
        $rows = [];
        $trs = [];
        foreach ($table->childNodes as $c) {
            if ($c instanceof DOMElement && strtolower($c->tagName) === 'tr') {
                $trs[] = $c;
            } elseif ($c instanceof DOMElement && in_array(strtolower($c->tagName), ['thead', 'tbody', 'tfoot'], true)) {
                foreach ($c->childNodes as $cc) {
                    if ($cc instanceof DOMElement && strtolower($cc->tagName) === 'tr') {
                        $trs[] = $cc;
                    }
                }
            }
        }
        foreach ($trs as $tr) {
            $cells = [];
            $allTh = true;
            foreach ($tr->childNodes as $cell) {
                if (! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    continue;
                }
                $lines = [];
                $b = '';
                $t = 0;
                self::walk($cell, $lines, $b, $t);
                self::flush($lines, $b);
                $text = self::clean(implode(' ', array_column($lines, 'text')));
                if (mb_strlen($text) > 250) {
                    return null;
                }
                $cells[] = $text;
                $allTh = $allTh && strtolower($cell->tagName) === 'th';
            }
            if ($cells !== []) {
                $rows[] = [$cells, $allTh];
            }
        }

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $out */
    private static function flush(array &$out, string &$buf): void
    {
        $text = self::clean($buf);
        $buf = '';
        if ($text !== '' && mb_strlen($text) <= 1500) {
            $out[] = self::record($text);
        }
    }

    /** @return array<string, mixed> */
    private static function record(string $text, bool $heading = false): array
    {
        return ['text' => $text, 'cells' => null, 'table' => null, 'header' => false, 'heading' => $heading];
    }

    private static function visibleText(string $html): string
    {
        [, $records] = self::parse($html);

        return implode(' ', array_column($records, 'text'));
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
        if (! preg_match('/phone|ph\b|ph\.|mobile|mob|cell|contact|call|tel|whatsapp|office|enquir|ఫోన్|फ़ोन|फोन/iu', $line)) {
            return [];
        }
        preg_match_all('/(?:\+?91[\s-]?)?(?:0\d{2,4}[\s-]?\d{3,4}[\s-]?\d{3,4}|[6-9]\d{4}[\s-]?\d{5})/', $line, $m);

        return array_values(array_filter($m[0], fn ($p) => strlen(preg_replace('/\D/', '', $p)) >= 10));
    }

    private static function pincodeIn(string $text): ?string
    {
        return preg_match('/(?<![\d₹,.:])([1-9]\d{2})\s?(\d{3})(?![\d,.:])/u', $text, $m) ? $m[1].$m[2] : null;
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
        if ($href === null || $href === '' || str_starts_with($href, '#') || preg_match('/^(javascript|mailto|tel|whatsapp|sms):/i', $href)) {
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

        return $root.$dir.'/'.preg_replace('#^\./#', '', $href);
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
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

    /** "05:30" → "5:30 AM", for people reading it. */
    public static function twelveHour(?string $time): ?string
    {
        return Clock::twelve($time);
    }

    /** "5:30 pm", "5.30PM", "17:30" → "17:30"; null when it is not a time. */
    public static function fromTwelveHour(?string $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '' || ! preg_match('/^'.self::TIME.'$/iu', $text, $m)) {
            return null;
        }
        if (($m[4] ?? '') === '' && ($m[2] ?? '') !== ':') {
            return null;
        }

        return self::hhmm($m[1], $m[3] ?? '', $m[4] ?? '');
    }
}
