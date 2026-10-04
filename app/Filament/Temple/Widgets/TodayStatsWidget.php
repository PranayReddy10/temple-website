<?php

namespace App\Filament\Temple\Widgets;

use App\Filament\Temple\Pages\Finance;
use App\Filament\Temple\Resources\Bookings\BookingResource;
use App\Filament\Temple\Resources\HundiDonations\HundiDonationResource;
use App\Filament\Temple\Resources\MyTemples\MyTempleResource;
use App\Filament\Temple\Widgets\Concerns\ForCurrentTemple;
use App\Models\TempleSettlement;
use App\Support\DevotionalClock;
use App\Support\TempleTeam\TempleStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Today at the temple, and what is waiting: the trust app's home numbers. */
class TodayStatsWidget extends StatsOverviewWidget
{
    use ForCurrentTemple;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Today';

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $temple = $this->temple();
        if ($temple === null) {
            return [];
        }
        $s = TempleStats::for($temple);
        $edit = fn (int $tab): string => MyTempleResource::getUrl('edit', ['record' => $temple, 'relation' => (string) $tab]);

        // The last fortnight of hundi, for the sparkline.
        $since = DevotionalClock::now()->subDays(13)->toDateString();
        $byDay = $temple->donations()->paid()->whereDate('paid_on', '>=', $since)
            ->selectRaw('paid_on as day, sum(amount_paise) as amount')->groupBy('paid_on')->pluck('amount', 'day')
            ->mapWithKeys(fn ($amount, $day): array => [substr((string) $day, 0, 10) => $amount]);
        $hundiTrend = collect(range(13, 0))->map(fn (int $ago): float => (int) ($byDay[DevotionalClock::now()->subDays($ago)->toDateString()] ?? 0) / 100)->all();

        return [
            Stat::make('Sevas paid for today', TempleSettlement::rupees($s['amount_today_paise']))
                ->description($s['bookings_today'].' bookings · '.$s['people_today'].' people')
                ->descriptionIcon('heroicon-m-ticket')
                ->url(Finance::getUrl()),
            Stat::make('Received at the counter', $s['received_today'].' of '.$s['bookings_today'])
                ->description(max(0, $s['bookings_today'] - $s['received_today']).' still to come today')
                ->descriptionIcon('heroicon-m-qr-code')
                ->color($s['bookings_today'] > 0 && $s['received_today'] >= $s['bookings_today'] ? 'success' : 'gray')
                ->url(BookingResource::getUrl()),
            Stat::make('Online hundi today', TempleSettlement::rupees($s['hundi_today_paise']))
                ->description($s['hundi_today_count'].' '.str('gift')->plural($s['hundi_today_count']).' · this month '.TempleSettlement::rupees($s['hundi_month_paise']))
                ->descriptionIcon('heroicon-m-gift')
                ->chart($hundiTrend)
                ->color($s['hundi_enabled'] ? 'success' : 'gray')
                ->url(HundiDonationResource::getUrl()),
            Stat::make('Upcoming seva bookings', (string) $s['bookings_upcoming'])
                ->description('today and ahead')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->url(BookingResource::getUrl()),
            Stat::make('Events ahead', (string) $s['events_upcoming'])
                ->description($s['events_in_review'] > 0 ? $s['events_in_review'].' waiting for review' : 'none waiting for review')
                ->descriptionIcon('heroicon-m-sparkles')
                ->color($s['events_in_review'] > 0 ? 'warning' : 'gray')
                ->url($edit(5)),
            Stat::make('Reviews to answer', (string) $s['reviews_to_answer'])
                ->description('devotees see your reply in the app')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color($s['reviews_to_answer'] > 0 ? 'warning' : 'gray')
                ->url($edit(4)),
            Stat::make('Check-ins', number_format($s['visits']))
                ->description('devotees who checked in here')
                ->descriptionIcon('heroicon-m-map-pin'),
            Stat::make('Followers', number_format($s['followers']))
                ->description(number_format($s['likes']).' likes · '.$s['photos'].' photos · '.$s['sevas'].' sevas')
                ->descriptionIcon('heroicon-m-heart'),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
