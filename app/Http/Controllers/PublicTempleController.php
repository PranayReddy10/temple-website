<?php

namespace App\Http\Controllers;

use App\Models\Deity;
use App\Models\State;
use App\Models\Temple;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The temple pages search engines index: a directory, one page per state
 * and per deity, one per temple. Published temples only; drafts are not public anywhere.
 */
class PublicTempleController extends Controller
{
    public function index(Request $request): View
    {
        $temples = Temple::query()->published()
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->orderByDesc('is_featured')->orderBy('name')
            ->paginate(48);

        return view('site.temples', [
            'temples' => $temples,
            'states' => self::statesWithTemples(),
            'deities' => self::deitiesWithTemples(),
            'state' => null,
            'deity' => null,
            'heading' => 'Temples',
            'title' => 'Hindu temples: timings, pujas and how to reach',
            'description' => 'Darshan timings, puja and seva details, dress code and directions for '.number_format($temples->total()).' temples across India.',
            'canonical' => \App\Support\Seo::url('temples'.($temples->currentPage() > 1 ? '?page='.$temples->currentPage() : '')),
        ]);
    }

    public function state(string $slug): View
    {
        $state = State::query()->where('slug', $slug)->first() ?? throw new NotFoundHttpException;

        $temples = Temple::query()->published()->where('state_id', $state->id)
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->orderByDesc('is_featured')->orderBy('name')
            ->paginate(48);

        if ($temples->total() === 0) {
            throw new NotFoundHttpException;
        }

        return view('site.temples', [
            'temples' => $temples,
            'states' => self::statesWithTemples(),
            'deities' => self::deitiesWithTemples(),
            'state' => $state,
            'deity' => null,
            'heading' => 'Temples in '.$state->name,
            'title' => 'Temples in '.$state->name.': timings, pujas and how to reach',
            'description' => 'Darshan timings, pujas and sevas, dress code and directions for '.number_format($temples->total()).' temples in '.$state->name.'.',
            'canonical' => \App\Support\Seo::url('states/'.$state->slug.($temples->currentPage() > 1 ? '?page='.$temples->currentPage() : '')),
        ]);
    }

    public function deity(string $slug): View
    {
        $deity = Deity::query()->where('slug', $slug)->first() ?? throw new NotFoundHttpException;

        $temples = Temple::query()->published()->where('deity_id', $deity->id)
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->orderByDesc('is_featured')->orderBy('name')
            ->paginate(48);

        if ($temples->total() === 0) {
            throw new NotFoundHttpException;
        }

        $name = self::deityPhrase($deity->name);

        return view('site.temples', [
            'temples' => $temples,
            'states' => self::statesWithTemples(),
            'deities' => self::deitiesWithTemples(),
            'state' => null,
            'deity' => $deity,
            'heading' => $name.' temples in India',
            'intro' => Str::limit(trim(strip_tags((string) $deity->description)), 400),
            'title' => $name.' temples in India: timings, pujas and how to reach',
            'description' => 'Darshan timings, pujas and sevas, dress code and directions for '.number_format($temples->total()).' '.$name.' '.Str::plural('temple', $temples->total()).' across India.',
            'canonical' => \App\Support\Seo::url('deities/'.$deity->slug.($temples->currentPage() > 1 ? '?page='.$temples->currentPage() : '')),
        ]);
    }

    public function show(string $slug): View
    {
        $temple = Temple::query()->published()->where('slug', $slug)
            ->with([
                'deity', 'state', 'district', 'primaryPhoto', 'timings',
                'photos' => fn ($q) => $q->published()->limit(8),
                'pujas' => fn ($q) => $q->published(),
            ])
            ->first() ?? throw new NotFoundHttpException;

        $place = collect([$temple->city, $temple->district?->name, $temple->state?->name])->filter()->unique()->implode(', ');
        $related = fn ($q) => $q->published()->whereKeyNot($temple->id)
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->orderByDesc('is_featured')->orderBy('name')->limit(6)
            ->get(['id', 'name', 'slug', 'city', 'deity_id', 'state_id', 'is_featured']);
        $about = $temple->translate('short_description', null, reviewedOnly: true);

        return view('site.temple', [
            'temple' => $temple,
            'place' => $place,
            'about' => $about,
            'history' => $temple->translate('history', null, reviewedOnly: true),
            'significance' => $temple->translate('significance', null, reviewedOnly: true),
            'dressCode' => $temple->translate('dress_code', null, reviewedOnly: true),
            'title' => $temple->name.($place !== '' ? ', '.$place : '').': timings, pujas, how to reach',
            'description' => Str::limit(trim(strip_tags((string) ($about ?: 'Darshan timings, pujas and sevas, dress code and directions for '.$temple->name.($place !== '' ? ' in '.$place : '').'.'))), 158),
            'canonical' => \App\Support\Seo::url('temples/'.$temple->slug),
            'image' => \App\Support\Seo::absolute($temple->primaryPhoto?->mediumUrl() ?? $temple->photos->first()?->mediumUrl()),
            // Links on to more temples: what search engines follow, and what a
            // devotee planning a trip looks at next.
            'sameDeity' => $temple->deity_id ? $related(Temple::query()->where('deity_id', $temple->deity_id)) : collect(),
            'sameState' => $temple->state_id ? $related(Temple::query()->where('state_id', $temple->state_id)) : collect(),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, State> */
    public static function statesWithTemples()
    {
        return State::query()
            ->whereHas('temples', fn ($q) => $q->published())
            ->withCount(['temples' => fn ($q) => $q->published()])
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    /** @return \Illuminate\Support\Collection<int, Deity> */
    public static function deitiesWithTemples()
    {
        return Deity::query()
            ->whereNotNull('slug')
            ->whereHas('temples', fn ($q) => $q->published())
            ->withCount(['temples' => fn ($q) => $q->published()])
            ->orderByDesc('temples_count')->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    /** "Lord Shiva" → "Shiva", for "Shiva temples in India". */
    public static function deityPhrase(string $name): string
    {
        return trim(preg_replace('/^(lord|sri|shri|sree|goddess|devi)\s+/i', '', $name) ?: $name);
    }
}
