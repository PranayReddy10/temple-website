<?php

namespace App\Support;

use App\Enums\CheckInMethod;
use App\Enums\TempleStatus;
use App\Models\Devotee;
use App\Models\DevoteeVisit;
use App\Models\Temple;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A temple's own staff marking a devotee as visited, after scanning the
 * devotee's passport at the counter.
 *
 * The devotee standing in front of the person who scanned them is evidence
 * of the same order as a temple code at the gate, so the visit is verified
 * and records who marked it. Only a temple the staff member has an approved
 * claim on can be marked: the check is here, not in the page that calls it,
 * so no second caller can forget it.
 */
final class StaffCheckIn
{
    public const CREATED = 'created';

    public const VERIFIED = 'verified';

    public const ALREADY = 'already';

    /**
     * @return array{outcome: string, visit: DevoteeVisit}
     *
     * @throws AuthorizationException
     */
    public static function mark(Devotee $devotee, Temple $temple, User $staff): array
    {
        if (! $staff->administersTemple($temple)) {
            throw new AuthorizationException('You can mark visits only at temples you manage.');
        }

        if ($temple->status !== TempleStatus::Published) {
            throw new AuthorizationException('This temple is not published yet, so devotees cannot collect its stamp.');
        }

        $today = DevotionalClock::now()->toDateString();

        // One stamp a day per temple: a second scan in the same queue should
        // not add a second visit. A visit the devotee already recorded today
        // by hand is the same visit, now confirmed.
        $existing = $devotee->visits()
            ->where('temple_id', $temple->getKey())
            ->whereDate('visited_on', $today)
            ->orderByDesc('is_verified')
            ->first();

        if ($existing?->is_verified) {
            return ['outcome' => self::ALREADY, 'visit' => $existing];
        }

        if ($existing !== null) {
            // Now it is the counter's word, not only the devotee's; the app
            // shows it as marked by the temple.
            $existing->forceFill([
                'method' => CheckInMethod::Staff,
                'is_verified' => true,
                'verified_at' => now(),
                'verified_by' => $staff->getKey(),
            ])->save();

            return ['outcome' => self::VERIFIED, 'visit' => $existing];
        }

        $visit = new DevoteeVisit([
            'temple_id' => $temple->getKey(),
            'method' => CheckInMethod::Staff,
            'visited_on' => $today,
            'visited_at' => DevotionalClock::now()->format('H:i'),
            'is_public' => true,
        ]);
        $visit->devotee_id = $devotee->getKey();
        $visit->is_verified = true;
        $visit->verified_at = now();
        $visit->verified_by = $staff->getKey();
        $visit->save();

        // A trip that planned this temple is now one stop further along, as
        // when the devotee checks in themselves.
        $devotee->yatras()->upcoming()->get()->each(fn ($yatra) => $yatra->stops()
            ->where('temple_id', $temple->getKey())
            ->whereNull('devotee_visit_id')
            ->update(['devotee_visit_id' => $visit->getKey()]));

        return ['outcome' => self::CREATED, 'visit' => $visit];
    }
}
