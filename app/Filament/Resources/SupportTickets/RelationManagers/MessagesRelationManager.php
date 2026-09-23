<?php

namespace App\Filament\Resources\SupportTickets\RelationManagers;

use App\Models\SupportTicketMessage;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The conversation, replies and internal notes in one chronology.
 *
 * Together rather than on separate tabs, because the order is the story: a
 * note written before a reply is the reasoning behind it, and separating them
 * loses which came first. What keeps them apart is the flag and the colour,
 * and the reporter's copy is filtered by the model's `replies()` relation, so
 * nothing here can leak a note by being rendered on the wrong screen.
 */
class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Conversation';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-chat-bubble-left-right';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('author'))
            ->columns([
                IconColumn::make('is_internal')
                    ->label('')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-paper-airplane')
                    ->trueColor('gray')
                    ->falseColor('primary')
                    ->tooltip(fn (SupportTicketMessage $record): string => $record->is_internal
                        ? 'Internal note — never shown to the reporter'
                        : 'Sent to the reporter'),

                TextColumn::make('body')
                    ->label('Message')
                    ->wrap()
                    ->color(fn (SupportTicketMessage $record): ?string => $record->is_internal ? 'gray' : null),

                TextColumn::make('author')
                    ->label('By')
                    ->state(fn (SupportTicketMessage $record): string => $record->authorName()),

                TextColumn::make('created_at')->label('When')->dateTime('d M, H:i')->sortable(),
            ])
            ->filters([
                Filter::make('replies_only')
                    ->label('What the reporter sees')
                    ->query(fn (Builder $query): Builder => $query->visibleToReporter())
                    ->toggle(),
            ])
            ->recordActions([
                /*
                 * "Delete note", not a generic delete.
                 *
                 * Nothing here is editable and a reply is never removable: a
                 * sent reply cannot be unsent, and a record of a conversation
                 * that can be rewritten afterwards is not a record of
                 * anything. Only a note — which nobody outside the team has
                 * seen — can be taken back, and naming the action for that
                 * says so on the button rather than in a modal after the
                 * click.
                 */
                Action::make('delete_note')
                    ->label('Delete note')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (SupportTicketMessage $record): bool => $record->is_internal)
                    ->requiresConfirmation()
                    ->modalHeading('Delete this internal note?')
                    ->modalDescription('Replies that have been sent are kept as sent; only notes can be removed.')
                    ->action(fn (SupportTicketMessage $record) => $record->delete()),
            ])
            ->defaultSort('created_at')
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right')
            ->emptyStateHeading('Nothing said yet')
            ->emptyStateDescription('Use Reply above to write back, or Add a note for something only the team should see.');
    }
}
