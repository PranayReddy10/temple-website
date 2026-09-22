<?php

namespace App\Filament\Widgets;

use App\Enums\EventStatus;
use App\Enums\TempleStatus;
use App\Filament\Resources\TempleAccess\TempleAccessResource;
use App\Filament\Resources\TempleEvents\TempleEventResource;
use App\Filament\Resources\Temples\TempleResource;
use App\Models\Temple;
use App\Models\TempleEvent;
use App\Models\TempleUser;
use App\Support\AdminLinks;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What needs a person today, rather than what the totals happen to be.
 *
 * Every figure here is something someone can act on: a queue to clear or a
 * record to correct. Counts that nobody would act on belong elsewhere.
 *
 * Each tile opens the same records it counted, filtered the same way, so
 * clearing a queue starts with one click rather than with rebuilding the
 * filter by hand. A tile an editor may not open is left unlinked rather than
 * sending them to a 403.
 */
class TempleWorkQueueWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $templesInReview = Temple::query()->where('status', TempleStatus::InReview)->count();
        $eventsInReview = TempleEvent::query()->awaitingReview()->count();
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

        $temples = TempleResource::getUrl('index');
        $publishedOnly = ['status' => AdminLinks::selected(TempleStatus::Published->value)];

        return [
            Stat::make('Temples to review', number_format($templesInReview))
                ->description($templesInReview > 0 ? 'Waiting on a super admin' : 'Queue is clear')
                ->descriptionIcon('heroicon-m-clock')
                ->color($templesInReview > 0 ? 'warning' : 'success')
                ->url(AdminLinks::filtered($temples, [
                    'status' => AdminLinks::selected(TempleStatus::InReview->value),
                ])),

            Stat::make('Events to review', number_format($eventsInReview))
                ->description($eventsInReview > 0 ? 'Submitted by temple teams' : 'Nothing waiting')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($eventsInReview > 0 ? 'warning' : 'success')
                ->url($this->urlIfPermitted(TempleEventResource::class, fn (): string => AdminLinks::filtered(
                    TempleEventResource::getUrl('index'),
                    ['status' => AdminLinks::selected(EventStatus::PendingReview->value)],
                ))),

            Stat::make('Access requests', number_format($pendingClaims))
                ->description($pendingClaims > 0 ? 'Temples awaiting approval' : 'None outstanding')
                ->descriptionIcon('heroicon-m-key')
                ->color($pendingClaims > 0 ? 'warning' : 'success')
                ->url($this->urlIfPermitted(TempleAccessResource::class, fn (): string => AdminLinks::filtered(
                    TempleAccessResource::getUrl('index'),
                    ['pending' => AdminLinks::on()],
                ))),

            Stat::make('Needs re-verification', number_format($stale))
                ->description('Published, unchecked for over a year')
                ->descriptionIcon('heroicon-m-shield-exclamation')
                ->color($stale > 0 ? 'danger' : 'success')
                ->url(AdminLinks::filtered($temples, $publishedOnly + ['stale' => AdminLinks::on()])),

            Stat::make('Missing coordinates', number_format($missingCoordinates))
                ->description($missingCoordinates > 0 ? 'Published but not on the map' : 'Every temple is mappable')
                ->descriptionIcon('heroicon-m-map-pin')
                ->color($missingCoordinates > 0 ? 'danger' : 'success')
                ->url(AdminLinks::filtered($temples, $publishedOnly + ['missing_coordinates' => AdminLinks::on()])),
        ];
    }

    /**
     * A link only where the viewer may follow it.
     *
     * getUrl() happily builds a route the resource would refuse, so the
     * permission is checked here rather than discovered as a 403 after the
     * click.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    protected function urlIfPermitted(string $resource, \Closure $url): ?string
    {
        return $resource::canAccess() ? $url() : null;
    }
}
