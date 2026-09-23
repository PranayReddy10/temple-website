<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DevotionalDayResource;
use App\Models\DevotionalDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DevotionalDayController extends Controller
{
    /** How many of the day's temples to include inline. */
    protected const TEMPLES_PER_DAY = 10;

    /**
     * What to show a devotee today: the day's deity, its colour, its songs,
     * and temples of that deity.
     */
    public function today(Request $request): JsonResponse
    {
        // The app's timezone decides the day, not the server's location. A
        // devotee in India must not see Sunday's deity late on Saturday.
        $date = Carbon::now(config('app.timezone'));

        $days = $this->loadDays(fn ($query) => $query->forDate($date));

        return response()->json([
            'data' => [
                'date' => $date->toDateString(),
                'weekday' => $date->dayOfWeek,
                'weekday_name' => $date->format('l'),
                // The lead deity's colour, for theming the whole screen.
                'accent_color' => $days->first()?->accentColor() ?? config('brand.colors.saffron.hex'),
                'days' => DevotionalDayResource::collection($days),
            ],
        ]);
    }

    /** The whole week, for a calendar or settings screen. */
    public function index(): AnonymousResourceCollection
    {
        return DevotionalDayResource::collection(
            $this->loadDays(includeTemples: false)->sortBy(['weekday', 'sort_order'])->values(),
        );
    }

    public function show(int $weekday): DevotionalDayResource|JsonResponse
    {
        if ($weekday < 0 || $weekday > 6) {
            throw new NotFoundHttpException();
        }

        $days = $this->loadDays(fn ($query) => $query->where('weekday', $weekday));

        if ($days->isEmpty()) {
            throw new NotFoundHttpException();
        }

        return response()->json([
            'data' => DevotionalDayResource::collection($days),
        ]);
    }

    /**
     * Loads days with their published media, and optionally the temples of
     * each day's deity.
     *
     * Temples are attached per day rather than eager-loaded through a
     * relationship because the link is the deity, not a foreign key on the
     * day itself.
     */
    protected function loadDays(?callable $filter = null, bool $includeTemples = true)
    {
        $query = DevotionalDay::query()
            ->active()
            ->with([
                'deity',
                // The deity's own media as well as the day's: the mantra and
                // the aarti belong to the deity, and typing them again on
                // every one of its days is how they come to differ.
                'deity.media' => fn ($q) => $q->published(),
                // Unpublished media is invisible: it is either unfinished or
                // waiting on rights we have not confirmed.
                'media' => fn ($q) => $q->published(),
            ])
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($filter !== null) {
            $filter($query);
        }

        $days = $query->get();

        if (! $includeTemples) {
            return $days;
        }

        foreach ($days as $day) {
            $day->setRelation('dayTemples', $day->temples()
                ->with(['deity', 'state', 'district', 'primaryPhoto'])
                ->orderByDesc('published_at')
                ->limit(self::TEMPLES_PER_DAY)
                ->get());
        }

        return $days;
    }
}
