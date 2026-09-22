<?php

namespace App\Support;

use App\Models\Devotee;
use App\Models\DevoteeMemory;
use App\Models\DevoteeVisit;
use App\Models\LoginEvent;
use App\Models\VisitPhoto;
use App\Models\Yatra;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on the analytics screen, defined once.
 *
 * Every figure here will eventually be quoted to somebody, so what each one
 * counts has to be written down in exactly one place. "Active users" is the
 * one that goes wrong most often: it means distinct people who signed in,
 * not sign-ins — a devotee who opens the app eight times in a day is one
 * active user, and a dashboard that says eight is a dashboard that will be
 * believed.
 */
final class DevoteeStats
{
    public const GUARD = 'devotee';

    // --- Accounts ---

    public static function totalAccounts(): int
    {
        return Devotee::query()->count();
    }

    public static function activeAccounts(): int
    {
        return Devotee::query()->where('is_active', true)->count();
    }

    public static function suspendedAccounts(): int
    {
        return Devotee::query()->where('is_active', false)->count();
    }

    /** Sign-ups in the last $days days, inclusive of today. */
    public static function newAccounts(int $days): int
    {
        return Devotee::query()->where('created_at', '>=', self::windowStart($days))->count();
    }

    /**
     * Accounts that have never signed in.
     *
     * The number that says whether sign-up is working: a registration that is
     * never followed by a sign-in is usually a confirmation step nobody can
     * complete, not a person who changed their mind.
     */
    public static function neverSignedIn(): int
    {
        return Devotee::query()
            ->whereDoesntHave('loginEvents', fn ($q) => $q->where('succeeded', true))
            ->count();
    }

    // --- Sign-ins ---

    /** Distinct people, not sign-ins. DAU at 1, WAU at 7, MAU at 30. */
    public static function activeUsers(int $days): int
    {
        return LoginEvent::activeAccountCount(self::GUARD, $days);
    }

    public static function signIns(int $days): int
    {
        return LoginEvent::query()->forGuard(self::GUARD)->succeeded()->since($days)->count();
    }

    public static function failedSignIns(int $days): int
    {
        return LoginEvent::query()->forGuard(self::GUARD)->failed()->since($days)->count();
    }

    public static function staffSignIns(int $days): int
    {
        return LoginEvent::query()->forGuard('web')->succeeded()->since($days)->count();
    }

    public static function activeStaff(int $days): int
    {
        return LoginEvent::activeAccountCount('web', $days);
    }

    /**
     * How sticky the product is: daily actives as a share of monthly actives.
     *
     * The single most honest number about a consumer app, and the one hardest
     * to flatter — it cannot be raised by acquiring more users.
     */
    public static function stickiness(): ?float
    {
        $monthly = self::activeUsers(30);

        return $monthly === 0 ? null : self::activeUsers(1) / $monthly;
    }

    // --- Passport, trips and content ---

    public static function visitsRecorded(?int $days = null): int
    {
        $query = DevoteeVisit::query();

        return ($days === null ? $query : $query->since($days))->count();
    }

    /**
     * Verified visits, counted once per devotee per temple.
     *
     * Through a subquery rather than COUNT(DISTINCT a, b), which is MySQL's
     * own extension: SQLite rejects it outright, so the test suite would
     * never see this run.
     */
    public static function stampsAwarded(): int
    {
        $distinctStamps = DevoteeVisit::query()
            ->verified()
            ->select('devotee_id', 'temple_id')
            ->distinct();

        return DB::query()->fromSub($distinctStamps, 'stamps')->count();
    }

    public static function tripsBeingPlanned(): int
    {
        return Yatra::query()->upcoming()->count();
    }

    public static function tripsStartingWithin(int $days): int
    {
        return Yatra::query()->upcoming()->startingWithin($days)->count();
    }

    public static function devoteesPlanningTrips(): int
    {
        return Yatra::query()->upcoming()->distinct()->count('devotee_id');
    }

    public static function photosAwaitingModeration(): int
    {
        return VisitPhoto::query()->awaitingModeration()->count();
    }

    public static function photosUploaded(): int
    {
        return VisitPhoto::query()->count();
    }

    public static function memoriesWritten(): int
    {
        return DevoteeMemory::query()->count();
    }

    // --- Series, for the charts ---

    /**
     * A value per day for the last $days days, with the empty days present.
     *
     * Grouping in SQL returns only the days that had something, and a chart
     * drawn from that silently closes the gaps — a week of no sign-ups
     * renders as a straight line between two busy days rather than as the
     * flat line it was.
     *
     * @return Collection<string, int> date (Y-m-d) => count
     */
    public static function dailySeries(string $table, string $column, int $days, ?callable $constrain = null): Collection
    {
        $start = self::windowStart($days);

        $query = DB::table($table)
            ->selectRaw('DATE('.$column.') as day, COUNT(*) as aggregate')
            ->where($column, '>=', $start)
            ->groupBy('day');

        if ($constrain !== null) {
            $constrain($query);
        }

        return self::fillWindow($days, $query->pluck('aggregate', 'day'));
    }

    /**
     * Turns the days that had something into every day in the window.
     *
     * @param  Collection<string, mixed>  $counted
     * @return Collection<string, int>
     */
    protected static function fillWindow(int $days, Collection $counted): Collection
    {
        return collect(range(0, $days - 1))
            ->mapWithKeys(function (int $offset) use ($days, $counted): array {
                $date = now()->subDays($days - 1 - $offset)->toDateString();

                return [$date => (int) ($counted[$date] ?? 0)];
            });
    }

    /**
     * Distinct devotees signed in, per day.
     *
     * Plotted against sign-ups rather than raw sign-ins, which is the same
     * chart it replaced but a better question. Sign-ins is a session count:
     * it is always far larger than sign-ups, so on a shared axis the sign-up
     * line flattens against zero and says nothing, and giving it its own axis
     * would let the two be scaled into any crossing you like. People per day
     * against people per day is comparable without any of that, and "are the
     * new accounts turning into active ones" is what anyone reading this
     * actually wants to know.
     *
     * One query for the whole window rather than one per day.
     *
     * @return Collection<string, int> date (Y-m-d) => count
     */
    public static function dailyActiveSeries(int $days, string $guard = self::GUARD): Collection
    {
        $counted = DB::table('login_events')
            ->selectRaw('DATE(occurred_at) as day, COUNT(DISTINCT authenticatable_id) as aggregate')
            ->where('guard', $guard)
            ->where('succeeded', true)
            ->whereNotNull('authenticatable_id')
            ->where('occurred_at', '>=', self::windowStart($days))
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        return self::fillWindow($days, $counted);
    }

    public static function windowStart(int $days): Carbon
    {
        return now()->subDays($days - 1)->startOfDay();
    }
}
