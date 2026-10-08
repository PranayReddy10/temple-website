<?php

namespace App\Http\Controllers;

use App\Models\Deity;
use App\Models\District;
use App\Models\State;
use App\Models\Temple;
use App\Models\TempleTiming;
use App\Support\DevotionalClock;
use App\Support\Seo;
use App\Support\SiteLocale;
use App\Support\TempleFinder;
use App\Support\TempleSeo;
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

    public function state(Request $request, string $slug): View
    {
        $state = State::query()->where('slug', $slug)->first() ?? throw new NotFoundHttpException;
        // A search within the state: its temples matching a name or town.
        $q = trim((string) $request->query('q'));

        $temples = Temple::query()->published()->where('state_id', $state->id)
            ->when($q !== '', fn ($query) => $query->search($q))
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->tap(self::cardDetails(...))
            ->orderByDesc('is_featured')->orderBy('name')
            ->paginate(48)
            ->withQueryString();

        if ($temples->total() === 0 && $q === '') {
            throw new NotFoundHttpException;
        }

        return view('site.temples', [
            'temples' => $temples,
            'states' => self::statesWithTemples(),
            'deities' => self::deitiesWithTemples(),
            'state' => $state,
            'districts' => self::districtsWithTemples($state),
            'deity' => null,
            'q' => $q,
            'noindex' => $q !== '',
            'heading' => $q !== '' ? 'Temples in '.$state->name.' matching "'.$q.'"' : 'Temples in '.$state->name,
            'title' => 'Temples in '.$state->name.': timings, pujas and how to reach',
            'description' => 'Darshan timings, pujas and sevas, dress code and directions for '.number_format($temples->total()).' temples in '.$state->name.'.',
            'canonical' => Seo::url('states/'.$state->slug.($temples->currentPage() > 1 ? '?page='.$temples->currentPage() : '')),
        ]);
    }

    public function deity(Request $request, string $slug): View
    {
        $deity = Deity::query()->where('slug', $slug)->first() ?? throw new NotFoundHttpException;
        $q = trim((string) $request->query('q'));

        $temples = Temple::query()->published()->where('deity_id', $deity->id)
            ->when($q !== '', fn ($query) => $query->search($q))
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->tap(self::cardDetails(...))
            ->orderByDesc('is_featured')->orderBy('name')
            ->paginate(48)
            ->withQueryString();

        if ($temples->total() === 0 && $q === '') {
            throw new NotFoundHttpException;
        }

        $name = self::deityPhrase($deity->name);

        return view('site.temples', [
            'temples' => $temples,
            'states' => self::statesWithTemples(),
            'deities' => self::deitiesWithTemples(),
            'state' => null,
            'deity' => $deity,
            'q' => $q,
            'noindex' => $q !== '',
            'heading' => $q !== '' ? $name.' temples matching "'.$q.'"' : $name.' temples in India',
            'intro' => Str::limit(trim(strip_tags((string) $deity->description)), 400),
            'title' => $name.' temples in India: timings, pujas and how to reach',
            'description' => 'Darshan timings, pujas and sevas, dress code and directions for '.number_format($temples->total()).' '.$name.' '.Str::plural('temple', $temples->total()).' across India.',
            'canonical' => Seo::url('deities/'.$deity->slug.($temples->currentPage() > 1 ? '?page='.$temples->currentPage() : '')),
        ]);
    }

    public function show(string $slug): View|RedirectResponse
    {
        return $this->render($slug, null);
    }

    /**
     * The same page in Telugu, Hindi, Tamil or Kannada: /te/temples/{slug}.
     * Only for a temple whose name has a reviewed translation in that
     * language, so each language version is a real page and never the
     * English one again under another address.
     */
    public function showLocalized(string $locale, string $slug): View|RedirectResponse
    {
        return $this->render($slug, $locale);
    }

    /** A temple not yet published, as its page will look (signed link, not indexed). */
    public function preview(int $temple): View|RedirectResponse
    {
        $slug = Temple::query()->whereKey($temple)->value('slug') ?? throw new NotFoundHttpException;

        $page = $this->render($slug, null, preview: true);

        return $page instanceof View ? $page->with('noindex', true) : $page;
    }

    protected function render(string $slug, ?string $locale, bool $preview = false): View|RedirectResponse
    {
        $temple = Temple::query()->when(! $preview, fn ($q) => $q->published())->where('slug', $slug)
            ->with([
                'deity', 'state', 'district', 'primaryPhoto', 'timings', 'aliases', 'translations', 'deity.translations',
                'photos' => fn ($q) => $q->published()->orderByDesc('is_primary')->orderBy('sort_order')->limit(30),
                'media', 'deity.media',
                'pujas' => fn ($q) => $q->published(),
                'events' => fn ($q) => $q->published()->upcoming()->orderBy('starts_on')->limit(6),
                'closures' => fn ($q) => $q->upcoming(),
            ])
            ->first();

        if ($temple === null) {
            // A slug typed by hand or cut short: the temple it clearly means.
            $meant = TempleFinder::closest($slug);
            if ($meant !== null && $meant->slug !== $slug) {
                return redirect()->to(SiteLocale::templeUrl($meant, $locale), 301);
            }

            throw new NotFoundHttpException;
        }

        $languages = SiteLocale::languagesOf($temple);
        if ($locale !== null && ! in_array($locale, $languages, true)) {
            // Not translated (yet): the English page is the one to read.
            return redirect()->to(SiteLocale::templeUrl($temple, null), 302);
        }

        SiteLocale::use($locale);
        $lang = $locale ?? 'en';
        $name = $locale !== null ? $temple->localName($locale) : $temple->name;
        $deityName = $temple->deity?->translate('name', $lang, reviewedOnly: true);

        $place = collect([$temple->city, $temple->district?->name, $temple->state?->name])->filter()->unique()->implode(', ');
        $related = fn ($q) => $q->published()->whereKeyNot($temple->id)
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto', 'translations'])
            ->orderByDesc('is_featured')->orderBy('name')->limit(6)
            ->get(['id', 'name', 'slug', 'city', 'deity_id', 'state_id', 'is_featured']);
        $about = $temple->translate('short_description', $lang, reviewedOnly: true);
        $dressCode = $temple->translate('dress_code', $lang, reviewedOnly: true);
        $today = (int) DevotionalClock::now()->dayOfWeek;
        // A Sat & Sun timing replaces the every-day one of its kind that day.
        $todays = TempleTiming::forDay($temple->timings, $today);
        $bookable = $temple->pujas->contains(fn ($p) => $p->isBookableInApp());
        $nearby = TempleSeo::nearby($temple);
        $photoCount = $temple->photos->count();
        $canonical = SiteLocale::templeUrl($temple, $locale);

        return view('site.temple', [
            'temple' => $temple,
            'name' => $name,
            'deityName' => $deityName,
            'place' => $place,
            'about' => $about,
            'history' => $temple->translate('history', $lang, reviewedOnly: true),
            'significance' => $temple->translate('significance', $lang, reviewedOnly: true),
            'entryRules' => $temple->translate('entry_rules', $lang, reviewedOnly: true),
            'queueInfo' => $temple->translate('queue_information', $lang, reviewedOnly: true),
            'dressCode' => $dressCode,
            'todays' => $todays,
            'bookable' => $bookable,
            'nearby' => $nearby,
            'intro' => TempleSeo::introduction($temple, $name, $deityName, $nearby),
            'aliases' => $temple->aliases->pluck('name')->filter()->unique()->reject(fn ($n) => strcasecmp($n, $temple->name) === 0 || $n === $name)->values(),
            'faq' => $locale === null ? self::faq($temple, $place, $dressCode, $bookable, $nearby) : [],
            'appLink' => Seo::appLink($temple->slug),
            // Booking and the hundi happen on the website itself.
            'bookLink' => Seo::url('temples/'.$temple->slug.'/sevas'),
            // Hundi offerings, where the temple takes them and its payout
            // account is verified: the same rule as the app.
            'donateLink' => $temple->accepts_donations && $temple->canCollectPayments() ? Seo::url('temples/'.$temple->slug.'/donate') : null,
            'saved' => ($devotee = auth('devotee_web')->user()) !== null && $devotee->savedTemples()->whereKey($temple->getKey())->exists(),
            'storeUrl' => Seo::storeUrl(),
            'ogType' => 'place',
            'locale' => $lang,
            'alternates' => SiteLocale::alternates($temple, $languages),
            'title' => $locale === null
                ? TempleSeo::title($temple, $name)
                : $name.($temple->city ? ', '.$temple->city : '').': '.__('Timings, Photos & How to Reach'),
            'description' => $locale === null
                ? TempleSeo::description($temple, $name, $place, $about, $todays, $photoCount)
                : Str::limit(trim(strip_tags((string) ($about ?: $name.($place !== '' ? ', '.$place : '')))), 158),
            'canonical' => $canonical,
            'updatedAt' => $temple->updated_at,
            'image' => Seo::absolute($temple->primaryPhoto?->mediumUrl() ?? $temple->photos->first()?->mediumUrl()),
            // Links on to more temples: what search engines follow, and what a
            // devotee planning a trip looks at next.
            'sameDeity' => $temple->deity_id ? $related(Temple::query()->where('deity_id', $temple->deity_id)) : collect(),
            'sameState' => $temple->state_id ? $related(Temple::query()->where('state_id', $temple->state_id)) : collect(),
        ]);
    }

    /**
     * A district's temples: "Temples in Bhadradri Kothagudem, Telangana",
     * which is how people look for the temples of a place.
     */
    public function district(Request $request, string $stateSlug, string $districtSlug): View
    {
        $state = State::query()->where('slug', $stateSlug)->first() ?? throw new NotFoundHttpException;
        $district = District::query()->where('state_id', $state->id)->where('slug', $districtSlug)->first() ?? throw new NotFoundHttpException;
        $q = trim((string) $request->query('q'));

        $temples = Temple::query()->published()->where('district_id', $district->id)
            ->when($q !== '', fn ($query) => $query->search($q))
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->tap(self::cardDetails(...))
            ->orderByDesc('is_featured')->orderBy('name')
            ->paginate(48)
            ->withQueryString();

        if ($temples->total() === 0 && $q === '') {
            throw new NotFoundHttpException;
        }

        $towns = Temple::query()->published()->where('district_id', $district->id)
            ->whereNotNull('city')->distinct()->orderBy('city')->limit(30)->pluck('city');

        return view('site.temples', [
            'temples' => $temples,
            'states' => collect(),
            'deities' => collect(),
            'districts' => self::districtsWithTemples($state),
            'state' => $state,
            'district' => $district,
            'deity' => null,
            'q' => $q,
            'noindex' => $q !== '',
            'heading' => $q !== '' ? 'Temples in '.$district->name.' matching "'.$q.'"' : 'Temples in '.$district->name.' district, '.$state->name,
            'intro' => $towns->isNotEmpty() ? 'Temples in '.$towns->take(12)->implode(', ').($towns->count() > 12 ? ' and more' : '').': darshan timings, pujas and sevas, photos and the way there.' : null,
            'title' => 'Temples in '.$district->name.', '.$state->name.': Timings & How to Reach',
            'description' => number_format($temples->total()).' '.Str::plural('temple', $temples->total()).' in '.$district->name.' district, '.$state->name.': darshan timings, pujas and sevas, photos, dress code and directions.',
            'canonical' => Seo::url('states/'.$state->slug.'/'.$district->slug.($temples->currentPage() > 1 ? '?page='.$temples->currentPage() : '')),
        ]);
    }

    /** @return Collection<int, District> a state's districts that have published temples */
    public static function districtsWithTemples(State $state)
    {
        return District::query()
            ->where('state_id', $state->id)
            ->whereNotNull('slug')
            ->whereHas('temples', fn ($q) => $q->published())
            ->withCount(['temples' => fn ($q) => $q->published()])
            ->orderBy('name')
            ->get(['id', 'state_id', 'name', 'slug']);
    }

    /**
     * Questions devotees ask about a temple, answered from what is on file.
     * Shown on the page and given to search engines as an FAQ, so the answer
     * can appear in the results themselves. Only questions with a real
     * answer are asked.
     *
     * @return array<int, array{q: string, a: string}>
     */
    public static function faq(Temple $temple, string $place, ?string $dressCode, bool $bookable, ?Collection $nearby = null): array
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

        if ($temple->deity !== null) {
            $faq[] = ['q' => 'Which deity is worshipped at '.$name.'?', 'a' => $name.' is dedicated to '.$temple->deity->name.'.'];
        }

        if ($temple->events->isNotEmpty()) {
            $faq[] = ['q' => 'Which festivals and events are coming up at '.$name.'?', 'a' => $temple->events->take(4)->map(fn ($e) => $e->title.' ('.optional($e->nextDate() ?? $e->starts_on)->format('d M Y').')')->implode(', ').'.'];
        }

        if ($nearby !== null && $nearby->isNotEmpty()) {
            $faq[] = ['q' => 'Which temples are near '.$name.'?', 'a' => $nearby->take(5)->map(fn (Temple $t) => $t->name.' ('.TempleSeo::km((float) $t->distance_km).' away)')->implode(', ').'.'];
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
