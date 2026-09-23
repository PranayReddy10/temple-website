<?php

namespace App\Filament\Resources\VisitPhotos;

use App\Enums\PhotoModerationStatus;
use App\Filament\Resources\VisitPhotos\Pages\ListVisitPhotos;
use App\Filament\Support\PhotoModeration;
use App\Models\VisitPhoto;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The Photo Stamp moderation queue.
 *
 * User-uploaded imagery attached by name to real places of worship. Nothing
 * here reaches another devotee until someone has looked at it, which is why
 * the queue is a first-class screen rather than something reached through an
 * account.
 */
class VisitPhotoResource extends Resource
{
    protected static ?string $model = VisitPhoto::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-camera';

    protected static string|\UnitEnum|null $navigationGroup = 'Devotees';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Photo Stamps';

    protected static ?string $modelLabel = 'photo';

    protected static ?string $slug = 'photo-stamps';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'devotee:id,name', 'temple:id,name', 'moderator:id,name',
            ]))
            ->columns(PhotoModeration::columns())
            ->filters(PhotoModeration::filters())
            ->recordActions(PhotoModeration::recordActions())
            // Oldest first: a queue worked newest-first leaves the people who
            // have been waiting longest waiting longest.
            ->defaultSort('created_at', 'asc')
            ->persistFiltersInSession()
            ->emptyStateIcon('heroicon-o-camera')
            ->emptyStateHeading('No photos uploaded yet')
            ->emptyStateDescription('Photos devotees add to their visits appear here for review before anyone else can see them.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVisitPhotos::route('/'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = VisitPhoto::query()->awaitingModeration()->count();

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
