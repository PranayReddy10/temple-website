<?php

namespace App\Http\Controllers;

use App\Models\Deity;
use App\Models\Festival;
use App\Models\Temple;
use App\Support\DevotionalClock;
use App\Support\Seo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The festival and vrat calendar as public pages, for what people search
 * for most around temples: "Ugadi 2026 date", "Ekadashi dates 2026",
 * "festivals in October 2026". The dates are the ones the app's calendar
 * shows (the festivals table, computed from the panchang and checked by
 * editors). Each page links to temples of the festival's deity.
 */
class FestivalPageController extends Controller
{
    /** Vrats kept every fortnight or month under many names: one page for each kind, all dates. */
    public const GROUPS = [
        'ekadashi' => ['match' => 'Ekadashi', 'title' => 'Ekadashi', 'deity' => 'vishnu', 'about' => 'Ekadashi, the eleventh day of each lunar fortnight, is kept as a fast for Lord Vishnu. Each of the year’s Ekadashis has its own name and story.'],
        'purnima' => ['match' => 'Purnima', 'title' => 'Purnima (full moon)', 'deity' => null, 'about' => 'Purnima, the full-moon day, is kept with fasting, Satyanarayan puja and temple visits; several are festivals in their own right.'],
        'amavasya' => ['match' => 'Amavasya', 'title' => 'Amavasya (new moon)', 'deity' => null, 'about' => 'Amavasya, the new-moon day, is set aside for tarpanam and prayers for the ancestors.'],
    ];

    /** Festival deities named differently from the site's deity pages. */
    private const DEITY_ALIASES = ['durga' => 'devi', 'parvati' => 'devi', 'jagannath' => 'krishna'];

    public function index(): View
    {
        $today = $this->today();
        $upcoming = Festival::query()->published()
            ->whereDate('starts_on', '>=', $today->toDateString())
            ->whereDate('starts_on', '<', $today->addYear()->toDateString())
            ->orderBy('starts_on')->get();

        $years = $upcoming->map(fn (Festival $f) => $f->starts_on->year)->unique()->implode('–');

        return view('site.festivals.index', [
            'major' => $upcoming->where('is_major', true)->take(12)->values(),
            'months' => $upcoming->groupBy(fn (Festival $f) => $f->starts_on->format('Y-m')),
            'groups' => self::GROUPS,
            'title' => 'Hindu Festival Calendar '.$years.': Dates of Festivals & Vrats',
            'description' => 'Dates of Hindu festivals and vrats for the year ahead: Ekadashi, Purnima, Amavasya, Pradosh, Navratri, Diwali, Ugadi, Sankranti and more, with tithi, meaning and temples to visit.',
            'canonical' => Seo::url('festivals'),
            'crumbs' => [['Festivals', null]],
        ]);
    }

    public function show(string $slug): View
    {
        $rows = Festival::query()->published()->where('slug', $slug)->orderBy('starts_on')->get();
        throw_if($rows->isEmpty(), NotFoundHttpException::class);

        $today = $this->today();
        // The occurrence people are searching for: the coming one, or one
        // that began in the past week (Navratri is still "Navratri 2026" the
        // day after it starts).
        $next = $rows->first(fn (Festival $f) => ($f->ends_on ?? $f->starts_on)->gte($today->subDays(7))) ?? $rows->last();
        $latest = $rows->last();
        $year = $next->starts_on->year;
        $many = $rows->filter(fn (Festival $f) => $f->starts_on->year === $year)->count() > 1;
        $deity = $this->deity($latest->deity);

        $name = self::searchName($latest->name);
        $title = $many
            ? $name.' '.$year.' Dates: Full List & Significance'
            : $name.' '.$year.': Date, Tithi & Significance';
        $when = $this->dateLabel($next);

        return view('site.festivals.show', [
            'festival' => $latest,
            'rows' => $rows,
            'next' => $next,
            'today' => $today,
            'year' => $year,
            'deity' => $deity,
            'temples' => $this->templesOf($deity),
            'related' => $this->around($next, $slug),
            'faq' => array_values(array_filter([
                ['q' => 'When is '.$name.' in '.$year.'?', 'a' => self::sentence($latest->name, $next->starts_on->lt($today)).' '.$when.($next->tithi ? ' ('.$next->tithi.')' : '').'.'],
                $many ? ['q' => 'What are the '.$latest->name.' dates in '.$year.'?', 'a' => $rows->filter(fn (Festival $f) => $f->starts_on->year === $year)->map(fn (Festival $f) => $f->starts_on->format('j F'))->implode(', ').'.'] : null,
                $latest->description ? ['q' => 'What is '.$latest->name.'?', 'a' => trim(strip_tags($latest->description))] : null,
                $deity ? ['q' => 'Which temples should I visit on '.$latest->name.'?', 'a' => 'Temples of '.PublicTempleController::deityPhrase($deity->name).' are visited on '.$latest->name.'. '.config('brand.name').' lists their darshan timings, sevas and directions.'] : null,
            ])),
            'title' => Str::limit($title, 68, ''),
            'name' => $name,
            'description' => Str::limit($name.' '.$year.': '.$when.($next->tithi ? ', '.$next->tithi : '').'. '.trim(strip_tags((string) $latest->description)), 158),
            'canonical' => Seo::url('festivals/'.$slug),
            'crumbs' => [['Festivals', Seo::url('festivals')], [$latest->name, null]],
        ]);
    }

    public function month(int $year, string $month): View
    {
        $index = array_search(strtolower($month), array_map('strtolower', self::MONTHS), true);
        throw_if($index === false, NotFoundHttpException::class);

        $start = CarbonImmutable::create($year, $index + 1, 1, 0, 0, 0, DevotionalClock::timezone());
        $rows = Festival::query()->published()
            ->whereDate('starts_on', '>=', $start->toDateString())
            ->whereDate('starts_on', '<=', $start->endOfMonth()->toDateString())
            ->orderBy('starts_on')->get();
        throw_if($rows->isEmpty(), NotFoundHttpException::class);

        $label = self::MONTHS[$index].' '.$year;

        return view('site.festivals.month', [
            'rows' => $rows,
            'label' => $label,
            'prev' => $this->monthLink($start->subMonth()),
            'nextMonth' => $this->monthLink($start->addMonth()),
            'title' => 'Hindu Festivals in '.$label.': Dates & Vrat List',
            'description' => 'All Hindu festivals and vrats in '.$label.': '.$rows->where('is_major', true)->pluck('name')->merge($rows->pluck('name'))->unique()->take(6)->implode(', ').', with dates and tithi.',
            'canonical' => Seo::url('festivals/'.$year.'/'.strtolower(self::MONTHS[$index])),
            'crumbs' => [['Festivals', Seo::url('festivals')], [$label, null]],
        ]);
    }

    public function group(string $group): View
    {
        $meta = self::GROUPS[$group] ?? throw new NotFoundHttpException;
        $rows = Festival::query()->published()->where('name', 'like', '%'.$meta['match'].'%')->orderBy('starts_on')->get();
        throw_if($rows->isEmpty(), NotFoundHttpException::class);

        $today = $this->today();
        $next = $rows->first(fn (Festival $f) => $f->starts_on->gte($today));
        $year = ($next ?? $rows->last())->starts_on->year;
        $deity = $this->deity($meta['deity']);

        return view('site.festivals.group', [
            'meta' => $meta,
            'byYear' => $rows->groupBy(fn (Festival $f) => $f->starts_on->year),
            'next' => $next,
            'today' => $today,
            'deity' => $deity,
            'temples' => $this->templesOf($deity),
            'title' => $meta['title'].' '.$year.' Dates: Full List with Names',
            'description' => 'All '.$meta['title'].' dates in '.$year.($next ? ', the next on '.$this->dateLabel($next) : '').', with the name and tithi of each. '.$meta['about'],
            'canonical' => Seo::url('festivals/'.$group),
            'crumbs' => [['Festivals', Seo::url('festivals')], [$meta['title'], null]],
        ]);
    }

    public const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** The month pages that have festivals: for links and the sitemap. @return Collection<int, string> */
    public static function monthPaths(): Collection
    {
        return Festival::query()->published()->pluck('starts_on')
            ->map(fn ($d) => $d->year.'/'.strtolower(self::MONTHS[$d->month - 1]))->unique()->values();
    }

    /**
     * The next date of each festival of a deity, for links from its temples
     * and its deity page. @return Collection<int, Festival>
     */
    public static function upcomingFor(?Deity $deity, int $limit = 6): Collection
    {
        if ($deity === null || blank($deity->slug)) {
            return collect();
        }
        $names = array_merge([$deity->slug], array_keys(array_filter(self::DEITY_ALIASES, fn ($to) => $to === $deity->slug)));

        return Festival::query()->published()->whereIn('deity', $names)
            ->whereDate('starts_on', '>=', DevotionalClock::now()->toDateString())
            ->orderByDesc('is_major')->orderBy('starts_on')->get()
            ->unique('slug')->take($limit)->sortBy('starts_on')->values();
    }

    /** "Sharad Navratri begins" → "Sharad Navratri": the words people search for. */
    public static function searchName(string $name): string
    {
        return trim(preg_replace(['/\s+begins\b/i', '/\s*\([^)]*\)\s*$/'], '', $name) ?? $name) ?: $name;
    }

    /** "Sharad Navratri began on", "Diwali is on": the start of the answer. */
    public static function sentence(string $name, bool $past): string
    {
        $begins = preg_match('/\bbegins\b/i', $name) === 1;

        return self::searchName($name).' '.($begins ? ($past ? 'began on' : 'begins on') : ($past ? 'was on' : 'is on'));
    }

    /** The page for a festival, for links from elsewhere on the site. */
    public static function url(Festival $festival): string
    {
        return Seo::url('festivals/'.$festival->slug);
    }

    protected function monthLink(CarbonImmutable $month): ?array
    {
        $has = Festival::query()->published()->whereYear('starts_on', $month->year)->whereMonth('starts_on', $month->month)->exists();

        return $has ? [self::MONTHS[$month->month - 1].' '.$month->year, Seo::url('festivals/'.$month->year.'/'.strtolower(self::MONTHS[$month->month - 1]))] : null;
    }

    /** Other festivals in the weeks around this one. @return Collection<int, Festival> */
    protected function around(Festival $festival, string $except): Collection
    {
        return Festival::query()->published()->where('slug', '!=', $except)
            ->whereBetween('starts_on', [$festival->starts_on->copy()->subDays(20)->toDateString(), $festival->starts_on->copy()->addDays(40)->toDateString()])
            ->orderByDesc('is_major')->orderBy('starts_on')->limit(8)->get()->unique('slug')->sortBy('starts_on')->values();
    }

    protected function deity(?string $slug): ?Deity
    {
        if (blank($slug)) {
            return null;
        }

        return Deity::query()->where('slug', self::DEITY_ALIASES[$slug] ?? $slug)->first();
    }

    /** @return Collection<int, Temple> */
    protected function templesOf(?Deity $deity): Collection
    {
        if ($deity === null) {
            return collect();
        }

        return Temple::query()->published()->where('deity_id', $deity->getKey())
            ->with(['state:id,name', 'primaryPhoto', 'deity:id,name,image_disk,image_path'])
            ->orderByDesc('is_featured')->orderBy('name')->limit(12)->get();
    }

    protected function dateLabel(Festival $f): string
    {
        $end = $f->ends_on && ! $f->ends_on->isSameDay($f->starts_on) ? ' to '.$f->ends_on->format('l, j F Y') : '';

        return $f->starts_on->format('l, j F Y').$end;
    }

    protected function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(DevotionalClock::now()->toDateString());
    }
}
