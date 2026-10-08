<?php

namespace App\Http\Controllers\Site;

use App\Enums\YatraStatus;
use App\Http\Controllers\Controller;
use App\Models\Temple;
use App\Models\Yatra;
use App\Models\YatraStop;
use App\Support\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The yatra planner on the website: the same trips as the app's Yatra tab
 * (yatras and yatra_stops), so a plan made on one shows on the other.
 * Temples are grouped by day and ordered within it; the route opens in
 * Google Maps.
 */
class YatraController extends Controller
{
    public function index(Request $request): View
    {
        return $this->page('site.yatra.index', 'Yatra planner', [
            'yatras' => $request->user()->yatras()->withCount('stops')->latest()->get(),
            'statuses' => YatraStatus::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $yatra = new Yatra($this->validated($request));
        $yatra->devotee_id = $request->user()->getKey();
        $yatra->save();

        // Started from a temple's "Add to yatra": that temple goes in first.
        if (($slug = $request->input('temple')) && ($temple = $this->publishedTemple((string) $slug))) {
            $this->addTemple($yatra, $temple, 1);
        }

        return redirect()->to(Seo::url('account/yatras/'.$yatra->getKey()))->with('status', 'Your yatra is ready. Add temples below.');
    }

    public function show(Request $request, int $yatra): View
    {
        $trip = $this->mine($request, $yatra);
        $trip->load(['stops.temple:id,slug,name,city,state_id,latitude,longitude', 'stops.temple.state:id,name', 'stops.temple.primaryPhoto', 'stops.temple.timings']);

        $q = trim((string) $request->query('q'));
        $found = $q === '' ? collect() : Temple::query()->published()->search($q)
            ->whereNotIn('id', $trip->stops->pluck('temple_id'))
            ->with('state:id,name')->limit(8)->get(['id', 'slug', 'name', 'city', 'state_id']);

        $days = $trip->stops->groupBy('day_number')->sortKeys();

        return $this->page('site.yatra.show', $trip->title, [
            'yatra' => $trip,
            'days' => $days,
            'legs' => $this->legs($trip->stops),
            'mapsUrl' => $this->mapsUrl($trip->stops),
            'q' => $q,
            'found' => $found,
            'nextDay' => max(1, (int) $days->keys()->max()),
            'statuses' => YatraStatus::cases(),
        ]);
    }

    public function update(Request $request, int $yatra): RedirectResponse
    {
        $trip = $this->mine($request, $yatra);
        $trip->update($this->validated($request));

        return redirect()->to(Seo::url('account/yatras/'.$trip->getKey()))->with('status', 'Saved.');
    }

    public function destroy(Request $request, int $yatra): RedirectResponse
    {
        $this->mine($request, $yatra)->delete();

        return redirect()->to(Seo::url('account/yatras'))->with('status', 'Yatra deleted.');
    }

    /** Add a temple, from the planner's search or from a temple page. */
    public function addStop(Request $request, int $yatra): RedirectResponse
    {
        $trip = $this->mine($request, $yatra);
        $validated = $request->validate([
            'temple' => ['required', 'string', 'max:200'],
            'day_number' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);
        $temple = $this->publishedTemple($validated['temple']) ?? throw new NotFoundHttpException;

        $this->addTemple($trip, $temple, (int) ($validated['day_number'] ?? max(1, (int) $trip->stops()->max('day_number'))));

        $back = $request->input('back') === 'temple' ? Seo::url('temples/'.$temple->slug) : Seo::url('account/yatras/'.$trip->getKey());

        return redirect()->to($back)->with('status', $temple->name.' is in '.$trip->title.'.');
    }

    /** Up or down within the day, or to another day. */
    public function moveStop(Request $request, int $yatra, int $stop): RedirectResponse
    {
        $trip = $this->mine($request, $yatra);
        $item = $trip->stops()->whereKey($stop)->first() ?? throw new NotFoundHttpException;
        $validated = $request->validate([
            'to' => ['nullable', Rule::in(['up', 'down'])],
            'day_number' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        DB::transaction(function () use ($trip, $item, $validated): void {
            if (isset($validated['day_number']) && (int) $validated['day_number'] !== $item->day_number) {
                $item->update([
                    'day_number' => (int) $validated['day_number'],
                    'sort_order' => (int) $trip->stops()->where('day_number', $validated['day_number'])->max('sort_order') + 1,
                ]);
            } elseif (isset($validated['to'])) {
                $day = $trip->stops()->where('day_number', $item->day_number)->get()->values();
                $i = $day->search(fn (YatraStop $s) => $s->is($item));
                $j = $validated['to'] === 'up' ? $i - 1 : $i + 1;
                if ($i !== false && isset($day[$j])) {
                    $ordered = $day->all();
                    [$ordered[$i], $ordered[$j]] = [$ordered[$j], $ordered[$i]];
                    $this->renumber(collect($ordered));
                }
            }
        });

        return redirect()->to(Seo::url('account/yatras/'.$trip->getKey()).'#stop-'.$item->getKey());
    }

    public function removeStop(Request $request, int $yatra, int $stop): RedirectResponse
    {
        $trip = $this->mine($request, $yatra);
        $trip->stops()->whereKey($stop)->delete();

        return redirect()->to(Seo::url('account/yatras/'.$trip->getKey()))->with('status', 'Removed from the yatra.');
    }

    /**
     * Each day's temples in the order that drives least: from the day's
     * first temple, always on to the nearest one not yet visited.
     */
    public function optimise(Request $request, int $yatra): RedirectResponse
    {
        $trip = $this->mine($request, $yatra);
        $trip->load('stops.temple:id,latitude,longitude');

        DB::transaction(function () use ($trip): void {
            foreach ($trip->stops->groupBy('day_number') as $stops) {
                $placed = $stops->filter(fn (YatraStop $s) => $s->temple?->hasCoordinates())->values();
                $rest = $stops->reject(fn (YatraStop $s) => $s->temple?->hasCoordinates());
                if ($placed->count() < 3) {
                    continue;
                }
                $route = collect([$placed->shift()]);
                while ($placed->isNotEmpty()) {
                    $from = $route->last()->temple;
                    $k = $placed->keys()->sortBy(fn ($k) => self::km($from, $placed[$k]->temple))->first();
                    $route->push($placed->pull($k));
                }
                $this->renumber($route->concat($rest));
            }
        });

        return redirect()->to(Seo::url('account/yatras/'.$trip->getKey()))->with('status', 'Each day is now in the shortest order.');
    }

    /** A temple page's "Add to yatra": into one of the devotee's yatras. */
    public function addFromTemple(Request $request, string $slug): RedirectResponse
    {
        $temple = $this->publishedTemple($slug) ?? throw new NotFoundHttpException;
        $validated = $request->validate(['yatra' => ['required', 'integer']]);
        $trip = $this->mine($request, (int) $validated['yatra']);

        $this->addTemple($trip, $temple, max(1, (int) $trip->stops()->max('day_number')));

        return redirect()->to(Seo::url('temples/'.$temple->slug))->with('status', 'Added to '.$trip->title.'. Open it under Yatra planner.');
    }

    protected function addTemple(Yatra $trip, Temple $temple, int $day): void
    {
        $trip->stops()->updateOrCreate(
            ['temple_id' => $temple->getKey()],
            ['day_number' => $day, 'sort_order' => (int) $trip->stops()->where('day_number', $day)->max('sort_order') + 1],
        );
    }

    /** @param  Collection<int, YatraStop>  $stops */
    protected function renumber(Collection $stops): void
    {
        foreach ($stops->values() as $n => $stop) {
            if ($stop->sort_order !== $n + 1) {
                $stop->forceFill(['sort_order' => $n + 1])->save();
            }
        }
    }

    /**
     * Distance from each stop to the next on the same day, by stop id.
     *
     * @param  Collection<int, YatraStop>  $stops
     * @return array<int, float>
     */
    protected function legs(Collection $stops): array
    {
        $legs = [];
        foreach ($stops->groupBy('day_number') as $day) {
            $day = $day->values();
            for ($i = 1; $i < $day->count(); $i++) {
                if ($day[$i - 1]->temple?->hasCoordinates() && $day[$i]->temple?->hasCoordinates()) {
                    $legs[$day[$i]->getKey()] = self::km($day[$i - 1]->temple, $day[$i]->temple);
                }
            }
        }

        return $legs;
    }

    /**
     * The whole route in Google Maps: the last temple as the destination,
     * the others as stops on the way (Maps takes up to nine).
     *
     * @param  Collection<int, YatraStop>  $stops
     */
    protected function mapsUrl(Collection $stops): ?string
    {
        $points = $stops->map(fn (YatraStop $s) => $s->temple)->filter(fn ($t) => $t?->hasCoordinates())
            ->map(fn (Temple $t) => $t->latitude.','.$t->longitude)->values();
        if ($points->isEmpty()) {
            return null;
        }

        return 'https://www.google.com/maps/dir/?'.http_build_query(array_filter([
            'api' => 1,
            'destination' => $points->last(),
            'waypoints' => $points->count() > 1 ? $points->slice(0, -1)->take(9)->implode('|') : null,
            'travelmode' => 'driving',
        ]));
    }

    /** Straight-line distance between two temples, in km. */
    public static function km(Temple $a, Temple $b): float
    {
        $r = fn ($d) => deg2rad((float) $d);
        $h = sin(($r($b->latitude) - $r($a->latitude)) / 2) ** 2
            + cos($r($a->latitude)) * cos($r($b->latitude)) * sin(($r($b->longitude) - $r($a->longitude)) / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($h)));
    }

    protected function publishedTemple(string $slug): ?Temple
    {
        return Temple::query()->published()->where('slug', $slug)->first();
    }

    protected function mine(Request $request, int $id): Yatra
    {
        return $request->user()->yatras()->whereKey($id)->first() ?? throw new NotFoundHttpException;
    }

    /** @return array<string, mixed> the details, leaving the status alone when none is chosen */
    protected function validated(Request $request): array
    {
        $data = $request->validate($this->rules());
        if (blank($data['status'] ?? null)) {
            unset($data['status']);
        }

        return $data;
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::enum(YatraStatus::class)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function page(string $view, string $title, array $data): View
    {
        return view($view, $data + [
            'title' => $title,
            'description' => 'Plan a temple yatra: temples by day, the route and timings.',
            'canonical' => Seo::url(request()->path()),
            'noindex' => true,
        ]);
    }
}
