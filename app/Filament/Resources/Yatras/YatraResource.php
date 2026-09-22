<?php

namespace App\Filament\Resources\Yatras;

use App\Enums\YatraStatus;
use App\Filament\Resources\Yatras\Pages\ListYatras;
use App\Models\Yatra;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Tables\Columns\IconColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Every devotee's planned pilgrimage, read-only.
 *
 * "How many are planning a trip" is the question this answers, and it is a
 * different question from how many have been somewhere. A temple deciding
 * whether to expect a crowd, or a partnership conversation about a route,
 * needs intent rather than history.
 *
 * Nothing here is editable. A trip belongs to the devotee who planned it;
 * staff read it, they do not rearrange someone's pilgrimage.
 */
class YatraResource extends Resource
{
    protected static ?string $model = Yatra::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static string|\UnitEnum|null $navigationGroup = 'Devotees';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Trips & Yatras';

    protected static ?string $modelLabel = 'trip';

    protected static ?string $slug = 'trips';

    protected static ?string $recordTitleAttribute = 'title';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('devotee:id,name')
                ->withCount('stops'))
            ->columns([
                TextColumn::make('title')->label('Trip')->searchable()->sortable()->weight('medium')->wrap(),

                TextColumn::make('devotee.name')->label('Devotee')->searchable()->toggleable(),

                TextColumn::make('status')->badge()->sortable(),

                TextColumn::make('dates')
                    ->label('When')
                    ->state(fn (Yatra $record): string => $record->dateLabel()),

                TextColumn::make('starts_on')
                    ->label('Starts')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('stops_count')->label('Temples')->alignEnd()->sortable(),

                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(fn (Yatra $record): string => $record->progressLabel())
                    ->toggleable(),

                TextColumn::make('party_size')
                    ->label('Party')
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(),

                IconColumn::make('is_public')
                    ->label('Shared')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('upcoming')
                    ->label('Still to happen')
                    ->query(fn (Builder $query): Builder => $query->upcoming())
                    ->toggle(),

                SelectFilter::make('status')->options(YatraStatus::class)->multiple(),

                // The one a temple would ask for: who is coming, and when.
                Filter::make('next_90_days')
                    ->label('Starting in the next 90 days')
                    ->query(fn (Builder $query): Builder => $query->upcoming()->startingWithin(90))
                    ->toggle(),

                Filter::make('no_dates')
                    ->label('No dates set')
                    ->query(fn (Builder $query): Builder => $query->whereNull('starts_on'))
                    ->toggle(),
            ])
            ->defaultSort('created_at', 'desc')
            ->persistFiltersInSession()
            ->emptyStateIcon('heroicon-o-map')
            ->emptyStateHeading('No trips planned yet')
            ->emptyStateDescription('Itineraries devotees build in the app appear here.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListYatras::route('/'),
        ];
    }

    /** Trips being planned right now, which is what the screen is for. */
    public static function getNavigationBadge(): ?string
    {
        $upcoming = Yatra::query()->upcoming()->count();

        return $upcoming > 0 ? (string) $upcoming : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }
}
