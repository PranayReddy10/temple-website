<?php

namespace App\Http\Controllers;

use App\Models\DevotionalDay;
use App\Models\Festival;
use App\Models\Temple;
use App\Support\DevotionalClock;
use App\Support\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * darshansaathi.com/ — the website's home page, rendered here like every
 * other page of the site: a search, popular temples, temples by state and
 * by deity, the festivals ahead, and the way to the app.
 */
class SiteHomeController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        // Links made for the old web app (?temple=<slug>&action=book) and
        // shared before it was retired: the temple's page, its sevas or its
        // hundi.
        $slug = (string) $request->query('temple');
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) === 1) {
            $page = match ($request->query('action')) {
                'book' => '/sevas',
                'donate' => '/donate',
                default => '',
            };

            return redirect()->to(Seo::url('temples/'.$slug.$page), 301);
        }

        // A payment's result page from before the website had accounts.
        $payment = (string) $request->query('payment');
        if (preg_match('/^[0-9a-f-]{36}$/', $payment) === 1) {
            return redirect()->to(Seo::url('account/payments/'.$payment));
        }

        $today = DevotionalClock::now();

        $popular = Temple::query()->published()
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->tap(PublicTempleController::cardDetails(...))
            ->orderByDesc('is_featured')->orderByDesc('published_at')
            ->limit(8)->get();

        // More temples as plain links: what search engines follow from the
        // site's strongest page.
        $more = Temple::query()->published()
            ->whereNotIn('id', $popular->modelKeys())
            ->orderByDesc('is_featured')->orderByDesc('published_at')
            ->limit(40)->get(['id', 'name', 'slug', 'city']);

        $states = PublicTempleController::statesWithTemples();

        return view('site.home', [
            'popular' => $popular,
            'more' => $more,
            'states' => $states,
            'deities' => PublicTempleController::deitiesWithTemples()->take(16),
            'templeCount' => Temple::query()->published()->count(),
            'day' => rescue(fn () => DevotionalDay::query()->active()->forDate($today)
                ->with('deity:id,name,slug')->orderBy('sort_order')->first(), null, report: false),
            'festivals' => rescue(fn () => Festival::query()->published()
                ->between($today->toDateString(), $today->addDays(45)->toDateString())
                ->orderBy('starts_on')->limit(6)->get(), collect(), report: false),
            'weekday' => $today->format('l'),
            'storeUrl' => Seo::storeUrl(),
            'title' => 'Temple timings, puja booking and directions',
            'description' => 'Darshan timings, puja and seva booking, dress code and directions for Hindu temples across India, with festivals, a temple passport and yatra planning.',
            'canonical' => Seo::url('/'),
        ]);
    }
}
