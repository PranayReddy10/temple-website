<?php

namespace App\Http\Controllers;

use App\Models\Deity;
use App\Models\State;
use App\Models\Temple;
use App\Models\TempleTiming;
use App\Support\DevotionalClock;
use App\Support\Seo;
use App\Support\TempleFinder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        $q = trim((string) $request->query('q'));
        $temples = Temple::query()->published()
            ->when($q !== '', fn ($query) => $query->search($q))
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->tap(self::cardDetails(...))
            ->orderByDesc('is_featured')->orderBy('name')
            ->paginate(48)->withQueryString();

        return view('site.temples', [
            'temples' => $temples,
            'states' => self::statesWithTemples(),
            'deities' => self::deitiesWithTemples(),
            'state' => null,
            'deity' => null,
            'q' => $q,
            // Search results are for people, not for the index.
            'noindex' => $q !== '',
            'heading' => $q !== '' ? 'Temples matching "'.$q.'"' : 'Temples',
            'title' => 'Hindu temples: timings, pujas and how to reach',
            'description' => 'Darshan timings, puja and seva details, dress code and directions for '.number_format($temples->total()).' temples across India.',
            'canonical' => Seo::url('temples'.($temples->currentPage() > 1 ? '?page='.$temples->currentPage() : '')),
        ]);
    }

    public function state(string $slug): View
    {
        $state = State::query()->where('slug', $slug)->first() ?? throw new NotFoundHttpException;

        $temples = Temple::query()->published()->where('state_id', $state->id)
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->tap(self::cardDetails(...))
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
            'canonical' => Seo::url('states/'.$state->slug.($temples->currentPage() > 1 ? '?page='.$temples->currentPage() : '')),
        ]);
    }

    public function deity(string $slug): View
    {
        $deity = Deity::query()->where('slug', $slug)->first() ?? throw new NotFoundHttpException;

        $temples = Temple::query()->published()->where('deity_id', $deity->id)
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->tap(self::cardDetails(...))
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
            'canonical' => Seo::url('deities/'.$deity->slug.($temples->currentPage() > 1 ? '?page='.$temples->currentPage() : '')),
        ]);
    }

    public function show(string $slug): View|RedirectResponse
    {
        $temple = Temple::query()->published()->where('slug', $slug)
            ->with([
                'deity', 'state', 'district', 'primaryPhoto', 'timings', 'aliases',
                'photos' => fn ($q) => $q->published()->limit(12),
                'pujas' => fn ($q) => $q->published(),
                'events' => fn ($q) => $q->published()->upcoming()->orderBy('starts_on')->limit(6),
                'closures' => fn ($q) => $q->upcoming(),
            ])
            ->first();

        if ($temple === null) {
            // A slug typed by hand or cut short: the temple it clearly means.
            $meant = TempleFinder::closest($slug);
            if ($meant !== null && $meant->slug !== $slug) {
                return redirect()->to(Seo::url('temples/'.$meant->slug), 301);
            }

            throw new NotFoundHttpException;
        }

        $place = collect([$temple->city, $temple->district?->name, $temple->state?->name])->filter()->unique()->implode(', ');
        $related = fn ($q) => $q->published()->whereKeyNot($temple->id)
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->orderByDesc('is_featured')->orderBy('name')->limit(6)
            ->get(['id', 'name', 'slug', 'city', 'deity_id', 'state_id', 'is_featured']);
        $about = $temple->translate('short_description', null, reviewedOnly: true);
        $dressCode = $temple->translate('dress_code', null, reviewedOnly: true);
        $today = (int) DevotionalClock::now()->dayOfWeek;
        // A Sat & Sun timing replaces the every-day one of its kind that day.
        $todays = TempleTiming::forDay($temple->timings, $today);
        $bookable = $temple->pujas->contains(fn ($p) => $p->isBookableInApp());

        return view('site.temple', [
            'temple' => $temple,
            'place' => $place,
            'about' => $about,
            'history' => $temple->translate('history', null, reviewedOnly: true),
            'significance' => $temple->translate('significance', null, reviewedOnly: true),
            'dressCode' => $dressCode,
            'todays' => $todays,
            'bookable' => $bookable,
            'aliases' => $temple->aliases->pluck('name')->filter()->unique()->reject(fn ($n) => strcasecmp($n, $temple->name) === 0)->values(),
            'faq' => self::faq($temple, $place, $dressCode, $bookable),
            'appLink' => Seo::appLink($temple->slug),
            'bookLink' => Seo::appLink($temple->slug, 'book'),
            'storeUrl' => Seo::storeUrl(),
            'ogType' => 'place',
            'title' => $temple->name.($place !== '' ? ', '.$place : '').': timings, pujas, how to reach',
            'description' => Str::limit(trim(strip_tags((string) ($about ?: 'Darshan timings, pujas and sevas, dress code and directions for '.$temple->name.($place !== '' ? ' in '.$place : '').'.'))), 158),
            'canonical' => Seo::url('temples/'.$temple->slug),
            'image' => Seo::absolute($temple->primaryPhoto?->mediumUrl() ?? $temple->photos->first()?->mediumUrl()),
            // Links on to more temples: what search engines follow, and what a
            // devotee planning a trip looks at next.
            'sameDeity' => $temple->deity_id ? $related(Temple::query()->where('deity_id', $temple->deity_id)) : collect(),
            'sameState' => $temple->state_id ? $related(Temple::query()->where('state_id', $temple->state_id)) : collect(),
        ]);
    }

    /**
     * Questions devotees ask about a temple, answered from what is on file.
     * Shown on the page and given to search engines as an FAQ, so the answer
     * can appear in the results themselves. Only questions with a real
     * answer are asked.
     *
     * @return array<int, array{q: string, a: string}>
     */
    public static function faq(Temple $temple, string $place, ?string $dressCode, bool $bookable): array
    {
        $name = $temple->name;
        $faq = [];

        $hours = $temple->timings->map(fn ($t) => trim(($t->label ?: $t->kind?->getLabel()).' ('.$t->dayLabel().'): '.$t->window()))->filter()->take(6);
        if ($hours->isNotEmpty()) {
            $faq[] = ['q' => 'What are the darshan timings of '.$name.'?', 'a' => $hours->implode('; ').'. Timings can change on festival days; confirm with the temple before travelling.'];
        }

        if ($place !== '' || $temple->address) {
            $faq[] = ['q' => 'Where is '.$name.' and how do I reach it?', 'a' => trim(collect([$temple->address, $place, $temple->pincode])->filter()->implode(', '))
                .'. '.($temple->hasCoordinates() ? 'Use the directions link on this page or in the '.config('brand.name').' app for the route.' : 'Search the temple\'s name in Google Maps for the route.')];
        }

        if ($temple->pujas->isNotEmpty()) {
            $list = $temple->pujas->take(6)->map(fn ($p) => $p->name.(filled($p->feeLabel()) ? ' ('.$p->feeLabel().')' : ''))->implode(', ');
            $faq[] = ['q' => 'Which pujas and sevas can be done at '.$name.'?', 'a' => $list.'.'
                .($bookable ? ' Some can be booked and paid for online in the '.config('brand.name').' app.' : '')];
        }

        if (filled($dressCode)) {
            $faq[] = ['q' => 'Is there a dress code at '.$name.'?', 'a' => trim(strip_tags((string) $dressCode))];
        }

        if (filled($temple->mobile_policy) || filled($temple->photography_policy)) {
            $faq[] = ['q' => 'Are mobile phones and photography allowed at '.$name.'?', 'a' => collect([$temple->mobile_policy ? 'Mobile phones: '.$temple->mobile_policy : null, $temple->photography_policy ? 'Photography: '.$temple->photography_policy : null])->filter()->implode('. ').'.'];
        }

        if (filled($temple->contact_phone)) {
            $faq[] = ['q' => 'What is the phone number of '.$name.'?', 'a' => 'The temple office can be reached on '.$temple->contact_phone.'.'];
        }

        return $faq;
    }

    /** What each card in a list shows beyond the name: how many sevas, and whether one books in the app. */
    public static function cardDetails(Builder $query): void
    {
        $query->withCount(['pujas' => fn ($q) => $q->published()])
            ->withExists(['pujas as books_in_app' => fn ($q) => $q->published()->where('app_booking_enabled', true)]);
    }

    /** @return Collection<int, State> */
    public static function statesWithTemples()
    {
        return State::query()
            ->whereHas('temples', fn ($q) => $q->published())
            ->withCount(['temples' => fn ($q) => $q->published()])
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    /** @return Collection<int, Deity> */
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
