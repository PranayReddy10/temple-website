<?php

namespace App\Filament\Resources\Temples\RelationManagers;

use App\Filament\Support\ReviewModeration;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * What devotees said about visiting this temple.
 *
 * In the admin, with the moderation decisions. In the temple portal, the
 * temple's team reads the published accounts and may reply; it sees
 * nothing that is not published, and cannot publish or remove anything.
 */
class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    protected static ?string $title = 'Visit reviews';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-chat-bubble-bottom-center-text';

    public function table(Table $table): Table
    {
        $staff = Auth::user()?->role?->isStaff() ?? false;

        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query) => $staff ? $query->with('devotee:id,name') : $query->approved()->with('devotee:id,name'))
            ->columns(ReviewModeration::columns(withTemple: false))
            ->filters($staff ? ReviewModeration::filters() : [])
            ->recordActions(ReviewModeration::recordActions(templeMayReply: true))
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-chat-bubble-bottom-center-text')
            ->emptyStateHeading('No reviews yet')
            ->emptyStateDescription($staff ? 'Accounts devotees write about visiting this temple appear here.' : 'Published accounts of visits appear here. You can reply to each one; the devotee sees your reply in the app.');
    }

    public static function getBadge(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): ?string
    {
        $staff = Auth::user()?->role?->isStaff() ?? false;
        $count = $staff ? $ownerRecord->reviews()->awaitingModeration()->count() : $ownerRecord->reviews()->approved()->whereNull('temple_reply')->count();

        return $count > 0 ? (string) $count : null;
    }
}
