<?php

namespace App\Http\Controllers;

use App\Enums\TempleStatus;
use App\Support\AppLinks;
use App\Support\Seo;
use App\Support\TempleQr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Where a temple's QR code leads when scanned with an ordinary phone camera.
 *
 * With the app installed, the phone opens the code in the app (the link is
 * on darshansaathi.com/temples/…, which the app claims), and the app checks
 * the visitor in. Without it, the temple's own page opens here, saying the
 * code is genuine, with a button that opens the app or, if it is not
 * installed, its Play Store page. A forged code says so instead.
 */
class TempleCheckinController extends Controller
{
    public function __invoke(Request $request, string $slug): View|RedirectResponse
    {
        $here = Seo::url('temples/'.$slug.'/checkin').($request->filled('s') ? '?s='.urlencode((string) $request->query('s')) : '');

        // Codes printed before they moved to the website's address.
        if (! Seo::onWebsite($request)) {
            return redirect()->away($here, 301);
        }

        $check = TempleQr::verify($request->fullUrl());
        $valid = $check['valid'] && $check['temple']?->slug === $slug;

        if ($valid && $check['temple']->status === TempleStatus::Published) {
            $page = app(PublicTempleController::class)->show($slug);

            // The scan page is the temple's page; search engines keep the original.
            return $page instanceof View ? $page->with('noindex', true)->with('scan', [
                'intent' => AppLinks::androidIntent($here),
                'appLink' => $here,
                'storeUrl' => Seo::storeUrl(),
            ]) : $page;
        }

        return view('checkin', [
            'valid' => $valid,
            'temple' => $check['temple'],
            'reason' => $check['reason'],
        ]);
    }
}
