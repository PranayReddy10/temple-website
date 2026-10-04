<?php

namespace App\Support\TempleTeam;

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Models\Temple;
use App\Models\TempleDonation;
use App\Support\DevotionalClock;
use App\Support\Finance\Settlements;

/**
 * A temple at a glance for its own team: today's sevas and hundi, what is
 * coming up, what waits for an answer, and whether devotees may pay here.
 * The trust app's dashboard and the temple portal's both read this.
 */
final class TempleStats
{
    /** @return array<string, mixed> */
    public static function for(Temple $temple): array
    {
        $today = DevotionalClock::now()->toDateString();
        $live = [BookingStatus::Confirmed->value, BookingStatus::Verified->value];

        $now = DevotionalClock::now();
        $hundi = fn () => $temple->donations()->where('status', TempleDonation::PAID);

        return [
            // Online hundi: gifts devotees made in the app.
            'hundi_today_paise' => (int) $hundi()->whereDate('paid_on', $today)->sum('amount_paise'),
            'hundi_today_count' => $hundi()->whereDate('paid_on', $today)->count(),
            'hundi_month_paise' => (int) $hundi()->whereDate('paid_on', '>=', $now->copy()->startOfMonth()->toDateString())->sum('amount_paise'),
            'hundi_enabled' => (bool) $temple->accepts_donations,
            // missing · pending · approved · rejected: may devotees pay here?
            'payments' => $temple->payoutAccount?->kycStatus() ?? 'missing',
            // Why staff refused it, shown on the home screen until fixed.
            'payments_rejection_reason' => $temple->payoutAccount?->kycStatus() === 'rejected' ? $temple->payoutAccount->rejection_reason : null,
            // What the platform keeps, so the team sees its share up front.
            'fee_percent' => app(Settlements::class)->feePercentFor($temple),
            'donation_fee_percent' => app(Settlements::class)->donationFeePercent(),
            'bookings_today' => $temple->pujaBookings()->whereDate('booked_for', $today)->whereIn('status', $live)->count(),
            'bookings_upcoming' => $temple->pujaBookings()->whereDate('booked_for', '>=', $today)->whereIn('status', $live)->count(),
            'received_today' => $temple->pujaBookings()->whereDate('booked_for', $today)->where('status', BookingStatus::Verified)->count(),
            // Paid for today's sevas, in paise; the finance screen has the rest.
            'amount_today_paise' => (int) $temple->pujaBookings()->whereDate('booked_for', $today)->whereIn('status', $live)->sum('amount_paise'),
            'people_today' => (int) $temple->pujaBookings()->whereDate('booked_for', $today)->whereIn('status', $live)->sum('people'),
            'events_upcoming' => $temple->events()->upcoming()->count(),
            'events_in_review' => $temple->events()->where('status', EventStatus::PendingReview)->count(),
            'reviews_to_answer' => $temple->reviews()->approved()->whereNull('temple_reply')->count(),
            'followers' => $temple->follows()->count(),
            'likes' => $temple->likes()->count(),
            'visits' => $temple->visits()->count(),
            'photos' => $temple->photos()->count(),
            'sevas' => $temple->pujas()->count(),
        ];
    }
}
