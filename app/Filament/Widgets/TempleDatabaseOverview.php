<?php

namespace App\Filament\Widgets;

use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use App\Models\Temple;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The plan's MVP target is 1,000 high-quality temple profiles, so the dashboard
 * leads with data quality rather than vanity counts.
 */
class TempleDatabaseOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        // One grouped query for the status counts instead of four.
        $byStatus = Temple::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $published = (int) ($byStatus[TempleStatus::Published->value] ?? 0);
        $inReview = (int) ($byStatus[TempleStatus::InReview->value] ?? 0);
        $draft = (int) ($byStatus[TempleStatus::Draft->value] ?? 0);
        $total = (int) $byStatus->sum();

        $trusted = Temple::query()
            ->whereIn('verification_status', [
                VerificationStatus::Verified->value,
                VerificationStatus::Official->value,
            ])
            ->count();

        $needsAttention = Temple::query()
            ->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))
            ->count();

        return [
            Stat::make('Temples recorded', number_format($total))
                ->description($published.' published, '.$draft.' draft')
                ->descriptionIcon('heroicon-m-building-library')
                ->color('primary'),

            Stat::make('Progress to MVP', $this->progressLabel($published))
                ->description('Target is 1,000 high-quality profiles')
                ->descriptionIcon('heroicon-m-flag')
                ->color($published >= 1000 ? 'success' : 'warning'),

            Stat::make('Waiting for review', number_format($inReview))
                ->description($inReview > 0 ? 'Needs a super admin' : 'Queue is clear')
                ->descriptionIcon('heroicon-m-clock')
                ->color($inReview > 0 ? 'warning' : 'success'),

            Stat::make('Verified or official', number_format($trusted))
                ->description($this->sharePhrase($trusted, $total).' of records are source-backed')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color($trusted > 0 ? 'success' : 'gray'),

            Stat::make('Missing coordinates', number_format($needsAttention))
                ->description($needsAttention > 0 ? 'These will not appear on the map' : 'Every temple is mappable')
                ->descriptionIcon('heroicon-m-map-pin')
                ->color($needsAttention > 0 ? 'danger' : 'success'),
        ];
    }

    protected function progressLabel(int $published): string
    {
        $percent = min(100, (int) round($published / 1000 * 100));

        return $published.' / 1,000 ('.$percent.'%)';
    }

    protected function sharePhrase(int $part, int $whole): string
    {
        if ($whole === 0) {
            return 'None';
        }

        return round($part / $whole * 100).'%';
    }
}
