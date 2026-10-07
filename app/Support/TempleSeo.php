<?php

namespace App\Support;

use App\Http\Controllers\PublicTempleController;
use App\Models\Temple;
use App\Models\TempleTiming;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What a temple's public page says to a search engine, from what is on file.
 *
 * Someone searching a temple's name wants its timings, how to get there and
 * what it looks like, so the title and description lead with those, and the
 * page carries a short written introduction and the temples around it. Every
 * sentence comes from the temple's own record: nothing is invented, so a
 * sparse listing gets a short page rather than a padded one.
 */
final class TempleSeo
{
    /** Google shows about this many characters of a title. */
    protected const TITLE_MAX = 65;

    /** "Sri Rama Temple, Bhadrachalam: Timings, Photos & How to Reach". */
    public static function title(Temple $temple, string $name): string
    {
        $town = self::town($temple);
        $suffix = ': Timings, Photos & How to Reach';
        $named = $town !== null && stripos($name, $town) === false ? $name.', '.$town : $name;

        foreach ([$named.$suffix, $named.': Timings & How to Reach', $named.' Timings', $name.' Timings'] as $title) {
            if (mb_strlen($title) <= self::TITLE_MAX) {
                return $title;
            }
        }

        return $name;
    }

    /**
     * The search result's snippet: where it is, today's hours, and what else
     * the page has. Leads with the temple's own description when it has one.
     *
     * @param  Collection<int, TempleTiming>  $todays
     */
    public static function description(Temple $temple, string $name, string $place, ?string $about, Collection $todays, int $photos): string
    {
        $about = trim(strip_tags((string) $about));
        $hours = $todays->map(fn (TempleTiming $t): string => $t->window())->filter(fn ($w) => $w !== 'Not published')->unique()->take(2)->implode(', ');

        $facts = collect([
            $hours !== '' ? 'Darshan timings: '.$hours.'.' : null,
            collect([
                $temple->pujas->count() > 0 ? $temple->pujas->count().' '.Str::plural('seva', $temple->pujas->count()) : null,
                $photos > 0 ? $photos.' '.Str::plural('photo', $photos) : null,
                'dress code',
                'directions',
            ])->filter()->implode(', ').'.',
        ])->filter()->implode(' ');

        $lead = $about !== '' ? Str::limit($about, 90, '…') : $name.($place !== '' ? ' in '.$place : '').'.';

        return Str::limit($lead.' '.Str::ucfirst($facts), 158);
    }

    /**
     * A few plain sentences about the temple, built from its record, for the
     * top of the page. Unique to each temple because each fact is.
     *
     * @param  Collection<int, Temple>  $nearby  with distance_km
     * @return array<int, string>
     */
    public static function introduction(Temple $temple, string $name, ?string $deity, Collection $nearby): array
    {
        $out = [];

        $where = collect([
            $temple->city,
            $temple->district?->name ? $temple->district->name.' district' : null,
            $temple->state?->name,
        ])->filter()->unique()->implode(', ');

        $out[] = $name.' is a Hindu temple'
            .(filled($deity) ? ' dedicated to '.$deity : '')
            .($where !== '' ? ' in '.$where : '')
            .(filled($temple->pincode) ? ' ('.$temple->pincode.')' : '').'.';

        $general = $temple->timings->filter(fn (TempleTiming $t): bool => in_array($t->kind?->value, ['general', 'darshan'], true) && $t->isEveryDay());
        $opens = $general->pluck('opens_at')->filter()->sort()->first();
        $closes = $general->pluck('closes_at')->filter()->sort()->last();
        if ($opens !== null && $closes !== null) {
            $out[] = 'It is usually open for darshan from '.Clock::twelve($opens).' to '.Clock::twelve($closes)
                .($general->count() > 1 ? ', with a break during the day' : '').'.';
        }

        $pujas = $temple->pujas;
        if ($pujas->isNotEmpty()) {
            $online = $pujas->filter(fn ($p) => $p->isBookableInApp())->count();
            $out[] = $pujas->count().' '.Str::plural('puja or seva', $pujas->count()).' '.($pujas->count() === 1 ? 'is' : 'are').' listed here, such as '
                .$pujas->take(3)->pluck('name')->implode(', ')
                .($online > 0 ? '; '.$online.' can be booked online' : '').'.';
        }

        if ($temple->events->isNotEmpty()) {
            $out[] = 'Coming up: '.$temple->events->take(2)->pluck('title')->implode(' and ').'.';
        }

        if ($nearby->isNotEmpty()) {
            $out[] = 'Other temples nearby include '.self::sentenceList($nearby->take(3)->map(
                fn (Temple $t): string => $t->localName().' ('.self::km((float) $t->distance_km).')'
            )->all()).'.';
        }

        return $out;
    }

    /**
     * Published temples around this one, nearest first, with distance_km.
     *
     * @return Collection<int, Temple>
     */
    public static function nearby(Temple $temple, int $limit = 8, float $radiusKm = 60): Collection
    {
        if (! $temple->hasCoordinates()) {
            return collect();
        }

        $lat = (float) $temple->latitude;
        $lng = (float) $temple->longitude;

        return Temple::query()->published()
            ->whereKeyNot($temple->getKey())
            ->withinBoundingBox($lat, $lng, $radiusKm)
            ->withDistanceFrom($lat, $lng)
            ->with(['primaryPhoto', 'state:id,name', 'translations'])
            ->limit($limit)
            ->get()
            ->filter(fn (Temple $t): bool => (float) $t->distance_km <= $radiusKm)
            ->values();
    }

    /** "3.2 km", "18 km". */
    public static function km(float $km): string
    {
        return ($km < 10 ? number_format($km, 1) : number_format($km, 0)).' km';
    }

    /** The town to put beside the name: the city, else the district. */
    public static function town(Temple $temple): ?string
    {
        $town = $temple->city ?: $temple->district?->name;

        return filled($town) ? (string) $town : null;
    }

    /** "Shiva temples" phrase for a deity, as the directory uses. */
    public static function deityPhrase(?string $deity): ?string
    {
        return filled($deity) ? PublicTempleController::deityPhrase((string) $deity) : null;
    }

    /** @param  array<int, string>  $items */
    protected static function sentenceList(array $items): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }
        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
