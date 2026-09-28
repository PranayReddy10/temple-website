<?php

namespace App\Http\Controllers;

use App\Models\State;
use App\Models\Temple;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The temple pages search engines index: a directory, one page per state,
 * one per temple. Published temples only; drafts are not public anywhere.
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
            'state' => null,
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
            'state' => $state,
            'title' => 'Temples in '.$state->name.': timings, pujas and how to reach',
            'description' => 'Darshan timings, pujas and sevas, dress code and directions for '.number_format($temples->total()).' temples in '.$state->name.'.',
            'canonical' => \App\Support\Seo::url('states/'.$state->slug.($temples->currentPage() > 1 ? '?page='.$temples->currentPage() : '')),
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
}
