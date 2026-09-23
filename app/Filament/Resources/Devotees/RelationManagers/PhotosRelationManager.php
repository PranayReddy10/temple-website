<?php

namespace App\Filament\Resources\Devotees\RelationManagers;

use App\Filament\Support\PhotoModeration;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * This devotee's uploads, with the same moderation actions as the queue.
 *
 * Both views share PhotoModeration so a decision made from inside an account
 * and one made from the queue cannot behave differently — and so that
 * "approve everything this person sent" is a thing you can actually do while
 * looking at the person.
 */
class PhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';

    protected static ?string $title = 'Photos';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-camera';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('temple:id,name'))
            ->columns(PhotoModeration::columns(withDevotee: false))
            ->filters(PhotoModeration::filters())
            ->recordActions(PhotoModeration::recordActions())
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-camera')
            ->emptyStateHeading('No photos uploaded');
    }
}
