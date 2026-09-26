<?php

namespace App\Filament\Resources\SevaDrives;

use App\Enums\SevaCause;
use App\Enums\SevaDriveStatus;
use App\Filament\Resources\SevaDrives\Pages\ListSevaDrives;
use App\Filament\Resources\SevaDrives\Pages\ViewSevaDrive;
use App\Filament\Resources\SevaDrives\RelationManagers\DonationsRelationManager;
use App\Filament\Resources\SevaDrives\RelationManagers\MediaRelationManager;
use App\Filament\Resources\SevaDrives\RelationManagers\VolunteersRelationManager;
use App\Filament\Support\MediaColumn;
use App\Filament\Support\SevaDriveDecisions;
use App\Models\SevaDrive;
use App\Models\SevaDriveMedia;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Seva drives devotees have raised: the review queue and the record.
 *
 * Two moments need a person. A new drive is an invitation to strangers to
 * meet at a place, so it is approved before anybody sees it; a finished one
 * is about to ask for money, so its before and after are verified first.
 */
class SevaDriveResource extends Resource
{
    protected static ?string $model = SevaDrive::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-hand-raised';

    protected static string|\UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Seva Drives';

    protected static ?string $modelLabel = 'seva drive';

    protected static ?string $slug = 'seva-drives';

    protected static ?string $recordTitleAttribute = 'title';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['organiser:id,name', 'temple:id,name', 'media'])
                ->withSum('volunteers', 'party_size'))
            ->columns([
                MediaColumn::make('cover', fn (SevaDrive $record): ?string => $record->media
                    ->first(fn (SevaDriveMedia $m): bool => $m->type === SevaDriveMedia::TYPE_PHOTO && filled($m->path))?->path)
                    ->label('')
                    ->height(48),

                TextColumn::make('title')
                    ->searchable()
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (SevaDrive $record): string => collect([
                        $record->place_name, $record->city,
                    ])->filter()->implode(', ')),

                TextColumn::make('cause')->badge()->color('gray')->toggleable(),

                TextColumn::make('organiser.name')->label('Organiser')->searchable(),

                TextColumn::make('starts_at')->label('On')->dateTime('d M Y, H:i')->sortable(),

                TextColumn::make('volunteers_sum_party_size')
                    ->label('Coming')
                    ->numeric()
                    ->placeholder('0')
                    ->description(fn (SevaDrive $record): ?string => $record->volunteers_needed
                        ? 'of '.$record->volunteers_needed
                        : null),

                TextColumn::make('status')->badge()->sortable(),

                TextColumn::make('upi_id')->label('UPI')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')->label('Raised')->since()->sortable(),
            ])
            ->filters([
                Filter::make('needs_staff')
                    ->label('Needs a decision')
                    ->query(fn (Builder $query): Builder => $query->needsStaff())
                    ->toggle(),
                SelectFilter::make('status')->options(SevaDriveStatus::class)->multiple(),
                SelectFilter::make('cause')->options(SevaCause::class)->multiple(),
            ])
            ->recordActions([
                ViewAction::make()->label('Open'),
                ActionGroup::make(SevaDriveDecisions::actions()),
            ])
            // Oldest first, like every queue here.
            ->defaultSort('created_at', 'asc')
            ->persistFiltersInSession()
            ->emptyStateIcon('heroicon-o-hand-raised')
            ->emptyStateHeading('No seva drives yet')
            ->emptyStateDescription('Drives devotees raise from the app to care for old temples and heritage places arrive here for review.');
    }

    public static function getRelations(): array
    {
        return [
            MediaRelationManager::class,
            VolunteersRelationManager::class,
            DonationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSevaDrives::route('/'),
            'view' => ViewSevaDrive::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = SevaDrive::query()->needsStaff()->count();

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

    /** Drives are raised by devotees, from the app. */
    public static function canCreate(): bool
    {
        return false;
    }
}
