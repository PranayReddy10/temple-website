<?php

namespace App\Filament\Resources\TemplePhotos;

use App\Enums\PhotoCategory;
use App\Filament\Resources\TemplePhotos\Pages\ListTemplePhotos;
use App\Filament\Support\MediaColumn;
use App\Models\TemplePhoto;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Every temple photograph, across every temple.
 *
 * Distinct from Photo Stamps, which are devotees' own pictures and need
 * moderating. These are the library the listings are built from, and the
 * questions this answers are about the library as a whole: which photographs
 * have no credit recorded, which temples lead with nothing, what has just
 * been added.
 *
 * Attribution is the reason it exists. A photograph we did not take needs a
 * credit, and finding the ones that lack it by opening temples one at a time
 * is how they stay uncredited.
 */
class TemplePhotoResource extends Resource
{
    protected static ?string $model = TemplePhoto::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static string|\UnitEnum|null $navigationGroup = 'Temples';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Temple Photos';

    protected static ?string $modelLabel = 'temple photo';

    protected static ?string $slug = 'temple-photos';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('temple:id,name,city'))
            ->columns([
                MediaColumn::make(
                    'thumbnail',
                    // Smallest available: a page of full-size temple
                    // photographs is megabytes to render thumbnails.
                    fn (TemplePhoto $record): ?string => $record->thumbnail_path
                        ?? $record->medium_path
                        ?? $record->path,
                )->label('')->height(56),

                TextColumn::make('temple.name')
                    ->label('Temple')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (TemplePhoto $record): ?string => $record->temple?->city)
                    ->wrap(),

                TextColumn::make('category')->badge()->sortable()->toggleable(),

                TextColumn::make('caption')->placeholder('—')->limit(40)->toggleable(),

                TextColumn::make('credit')
                    ->label('Credit')
                    ->placeholder('Not recorded')
                    ->color(fn (TemplePhoto $record): ?string => blank($record->credit) ? 'warning' : null)
                    ->limit(30),

                IconColumn::make('is_primary')
                    ->label('Cover')
                    ->boolean()
                    ->tooltip('This is the temple\'s lead image'),

                IconColumn::make('is_published')->label('Published')->boolean()->sortable(),

                TextColumn::make('created_at')->label('Added')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('temple')->relationship('temple', 'name')->searchable()->preload(),
                SelectFilter::make('category')->options(PhotoCategory::class)->multiple(),

                // The reason this screen exists.
                Filter::make('uncredited')
                    ->label('No credit recorded')
                    ->query(fn (Builder $query): Builder => $query->whereNull('credit'))
                    ->toggle(),

                Filter::make('covers')
                    ->label('Cover images only')
                    ->query(fn (Builder $query): Builder => $query->where('is_primary', true))
                    ->toggle(),
            ])
            ->recordActions([
                Action::make('open_temple')
                    ->label('Temple')
                    ->icon('heroicon-o-building-library')
                    ->color('gray')
                    ->url(fn (TemplePhoto $record): ?string => $record->temple === null
                        ? null
                        : \App\Filament\Resources\Temples\TempleResource::getUrl('edit', ['record' => $record->temple]))
                    ->visible(fn (TemplePhoto $record): bool => $record->temple !== null),

                Action::make('make_cover')
                    ->label('Make cover')
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    ->visible(fn (TemplePhoto $record): bool => ! $record->is_primary)
                    ->requiresConfirmation()
                    ->modalDescription('This becomes the temple\'s lead image. The current one stays in the gallery.')
                    ->action(fn (TemplePhoto $record) => $record->update(['is_primary' => true])),

                DeleteAction::make()
                    ->modalDescription('The image files are deleted too, and that cannot be undone.'),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('created_at', 'desc')
            ->persistFiltersInSession()
            ->emptyStateIcon('heroicon-o-photo')
            ->emptyStateHeading('No temple photos yet')
            ->emptyStateDescription('Photos added inside a temple appear here. This is where to find the ones missing a credit.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTemplePhotos::route('/'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $uncredited = TemplePhoto::query()->whereNull('credit')->count();

        return $uncredited > 0 ? (string) $uncredited : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Photos with no credit recorded';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
    }

    /** Photos are uploaded against a temple, so they are added inside one. */
    public static function canCreate(): bool
    {
        return false;
    }
}
