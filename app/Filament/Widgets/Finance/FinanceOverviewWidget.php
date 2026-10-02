<?php

namespace App\Filament\Widgets\Finance;

use App\Filament\Resources\TempleBalances\TempleBalanceResource;
use App\Filament\Resources\TempleSettlements\TempleSettlementResource;
use App\Models\Payment;
use App\Models\PujaBooking;
use App\Models\TempleDonation;
use App\Models\TempleSettlement;
use App\Support\DevotionalClock;
use App\Support\Finance\Settlements;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Seva money at a glance: what devotees paid, what the platform holds for
 * temples, and what has gone out. Shown on the Finance pages, not the
 * dashboard, where the editorial queues come first.
 */
class FinanceOverviewWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Seva money';

    protected function getStats(): array
    {
        $now = DevotionalClock::now();
        $today = $now->toDateString();
        $monthFrom = $now->copy()->startOfMonth()->toDateString();
        $monthTo = $now->copy()->endOfMonth()->toDateString();
        $cutoff = app(Settlements::class)->defaultCutoff()->toDateString();

        $todayRow = PujaBooking::query()->paidFor()->whereDate('booked_for', $today)
            ->selectRaw('count(*) as n, coalesce(sum(people), 0) as people, coalesce(sum(amount_paise), 0) as amount')->first();

        $collectedToday = (int) Payment::query()->paid()->whereIn('purpose', [Payment::PUJA_BOOKING, Payment::EVENT_TICKET, Payment::DONATION])
            ->where('paid_at', '>=', $now->copy()->startOfDay()->utc())->sum('amount_paise');

        $month = (int) PujaBooking::query()->paidFor()
            ->whereDate('booked_for', '>=', $monthFrom)->whereDate('booked_for', '<=', $monthTo)->sum('amount_paise');

        $owedGross = 0;
        $heldAhead = 0;
        foreach (Settlements::SOURCES as [$model, $column]) {
            $owedGross += (int) $model::query()->settleable()->whereDate($column, '<=', $cutoff)->sum('amount_paise');
            $heldAhead += (int) $model::query()->settleable()->whereDate($column, '>', $cutoff)->sum('amount_paise');
        }
        $hundiMonth = (int) TempleDonation::query()->paid()->whereDate('paid_on', '>=', $monthFrom)->sum('amount_paise');

        $pending = TempleSettlement::query()->pending()->selectRaw('count(*) as n, coalesce(sum(net_paise), 0) as net')->first();
        $paidMonth = TempleSettlement::query()->paid()->where('paid_at', '>=', $now->copy()->startOfMonth()->utc())
            ->selectRaw('coalesce(sum(net_paise), 0) as net, coalesce(sum(fee_paise), 0) as fee')->first();

        $rupees = fn (int $paise): string => TempleSettlement::rupees($paise);

        return [
            Stat::make('Sevas today', $rupees((int) $todayRow->amount))
                ->description($todayRow->n.' paid bookings · '.$todayRow->people.' people')
                ->descriptionIcon('heroicon-m-ticket')
                ->color('primary'),

            Stat::make('Collected today', $rupees($collectedToday))
                ->description('Sevas, event tickets and hundi, through the gateway')
                ->descriptionIcon('heroicon-m-credit-card')
                ->color('success'),

            Stat::make('Sevas this month', $rupees($month))
                ->description($now->format('F Y').', by seva day · hundi '.$rupees($hundiMonth))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('gray'),

            Stat::make('Ready to settle', $rupees($owedGross))
                ->description('Gross, seva days up to '.Carbon::parse($cutoff)->format('d M').' · '.$rupees($heldAhead).' more for days ahead')
                ->descriptionIcon('heroicon-m-building-library')
                ->color($owedGross > 0 ? 'warning' : 'success')
                ->url(TempleBalanceResource::getUrl('index')),

            Stat::make('Being paid out', $rupees((int) $pending->net))
                ->description($pending->n.' settlements waiting for the transfer')
                ->descriptionIcon('heroicon-m-arrow-up-right')
                ->color((int) $pending->n > 0 ? 'info' : 'gray')
                ->url(TempleSettlementResource::getUrl('index')),

            Stat::make('Paid to temples this month', $rupees((int) $paidMonth->net))
                ->description('Platform fees kept: '.$rupees((int) $paidMonth->fee))
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),
        ];
    }
}
