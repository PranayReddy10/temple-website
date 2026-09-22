<?php

namespace App\Filament\Widgets;

use App\Enums\TempleStatus;
use App\Models\Temple;
use App\Models\TempleCategory;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * How complete each recognised pilgrimage circuit is.
 *
 * More useful than a raw temple count: a circuit has a canonical membership,
 * so "9 of 12 Jyotirlingas" says exactly what is left to do, and the plan's
 * MVP is about quality of coverage rather than volume.
 */
class TempleCoverageWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function getTableRecordKey($record): string
    {
        return (string) $record->getKey();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Pilgrimage circuits')
            ->description('Recognised groups with a known membership, and how much of each is recorded.')
            ->query(
                TempleCategory::query()
                    ->where('kind', 'circuit')
                    ->where('is_active', true)
                    ->whereNotNull('expected_count')
                    ->withCount(['temples as recorded_count' => fn (Builder $q) => $q
                        ->where('status', TempleStatus::Published)])
                    ->orderBy('sort_order')
            )
            ->columns([
                TextColumn::make('name')->label('Circuit')->weight('medium'),

                TextColumn::make('progress')
                    ->label('Recorded')
                    ->state(fn (TempleCategory $record): string => "{$record->recorded_count} of {$record->expected_count}")
                    ->badge()
                    ->color(fn (TempleCategory $record): string => match (true) {
                        $record->recorded_count >= $record->expected_count => 'success',
                        $record->recorded_count > 0 => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('share')
                    ->label('')
                    ->state(function (TempleCategory $record): string {
                        $percent = $record->expected_count > 0
                            ? min(100, (int) round($record->recorded_count / $record->expected_count * 100))
                            : 0;

                        return $percent.'%';
                    })
                    ->color('gray'),

                TextColumn::make('remaining')
                    ->label('Still to add')
                    ->state(fn (TempleCategory $record): int => max(0, $record->expected_count - $record->recorded_count))
                    ->color(fn (int $state): string => $state === 0 ? 'success' : 'gray'),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('No circuits configured');
    }
}
