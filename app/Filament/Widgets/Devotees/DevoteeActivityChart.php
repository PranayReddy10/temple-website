<?php

namespace App\Filament\Widgets\Devotees;

use App\Support\DevoteeStats;
use Filament\Widgets\ChartWidget;

/**
 * Sign-ups against active devotees, over time.
 *
 * Together rather than on separate charts, because the interesting shape is
 * the relationship: sign-ups climbing while the active line stays flat is a
 * campaign bringing in people who never come back, and neither line says that
 * alone. Both are people per day, so one axis is honest — see
 * DevoteeStats::dailyActiveSeries for why sign-ins are not what is plotted.
 *
 * Every day in the window is plotted, including the empty ones. Grouping in
 * SQL returns only the days that had something, and a chart drawn from that
 * closes the gaps — a quiet week renders as a straight line between two busy
 * days instead of the flat line it was.
 */
class DevoteeActivityChart extends ChartWidget
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

    protected static ?int $sort = 2;

    protected ?string $heading = 'Sign-ups and active devotees';

    protected ?string $description = 'Both are people per day, so they share one axis honestly: new accounts against how many distinct devotees actually signed in.';

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?? 30);

        $signUps = DevoteeStats::dailySeries('devotees', 'created_at', $days);

        // Distinct people, not sessions: see DevoteeStats::dailyActiveSeries
        // for why raw sign-ins cannot share an axis with sign-ups.
        $active = DevoteeStats::dailyActiveSeries($days);

        $colors = config('brand.colors');

        return [
            'datasets' => [
                [
                    'label' => 'Sign-ups',
                    'data' => $signUps->values()->all(),
                    'borderColor' => $colors['saffron']['hex'],
                    'backgroundColor' => 'rgba('.$colors['saffron']['rgb'].', 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Active devotees',
                    'data' => $active->values()->all(),
                    'borderColor' => $colors['kumkum']['hex'],
                    'backgroundColor' => 'rgba('.$colors['kumkum']['rgb'].', 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            // Short labels: 90 days of "12 January 2027" is unreadable at any
            // width, and the year is the same on every point anyway.
            'labels' => $signUps->keys()
                ->map(fn (string $date): string => \Carbon\Carbon::parse($date)->format('d M'))
                ->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                // Counts are whole numbers; a y-axis offering 0.5 sign-ups is
                // Chart.js guessing rather than the data saying anything.
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
            'plugins' => ['legend' => ['display' => true]],
        ];
    }
}
