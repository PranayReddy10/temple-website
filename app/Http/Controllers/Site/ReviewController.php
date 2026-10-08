<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Api\V1\ReviewController as ApiReviews;
use App\Http\Controllers\Controller;
use App\Models\Temple;
use App\Support\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A devotee's review of a temple, from its page on the website: the same
 * reviews, rules and moderation as the app's.
 */
class ReviewController extends Controller
{
    public function store(Request $request, string $slug): RedirectResponse
    {
        $temple = Temple::query()->published()->where('slug', $slug)->first() ?? throw new NotFoundHttpException;

        [$review] = ApiReviews::saveFor($request->user(), $temple, $request->validate(ApiReviews::rules()));

        return redirect()->to(Seo::url('temples/'.$temple->slug).'#reviews')->with('status', $review->isApproved()
            ? 'Thank you. Your review is on the page.'
            : 'Thank you. Your review shows once it is checked, usually within a day.');
    }

    public function destroy(Request $request, string $slug): RedirectResponse
    {
        $temple = Temple::query()->where('slug', $slug)->first() ?? throw new NotFoundHttpException;
        $request->user()->reviews()->where('temple_id', $temple->getKey())->delete();

        return redirect()->to(Seo::url('temples/'.$temple->slug).'#reviews')->with('status', 'Your review is removed.');
    }
}
