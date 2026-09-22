<?php

namespace App\Filament\Widgets\Devotees;

use App\Models\Devotee;
use App\Support\Locales;
use Filament\Widgets\ChartWidget;

/**
 * Which languages devotees have chosen.
 *
 * The number that decides which translations to pay for next. Guessing from
 * where temples are is a bad proxy: a Telugu speaker in Delhi still reads
 * Telugu, and their account says so.
 */
class LanguageBreakdownWidget extends ChartWidget
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

    protected static ?int $sort = 3;

    protected ?string $heading = 'Languages devotees chose';

    protected ?string $description = 'What to translate next, from what people actually set — not from where their temples are.';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = Devotee::query()
            ->selectRaw('locale, COUNT(*) as aggregate')
            ->groupBy('locale')
            ->orderByDesc('aggregate')
            ->pluck('aggregate', 'locale');

        if ($counts->isEmpty()) {
            return ['datasets' => [['data' => []]], 'labels' => []];
        }

        $colors = config('brand.colors');

        // The temple palette first, then a repeating tail. A chart with more
        // slices than brand colours should still be readable.
        $palette = [
            $colors['saffron']['hex'],
            $colors['kumkum']['hex'],
            $colors['gold']['hex'],
            '#4E7A51',
            '#3E6B8A',
            '#7A4E8A',
            $colors['deep']['hex'],
            '#8A6E4E',
        ];

        return [
            'datasets' => [[
                'label' => 'Devotees',
                'data' => $counts->values()->all(),
                'backgroundColor' => collect($counts)
                    ->keys()
                    ->map(fn ($_, int $i): string => $palette[$i % count($palette)])
                    ->all(),
                'borderWidth' => 0,
            ]],
            'labels' => $counts->keys()
                // The native name, as everywhere else a language is shown.
                ->map(fn (?string $code): string => $code === null
                    ? 'Not set'
                    : (Locales::supported()[$code]['native'] ?? $code))
                ->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'right']],
        ];
    }
}
