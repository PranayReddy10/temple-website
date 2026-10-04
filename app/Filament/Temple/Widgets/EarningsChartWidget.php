<?php

namespace App\Filament\Temple\Widgets;

use App\Filament\Temple\Widgets\Concerns\ForCurrentTemple;
use App\Support\DevotionalClock;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * What devotees paid the temple, day by day: sevas, event tickets and the
 * online hundi, stacked. The Finance page has the same numbers as a table.
 */
class EarningsChartWidget extends ChartWidget
{
    use ForCurrentTemple;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 2];

    protected ?string $heading = 'Paid by devotees';

    protected ?string $description = 'Sevas by the day they are for, tickets by the event day, hundi by the day given.';

    protected ?string $maxHeight = '280px';

    public ?string $filter = '30';

    /**
     * Brand-led series, checked for colour-blind separation; the legend and
     * tooltips name each one, so colour never carries identity alone.
     */
    private const COLORS = ['sevas' => '#9B1B30', 'tickets' => '#C9A227', 'hundi' => '#1BAF7A'];

    protected function getFilters(): ?array
    {
        return ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $temple = $this->temple();
        $days = max(7, min(90, (int) ($this->filter ?? 30)));
        $today = DevotionalClock::now()->startOfDay();
        $from = $today->copy()->subDays($days - 1)->toDateString();
        $to = $today->toDateString();
        $dates = collect(range($days - 1, 0))->map(fn (int $ago): string => $today->copy()->subDays($ago)->toDateString());

        $series = fn ($query, string $column) => $temple === null ? collect() : $query
            ->whereDate($column, '>=', $from)->whereDate($column, '<=', $to)
            ->selectRaw($column.' as day, sum(amount_paise) as amount')->groupBy($column)->pluck('amount', 'day')
            ->mapWithKeys(fn ($amount, $day): array => [substr((string) $day, 0, 10) => (int) $amount / 100]);

        $sevas = $temple ? $series($temple->pujaBookings()->live(), 'booked_for') : collect();
        $tickets = $temple ? $series($temple->eventRegistrations()->live(), 'occurs_on') : collect();
        $hundi = $temple ? $series($temple->donations()->paid(), 'paid_on') : collect();

        $dataset = fn (string $label, string $key, $values): array => [
            'label' => $label,
            'data' => $dates->map(fn (string $d): float => (float) ($values[$d] ?? 0))->all(),
            'backgroundColor' => self::COLORS[$key],
            // A 2px gap in the surface colour between stacked segments.
            'borderColor' => 'rgba(255,255,255,0.9)',
            'borderWidth' => ['top' => 2, 'right' => 0, 'bottom' => 0, 'left' => 0],
            'borderRadius' => 4,
            'borderSkipped' => 'bottom',
            'maxBarThickness' => 22,
        ];

        return [
            'datasets' => [
                $dataset('Sevas', 'sevas', $sevas),
                $dataset('Event tickets', 'tickets', $tickets),
                $dataset('Online hundi', 'hundi', $hundi),
            ],
            'labels' => $dates->map(fn (string $d): string => date('j M', strtotime($d)))->all(),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 10 } },
                    tooltip: {
                        callbacks: {
                            label: (c) => ` ${c.dataset.label}: ₹${Number(c.parsed.y).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`,
                            footer: (items) => `Total: ₹${items.reduce((s, i) => s + i.parsed.y, 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`,
                        },
                    },
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                    y: { stacked: true, beginAtZero: true, border: { display: false }, grid: { color: 'rgba(120,113,108,0.15)' }, ticks: { callback: (v) => '₹' + Number(v).toLocaleString('en-IN') } },
                },
            }
        JS);
    }
}
