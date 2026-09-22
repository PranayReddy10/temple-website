<?php

namespace App\Filament\Widgets;

use App\Enums\EventStatus;
use App\Enums\TempleStatus;
use App\Models\Temple;
use App\Models\TempleEvent;
use App\Models\TempleUser;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What needs a person today, rather than what the totals happen to be.
 *
 * Every figure here is something someone can act on: a queue to clear or a
 * record to correct. Counts that nobody would act on belong elsewhere.
 */
class TempleWorkQueueWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $templesInReview = Temple::query()->where('status', TempleStatus::InReview)->count();
        $eventsInReview = TempleEvent::query()->where('status', EventStatus::PendingReview)->count();
        $pendingClaims = TempleUser::query()->pending()->count();

        $stale = Temple::query()
            ->where('status', TempleStatus::Published)
            ->where(fn ($q) => $q->whereNull('last_verified_at')
                ->orWhere('last_verified_at', '<', now()->subYear()))
            ->count();

        $missingCoordinates = Temple::query()
            ->where('status', TempleStatus::Published)
            ->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))
            ->count();

        return [
            Stat::make('Temples to review', number_format($templesInReview))
                ->description($templesInReview > 0 ? 'Waiting on a super admin' : 'Queue is clear')
                ->descriptionIcon('heroicon-m-clock')
                ->color($templesInReview > 0 ? 'warning' : 'success'),

            Stat::make('Events to review', number_format($eventsInReview))
                ->description($eventsInReview > 0 ? 'Submitted by temple teams' : 'Nothing waiting')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($eventsInReview > 0 ? 'warning' : 'success'),

            Stat::make('Access requests', number_format($pendingClaims))
                ->description($pendingClaims > 0 ? 'Temples awaiting approval' : 'None outstanding')
                ->descriptionIcon('heroicon-m-key')
                ->color($pendingClaims > 0 ? 'warning' : 'success'),

            Stat::make('Needs re-verification', number_format($stale))
                ->description('Published, unchecked for over a year')
                ->descriptionIcon('heroicon-m-shield-exclamation')
                ->color($stale > 0 ? 'danger' : 'success'),

            Stat::make('Missing coordinates', number_format($missingCoordinates))
                ->description($missingCoordinates > 0 ? 'Published but not on the map' : 'Every temple is mappable')
                ->descriptionIcon('heroicon-m-map-pin')
                ->color($missingCoordinates > 0 ? 'danger' : 'success'),
        ];
    }
}
