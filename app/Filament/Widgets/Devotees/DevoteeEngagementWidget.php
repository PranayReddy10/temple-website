<?php

namespace App\Filament\Widgets\Devotees;

use App\Filament\Resources\VisitPhotos\VisitPhotoResource;
use App\Filament\Resources\Yatras\YatraResource;
use App\Support\AdminLinks;
use App\Support\DevoteeStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What the audience is doing: the Passport, trips and what they upload.
 *
 * Kept apart from the account figures on purpose. How many people have an
 * account and what those people do with it are different questions, and a
 * single row mixing them invites reading one as the other.
 */
class DevoteeEngagementWidget extends StatsOverviewWidget
{
    /*
     * Not discovered onto the dashboard.
     *
     * These belong together on the Devotee Analytics page; scattered across
     * the main dashboard they would bury the editorial queues that page is
     * for, and a screen that mixes "temples awaiting review" with "monthly
     * active users" answers neither question well.
     */
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 1;

    protected ?string $heading = 'Passport, trips and uploads';

    protected function getStats(): array
    {
        $planning = DevoteeStats::tripsBeingPlanned();
        $soon = DevoteeStats::tripsStartingWithin(90);
        $waiting = DevoteeStats::photosAwaitingModeration();

        $trips = YatraResource::getUrl('index');

        return [
            Stat::make('Visits recorded', number_format(DevoteeStats::visitsRecorded()))
                ->description(DevoteeStats::visitsRecorded(30).' in the last 30 days')
                ->descriptionIcon('heroicon-m-map-pin')
                ->color('primary'),

            Stat::make('Stamps awarded', number_format(DevoteeStats::stampsAwarded()))
                ->description('Verified visits, one per temple per devotee')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),

            /* The question asked directly: how many are planning a trip. */
            Stat::make('Trips being planned', number_format($planning))
                ->description(DevoteeStats::devoteesPlanningTrips().' devotees, '.$soon.' starting within 90 days')
                ->descriptionIcon('heroicon-m-map')
                ->color($planning > 0 ? 'info' : 'gray')
                ->url(AdminLinks::filtered($trips, ['upcoming' => AdminLinks::on()])),

            Stat::make('Trips starting soon', number_format($soon))
                ->description('In the next 90 days')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($soon > 0 ? 'warning' : 'gray')
                ->url(AdminLinks::filtered($trips, ['next_90_days' => AdminLinks::on()])),

            Stat::make('Photos waiting', number_format($waiting))
                ->description(DevoteeStats::photosUploaded().' uploaded in total')
                ->descriptionIcon('heroicon-m-camera')
                ->color($waiting > 0 ? 'warning' : 'success')
                ->url(AdminLinks::filtered(
                    VisitPhotoResource::getUrl('index'),
                    ['awaiting' => AdminLinks::on()],
                )),

            Stat::make('Memories written', number_format(DevoteeStats::memoriesWritten()))
                ->description('Private unless the devotee shared it')
                ->descriptionIcon('heroicon-m-book-open')
                ->color('gray'),
        ];
    }
}
