<?php

namespace App\Filament\Resources\TempleSuggestions;

use App\Enums\TempleSuggestionStatus;
use App\Filament\Resources\TempleSuggestions\Pages\ListTempleSuggestions;
use App\Filament\Resources\TempleSuggestions\Pages\ViewTempleSuggestion;
use App\Filament\Support\MediaColumn;
use App\Models\TempleSuggestion;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Temples added from the app by devotees and temple members.
 *
 * Nothing here is public. Each one becomes a draft temple, is matched to a
 * temple already listed, or is turned down with a note the sender sees.
 */
class TempleSuggestionResource extends Resource
{
    protected static ?string $model = TempleSuggestion::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Temples';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Suggested temples';

    protected static ?string $modelLabel = 'temple suggestion';

    protected static ?string $slug = 'temple-suggestions';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['photos', 'state:id,name', 'devotee:id,name', 'temple:id,name']))
            ->columns([
                MediaColumn::make('photo', fn (TempleSuggestion $record): ?string => $record->photos->first()?->path)
                    ->label('')
                    ->height(48),
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (TempleSuggestion $record): string => collect([$record->city, $record->district, $record->state?->name])->filter()->implode(', ')),
                TextColumn::make('submitter_role')
                    ->label('Sent by')
                    ->badge()
                    ->state(fn (TempleSuggestion $record): string => $record->roleLabel())
                    ->color(fn (TempleSuggestion $record): string => $record->isFromTempleMember() ? 'success' : 'gray')
                    ->description(fn (TempleSuggestion $record): ?string => $record->submitter_name ?? $record->devotee?->name),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('temple.name')->label('Temple')->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('Sent')->since()->sortable(),
            ])
            ->filters([
                Filter::make('pending')
                    ->label('Waiting for review')
                    ->query(fn (Builder $query): Builder => $query->pending())
                    ->toggle()
                    ->default(),
                SelectFilter::make('status')->options(TempleSuggestionStatus::class)->multiple(),
                Filter::make('members')
                    ->label('From temple members')
                    ->query(fn (Builder $query): Builder => $query->whereNotIn('submitter_role', ['devotee', 'other']))
                    ->toggle(),
            ])
            ->recordActions([ViewAction::make()->label('Review')])
            ->defaultSort('created_at', 'asc')
            ->persistFiltersInSession()
            ->emptyStateIcon('heroicon-o-plus-circle')
            ->emptyStateHeading('No temples suggested')
            ->emptyStateDescription('Temples devotees and temple members add from the app ("My temple is not listed") arrive here.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTempleSuggestions::route('/'),
            'view' => ViewTempleSuggestion::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = TempleSuggestion::query()->pending()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
