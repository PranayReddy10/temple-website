<?php

namespace App\Filament\Resources\SevaDrives;

use App\Enums\SevaCause;
use App\Enums\SevaDriveStatus;
use App\Filament\Resources\SevaDrives\Pages\CreateSevaDrive;
use App\Filament\Resources\SevaDrives\Pages\EditSevaDrive;
use App\Filament\Resources\SevaDrives\Pages\ListSevaDrives;
use App\Filament\Resources\SevaDrives\Pages\ViewSevaDrive;
use App\Filament\Resources\SevaDrives\RelationManagers\DonationsRelationManager;
use App\Filament\Resources\SevaDrives\RelationManagers\MediaRelationManager;
use App\Filament\Resources\SevaDrives\RelationManagers\ReportsRelationManager;
use App\Filament\Resources\SevaDrives\Schemas\SevaDriveForm;
use App\Filament\Resources\SevaDrives\RelationManagers\VolunteersRelationManager;
use App\Filament\Support\MediaColumn;
use App\Filament\Support\SevaDriveDecisions;
use App\Models\SevaDrive;
use App\Models\SevaDriveMedia;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
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

    public static function form(Schema $schema): Schema
    {
        return SevaDriveForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['organiser:id,name', 'temple:id,name', 'media'])
                ->withSum('volunteers', 'party_size')
                ->withCount(['reports as open_reports_count' => fn (Builder $q) => $q->open()]))
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

                TextColumn::make('organiser_display')
                    ->label('Organiser')
                    ->state(fn (SevaDrive $record): string => $record->organiserName())
                    ->description(fn (SevaDrive $record): ?string => $record->devotee_id === null ? 'Team drive' : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('organiser_name', 'like', "%{$search}%")
                        ->orWhereHas('organiser', fn ($q) => $q->where('name', 'like', "%{$search}%"))),

                TextColumn::make('starts_at')
                    ->label('Dates')
                    ->state(fn (SevaDrive $record): string => $record->dateLabel())
                    ->description(fn (SevaDrive $record): string => $record->isMultiDay() ? $record->dayCount().' days' : 'One day')
                    ->sortable(),

                TextColumn::make('volunteers_sum_party_size')
                    ->label('Coming')
                    ->numeric()
                    ->placeholder('0')
                    ->description(fn (SevaDrive $record): ?string => $record->volunteers_needed
                        ? 'of '.$record->volunteers_needed
                        : null),

                TextColumn::make('status')
                    ->badge()
                    ->state(fn (SevaDrive $record) => $record->effectiveStatus())
                    ->sortable(),

                IconColumn::make('verified_at')
                    ->label('Verified')
                    ->state(fn (SevaDrive $record): bool => $record->isVerified())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon(fn (SevaDrive $record): string => $record->verificationPending() ? 'heroicon-o-clock' : 'heroicon-o-minus')
                    ->falseColor(fn (SevaDrive $record): string => $record->verificationPending() ? 'warning' : 'gray')
                    ->tooltip(fn (SevaDrive $record): string => $record->isVerified() ? 'Verified' : ($record->verificationPending() ? 'Organiser asked for verification' : 'Not verified')),

                IconColumn::make('is_misleading')
                    ->label('Misleading')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('warning')
                    ->falseIcon('')
                    ->toggleable(),

                TextColumn::make('open_reports_count')
                    ->label('Reports')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),

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
                Filter::make('verification_requested')
                    ->label('Verification requested')
                    ->query(fn (Builder $query): Builder => $query->verificationRequested())
                    ->toggle(),
                Filter::make('reported')
                    ->label('Has open reports')
                    ->query(fn (Builder $query): Builder => $query->whereHas('reports', fn ($q) => $q->open()))
                    ->toggle(),
                Filter::make('misleading')
                    ->label('Marked misleading')
                    ->query(fn (Builder $query): Builder => $query->where('is_misleading', true))
                    ->toggle(),
            ])
            ->recordActions([
                ViewAction::make()->label('Open'),
                EditAction::make(),
                ActionGroup::make([...SevaDriveDecisions::actions(), DeleteAction::make()]),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
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
            ReportsRelationManager::class,
            VolunteersRelationManager::class,
            DonationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSevaDrives::route('/'),
            'create' => CreateSevaDrive::route('/create'),
            'view' => ViewSevaDrive::route('/{record}'),
            'edit' => EditSevaDrive::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = SevaDrive::query()
            ->where(fn (Builder $q) => $q->needsStaff()->orWhereHas('reports', fn ($r) => $r->open()))
            ->count();

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

    /** Staff can run a drive themselves, or raise one for a devotee. */
    public static function canCreate(): bool
    {
        return self::canAccess();
    }
}
