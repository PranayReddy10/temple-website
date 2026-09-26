<?php

namespace App\Filament\Resources\TempleReviews;

use App\Filament\Resources\TempleReviews\Pages\ListTempleReviews;
use App\Filament\Support\ReviewModeration;
use App\Models\TempleReview;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The moderation queue for devotees' accounts of visits.
 *
 * Words attached by name to places of worship, so nothing reaches another
 * devotee until a person has read it. Worked oldest first, like every queue.
 */
class TempleReviewResource extends Resource
{
    protected static ?string $model = TempleReview::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Devotees';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Visit reviews';

    protected static ?string $modelLabel = 'review';

    protected static ?string $slug = 'visit-reviews';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['devotee:id,name', 'temple:id,name', 'moderator:id,name']))
            ->columns(ReviewModeration::columns())
            ->filters([
                ...ReviewModeration::filters(),
                SelectFilter::make('temple')->relationship('temple', 'name')->searchable()->preload(),
            ])
            ->recordActions(ReviewModeration::recordActions())
            ->defaultSort('created_at', 'asc')
            ->persistFiltersInSession()
            ->emptyStateIcon('heroicon-o-chat-bubble-bottom-center-text')
            ->emptyStateHeading('No reviews yet')
            ->emptyStateDescription('What devotees write about visiting a temple arrives here before anyone else reads it. The visit is rated, never the temple.');
    }

    public static function getPages(): array
    {
        return ['index' => ListTempleReviews::route('/')];
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = TempleReview::query()->awaitingModeration()->count();

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
