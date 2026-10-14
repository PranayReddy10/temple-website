<?php

namespace App\Support\TempleImport;

use App\Enums\TempleStatus;
use App\Enums\TimingKind;
use App\Enums\VerificationStatus;
use App\Models\Deity;
use App\Models\District;
use App\Models\State;
use App\Models\Temple;
use App\Models\TempleCategory;
use Illuminate\Support\Str;

/**
 * Loads a state's researched temples (database/data/temples/<state>.json, or
 * what the research agent finds) into the site, one temple at a time.
 *
 * A temple already listed is never added again: TempleMatcher recognises it
 * by slug, any of its names, or its place. What happens then follows the
 * same rules as TelanganaTempleSeeder:
 *
 * - A temple an editor has verified (verified or official level, or any
 *   last-verified date), or deleted, is left alone.
 * - Any other listed temple only has its EMPTY fields filled; an editor's
 *   wording always wins, and its publishing status is kept.
 * - Timings and sevas are only added to a temple that has none.
 * - Featured is only ever switched on.
 *
 * New temples are COMMUNITY level: researched from public sources (recorded
 * as the source), not checked with the temple. No seva price is ever
 * imported ("No published price", never an invented figure).
 */
final class StateTempleImporter
{
    public const TIMING_NOTE = 'Hours as published by the temple or commonly reported, not confirmed with the temple. Hours change on festival days, during eclipses and for renovation — check with the devasthanam before travelling.';

    /** No district reaches further than this from its middle. */
    public const DISTRICT_KM = 150;

    /**
     * The first two digits of the state's PIN codes, for the states whose
     * temples are imported so far. A state not listed is not checked.
     */
    public const PIN_PREFIXES = [
        'AP' => ['51', '52', '53'],
        'TG' => ['50'],
        'KA' => ['56', '57', '58', '59'],
        'TN' => ['60', '61', '62', '63', '64'],
        'KL' => ['67', '68', '69'],
    ];

    /** @var array{created: int, updated: int, unchanged: int, skipped: int, invalid: int} */
    public array $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'invalid' => 0];

    /** @var array<int, array{name: string, result: string, temple: ?string}> what happened to each row */
    public array $log = [];

    private TempleMatcher $matcher;

    public function __construct(
        private readonly State $state,
        private readonly TempleStatus $newStatus = TempleStatus::Published,
        private readonly bool $dryRun = false,
    ) {
        $this->matcher = new TempleMatcher($state->id);
    }

    /** The data file for a state: database/data/temples/andhra-pradesh.json. */
    public static function dataFile(State $state): string
    {
        return database_path('data/temples/'.Str::slug($state->name).'.json');
    }

    /** @return array<int, array<string, mixed>> */
    public static function readFile(string $path): array
    {
        if (! is_readable($path)) {
            return [];
        }
        $rows = json_decode((string) file_get_contents($path), true);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** The temple a row describes, if the site already lists it. */
    public function existing(array $row): ?Temple
    {
        return $this->matcher->find($row);
    }

    /** @param iterable<array<string, mixed>> $rows */
    public function importAll(iterable $rows): void
    {
        foreach ($rows as $row) {
            $this->import($row);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return string created | updated | unchanged | skipped | invalid
     */
    public function import(array $row): string
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '' || blank($row['district'] ?? null)) {
            return $this->record($name ?: '(no name)', 'invalid', null);
        }

        $temple = $this->matcher->find($row);

        if ($temple !== null && $this->editorOwns($temple)) {
            return $this->record($name, 'skipped', $temple);
        }

        if ($this->dryRun) {
            return $this->record($name, $temple === null ? 'created' : 'updated', $temple);
        }

        $district = District::firstOrCreate(
            ['state_id' => $this->state->id, 'slug' => Str::slug($row['district'])],
            ['name' => $row['district']],
        );

        $attributes = $this->attributes($row, $district);

        if ($temple === null) {
            $temple = Temple::create($attributes + [
                'name' => mb_substr($name, 0, 255),
                'slug' => $this->freeSlug($name),
                'status' => $this->newStatus,
                'is_featured' => (bool) ($row['famous'] ?? false),
                'verification_status' => VerificationStatus::Community,
                'last_verified_at' => null,
            ]);
            $result = 'created';
        } else {
            // Fill only what is empty. An editor's wording always wins.
            $fill = array_filter(
                $attributes,
                fn ($value, string $key): bool => $value !== null && blank($temple->getAttribute($key)),
                ARRAY_FILTER_USE_BOTH,
            );
            if ($row['famous'] ?? false) {
                $fill['is_featured'] = true;
            }
            $temple->forceFill($fill);
            $result = $temple->isDirty() ? 'updated' : 'unchanged';
            $temple->save();
        }

        $this->attachDetails($temple, $row, $result);
        $this->matcher->remember($temple);

        return $this->record($name, $result, $temple);
    }

    /** @return array<string, mixed> */
    private function attributes(array $row, District $district): array
    {
        $rules = (array) ($row['rules'] ?? []);
        $sources = array_values(array_filter((array) ($row['sources'] ?? []), fn ($s) => is_array($s) && filled($s['url'] ?? null)));
        $lat = is_numeric($row['lat'] ?? null) ? round((float) $row['lat'], 7) : null;
        $lng = is_numeric($row['lng'] ?? null) ? round((float) $row['lng'], 7) : null;
        // Only a pin inside India; a swapped or zero pair is worse than none.
        if ($lat === null || $lng === null || $lat <= 6 || $lat >= 37 || $lng <= 68 || $lng >= 98 || ! $this->nearDistrict($district, $lat, $lng)) {
            $lat = $lng = null;
        }
        $pincode = preg_match('/^\d{6}$/', (string) ($row['pincode'] ?? '')) ? (string) $row['pincode'] : null;
        $prefixes = self::PIN_PREFIXES[$this->state->code] ?? null;
        if ($pincode !== null && $prefixes !== null && ! in_array(substr($pincode, 0, 2), $prefixes, true)) {
            $pincode = null; // A PIN of another state is a mistake, not an address.
        }

        return array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, [
            'deity_id' => filled($row['deity'] ?? null) ? Deity::where('slug', $row['deity'])->value('id') : null,
            'state_id' => $this->state->id,
            'district_id' => $district->id,
            'city' => self::text($row['city'] ?? null, 255),
            'address' => self::text($row['address'] ?? null),
            'pincode' => $pincode,
            'latitude' => $lat,
            'longitude' => $lng,
            'short_description' => self::text($row['summary'] ?? null, 500),
            'history' => self::text($row['history'] ?? null),
            'significance' => self::text($row['significance'] ?? null),
            'architecture_style' => self::text($row['architecture_style'] ?? null, 255),
            'built_period' => self::text($row['built_period'] ?? null, 255),
            'official_website' => self::url($row['website'] ?? null),
            'contact_phone' => self::text($row['phone'] ?? null, 40),
            'contact_email' => filter_var($row['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
            'dress_code' => self::text($rules['dress_code'] ?? null),
            'photography_policy' => self::text($rules['photography'] ?? null),
            'mobile_policy' => self::text($rules['mobile'] ?? null, 255),
            'footwear_policy' => self::text($rules['footwear'] ?? null, 255),
            'entry_rules' => self::text($rules['entry'] ?? null),
            'queue_information' => self::text($rules['queue'] ?? null),
            'how_to_reach' => self::howToReach((array) ($row['nearest'] ?? [])),
            'source_name' => isset($sources[0]) ? self::text($sources[0]['name'] ?? 'Public source', 255) : null,
            'source_url' => isset($sources[0]) ? self::url($sources[0]['url']) : null,
        ]);
    }

    /**
     * Whether a pin is plausibly in its district: within DISTRICT_KM of the
     * middle of the district's other pinned temples. A district with none
     * yet cannot say, so any pin passes.
     */
    private function nearDistrict(District $district, float $lat, float $lng): bool
    {
        $pins = Temple::where('district_id', $district->id)->whereNotNull('latitude')->whereNotNull('longitude')
            ->get(['latitude', 'longitude']);
        if ($pins->isEmpty()) {
            return true;
        }
        $middleLat = $pins->pluck('latitude')->map(fn ($v) => (float) $v)->median();
        $middleLng = $pins->pluck('longitude')->map(fn ($v) => (float) $v)->median();

        return TempleMatcher::metres($lat, $lng, (float) $middleLat, (float) $middleLng) <= self::DISTRICT_KM * 1000;
    }

    private function attachDetails(Temple $temple, array $row, string &$result): void
    {
        $before = $result;

        $categories = TempleCategory::whereIn('slug', (array) ($row['categories'] ?? []))->pluck('id');
        if ($categories->isNotEmpty() && $temple->categories()->syncWithoutDetaching($categories)['attached'] !== []) {
            $result = $before === 'unchanged' ? 'updated' : $before;
        }

        foreach ((array) ($row['aliases'] ?? []) as $alias => $locale) {
            $alias = trim((string) $alias);
            if ($alias === '' || $alias === $temple->name) {
                continue;
            }
            if ($temple->aliases()->firstOrCreate(['name' => mb_substr($alias, 0, 255)], ['locale' => is_string($locale) ? $locale : null])->wasRecentlyCreated && $result === 'unchanged') {
                $result = 'updated';
            }
        }

        $timings = array_values(array_filter((array) ($row['timings'] ?? []), fn ($t) => is_array($t) && self::time($t['opens'] ?? null) && self::time($t['closes'] ?? null)));
        if ($timings !== [] && ! $temple->timings()->exists()) {
            foreach ($timings as $index => $t) {
                $kind = TimingKind::tryFrom((string) ($t['kind'] ?? '')) ?? TimingKind::General;
                $days = is_array($t['days'] ?? null) ? array_values(array_unique(array_filter($t['days'], fn ($d) => is_int($d) && $d >= 0 && $d <= 6))) : null;
                $temple->timings()->create([
                    'kind' => $kind,
                    'label' => self::text($t['label'] ?? null, 255),
                    'days' => $days ?: null,
                    'opens_at' => self::time($t['opens']),
                    'closes_at' => self::time($t['closes']),
                    'notes' => $kind === TimingKind::General && $index === 0 ? self::TIMING_NOTE : null,
                    'sort_order' => $index + 1,
                ]);
            }
            $result = $result === 'unchanged' ? 'updated' : $result;
        }

        $pujas = array_values(array_filter((array) ($row['pujas'] ?? []), fn ($p) => is_array($p) && filled($p['name'] ?? null)));
        if ($pujas !== [] && ! $temple->pujas()->exists()) {
            foreach ($pujas as $index => $p) {
                $temple->pujas()->create([
                    'name' => self::text($p['name'], 255),
                    'description' => self::text($p['description'] ?? null),
                    'starts_at' => self::time($p['starts_at'] ?? null),
                    'duration_minutes' => is_numeric($p['duration_minutes'] ?? null) && $p['duration_minutes'] > 0 ? (int) $p['duration_minutes'] : null,
                    'schedule_note' => self::text($p['schedule_note'] ?? null, 255),
                    'is_free' => (bool) ($p['is_free'] ?? false),
                    // Left null on purpose: "No published price", never "Free".
                    'fee_amount' => null,
                    'sort_order' => $index + 1,
                ]);
            }
            $result = $result === 'unchanged' ? 'updated' : $result;
        }
    }

    /**
     * A record someone has checked against a source belongs to the editors,
     * and so does one they deleted: re-importing must not resurrect it.
     */
    private function editorOwns(Temple $temple): bool
    {
        return $temple->trashed()
            || $temple->last_verified_at !== null
            || in_array($temple->verification_status, [VerificationStatus::Verified, VerificationStatus::Official], true);
    }

    /** Another state may already have a temple of the same name. */
    private function freeSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'temple';
        $slug = $base;
        if (Temple::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::slug($this->state->code);
        }
        for ($n = 2; Temple::withTrashed()->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    private function record(string $name, string $result, ?Temple $temple): string
    {
        $this->counts[$result]++;
        $this->log[] = ['name' => $name, 'result' => $result, 'temple' => $temple?->name];

        return $result;
    }

    private static function text(mixed $value, ?int $max = null): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '' || in_array(mb_strtolower($value), ['null', 'n/a', 'unknown', 'not available'], true)) {
            return null;
        }

        return $max ? mb_substr($value, 0, $max) : $value;
    }

    private static function url(mixed $value): ?string
    {
        $value = self::text($value, 255);

        return $value !== null && preg_match('#^https?://[^\s]+\.[^\s]+$#i', $value) ? $value : null;
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($value)) ? trim($value) : null;
    }

    /** @param array<string, mixed> $nearest */
    private static function howToReach(array $nearest): ?string
    {
        $lines = array_filter([
            ($v = self::text($nearest['railway'] ?? null)) ? 'Nearest railway station: '.$v : null,
            ($v = self::text($nearest['airport'] ?? null)) ? 'Nearest airport: '.$v : null,
            ($v = self::text($nearest['bus'] ?? null)) ? 'By bus: '.$v : null,
        ]);

        return $lines === [] ? null : implode("\n", $lines);
    }
}
