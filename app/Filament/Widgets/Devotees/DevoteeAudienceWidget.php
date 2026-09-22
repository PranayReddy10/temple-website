<?php

namespace App\Filament\Widgets\Devotees;

use App\Filament\Resources\Devotees\DevoteeResource;
use App\Support\AdminLinks;
use App\Support\DevoteeStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Who the audience is, and whether it is growing.
 *
 * Active means distinct people who signed in over the window, not sign-ins.
 * The two diverge fast — a devotee opening the app eight times in a day is
 * one active user — and the wrong one is the one that flatters.
 */
class DevoteeAudienceWidget extends StatsOverviewWidget
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

    protected static ?int $sort = 0;

    protected ?string $heading = 'Accounts and activity';

    protected function getStats(): array
    {
        $total = DevoteeStats::totalAccounts();
        $devotees = DevoteeResource::getUrl('index');

        $daily = DevoteeStats::activeUsers(1);
        $weekly = DevoteeStats::activeUsers(7);
        $monthly = DevoteeStats::activeUsers(30);

        return [
            Stat::make('Devotee accounts', number_format($total))
                ->description(DevoteeStats::newAccounts(30).' joined in the last 30 days')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('primary')
                ->url($devotees),

            Stat::make('Active today', number_format($daily))
                ->description('Distinct people who signed in')
                ->descriptionIcon('heroicon-m-bolt')
                ->color($daily > 0 ? 'success' : 'gray'),

            Stat::make('Active this week', number_format($weekly))
                ->description($this->sharePhrase($weekly, $total).' of all accounts')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($weekly > 0 ? 'success' : 'gray')
                ->url(AdminLinks::filtered($devotees, ['signed_in_recently' => AdminLinks::on()])),

            Stat::make('Active this month', number_format($monthly))
                ->description($this->stickinessPhrase())
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color($monthly > 0 ? 'success' : 'gray'),

            Stat::make('Sign-ins this week', number_format(DevoteeStats::signIns(7)))
                ->description(DevoteeStats::failedSignIns(7).' failed attempts')
                ->descriptionIcon('heroicon-m-arrow-right-on-rectangle')
                ->color(DevoteeStats::failedSignIns(7) > DevoteeStats::signIns(7) ? 'danger' : 'info'),

            /*
             * Sign-ups that never became a sign-in.
             *
             * Usually a broken confirmation step rather than people changing
             * their minds, and it is invisible in a total-accounts figure.
             */
            Stat::make('Never signed in', number_format(DevoteeStats::neverSignedIn()))
                ->description('Registered but never came back')
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color(DevoteeStats::neverSignedIn() > 0 ? 'warning' : 'success')
                ->url(AdminLinks::filtered($devotees, ['dormant' => AdminLinks::on()])),
        ];
    }

    protected function stickinessPhrase(): string
    {
        $stickiness = DevoteeStats::stickiness();

        return $stickiness === null
            ? 'No sign-ins yet'
            : round($stickiness * 100).'% of them were here today';
    }

    protected function sharePhrase(int $part, int $whole): string
    {
        return $whole === 0 ? 'No accounts yet' : round($part / $whole * 100).'%';
    }
}
