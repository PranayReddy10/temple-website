<?php

namespace App\Filament\Widgets;

use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use App\Models\Temple;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What the database holds.
 *
 * Deliberately separate from TempleWorkQueueWidget, which covers what needs
 * a person. Showing a count in both places made the dashboard read as though
 * there were two different numbers for the same thing.
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
        $draft = (int) ($byStatus[TempleStatus::Draft->value] ?? 0);
        $total = (int) $byStatus->sum();

        $trusted = Temple::query()
            ->whereIn('verification_status', [
                VerificationStatus::Verified->value,
                VerificationStatus::Official->value,
            ])
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

            Stat::make('Verified or official', number_format($trusted))
                ->description($this->sharePhrase($trusted, $total).' of records are source-backed')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color($trusted > 0 ? 'success' : 'gray'),

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
