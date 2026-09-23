<?php

namespace App\Filament\Widgets\Devotees;

use App\Filament\Resources\Temples\TempleResource;
use App\Models\Temple;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which temples devotees actually care about.
 *
 * Three different signals, deliberately side by side. Saves are intent,
 * planned stops are commitment, visits are what happened — a temple with many
 * saves and no visits is one people want to reach and cannot, which is a
 * different problem from one nobody saves at all.
 */
class PopularTemplesWidget extends TableWidget
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

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function getTableRecordKey($record): string
    {
        return (string) $record->getKey();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Temples devotees engage with')
            ->description('Saved, planned and visited. A temple with saves but no visits is one people want to reach and cannot.')
            ->query(
                Temple::query()
                    ->published()
                    ->withCount([
                        'visits',
                        'visits as verified_visits_count' => fn (Builder $q) => $q->where('is_verified', true),
                        'yatraStops as planned_count',
                        'savedByDevotees as saved_count',
                    ])
                    ->orderByDesc('visits_count')
                    ->orderByDesc('saved_count')
            )
            ->columns([
                TextColumn::make('name')->label('Temple')->weight('medium')->wrap(),

                TextColumn::make('city')->label('City')->placeholder('—')->toggleable(),

                TextColumn::make('saved_count')->label('Saved')->alignEnd()->sortable(),

                TextColumn::make('planned_count')
                    ->label('On a trip')
                    ->alignEnd()
                    ->sortable()
                    ->tooltip('Appears as a stop in a planned pilgrimage'),

                TextColumn::make('visits_count')->label('Visits')->alignEnd()->sortable(),

                TextColumn::make('verified_visits_count')
                    ->label('Stamped')
                    ->alignEnd()
                    ->sortable()
                    ->tooltip('Visits verified by GPS, a temple code or staff'),
            ])
            ->recordUrl(fn (Temple $record): string => TempleResource::getUrl('edit', ['record' => $record]))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Nothing recorded yet')
            ->emptyStateDescription('Saves, planned stops and visits appear here as devotees use the app.');
    }
}
