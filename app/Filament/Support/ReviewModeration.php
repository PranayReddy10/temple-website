<?php

namespace App\Filament\Support;

use App\Enums\ReviewStatus;
use App\Models\TempleReview;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Accounts of visits, as staff and temples both see them.
 *
 * One definition for the moderation queue, a devotee's own record and a
 * temple's tab. Staff decide what is published; a temple's team reads what
 * was said about a visit to it and may reply, which the devotee sees. The
 * temple never approves or removes an account of itself: that is exactly
 * the conflict moderation exists to keep out.
 */
class ReviewModeration
{
    /** @return array<int, mixed> */
    public static function columns(bool $withDevotee = true, bool $withTemple = true): array
    {
        return array_values(array_filter([
            $withDevotee ? TextColumn::make('devotee.name')->label('Devotee')->searchable()->weight('medium') : null,

            $withTemple ? TextColumn::make('temple.name')->label('Temple')->searchable()->wrap() : null,

            TextColumn::make('visited_on')->label('Visited')->date('d M Y')->sortable(),

            TextColumn::make('ratings')
                ->label('The visit')
                ->state(fn (TempleReview $record): string => collect($record->ratings())
                    ->map(fn (int $v, string $k): string => str(TempleReview::DIMENSIONS[$k]['label'])->before(' ')->toString().' '.$v.'/5')
                    ->implode(' · ') ?: '—')
                ->description(fn (TempleReview $record): ?string => $record->wait_minutes === null ? null : 'Waited '.$record->wait_minutes.' min')
                ->wrap(),

            TextColumn::make('body')->label('What they wrote')->placeholder('—')->limit(80)->wrap()
                ->tooltip(fn (TempleReview $record): ?string => $record->body),

            TextColumn::make('status')->label('Moderation')->badge()->sortable(),

            TextColumn::make('temple_reply')->label('Temple replied')->placeholder('—')->limit(40)->toggleable(),

            TextColumn::make('created_at')->label('Written')->since()->sortable(),
        ]));
    }

    /** @return array<int, mixed> */
    public static function filters(): array
    {
        return [
            Filter::make('awaiting')
                ->label('Waiting for review')
                ->query(fn (Builder $query): Builder => $query->awaitingModeration())
                ->toggle(),
            SelectFilter::make('status')->label('Moderation')->options(ReviewStatus::class)->multiple(),
            Filter::make('unanswered')
                ->label('Published, no reply from the temple')
                ->query(fn (Builder $query): Builder => $query->approved()->whereNull('temple_reply'))
                ->toggle(),
        ];
    }

    /** @return array<int, mixed> */
    public static function recordActions(bool $templeMayReply = false): array
    {
        return [
            Action::make('read')
                ->label('Read')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->modalHeading(fn (TempleReview $record): string => ($record->devotee?->name ?? 'A devotee').' on '.($record->temple?->name ?? 'the temple'))
                ->modalContent(fn (TempleReview $record): View => view('filament.reviews.details', ['review' => $record->load(['devotee', 'temple', 'moderator', 'replier'])]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),

            Action::make('approve')
                ->label('Publish')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (TempleReview $record): bool => $record->status !== ReviewStatus::Approved && self::canModerate())
                ->requiresConfirmation()
                ->modalDescription('It becomes visible on the temple\'s page in the app, with the devotee\'s first name.')
                ->action(fn (TempleReview $record) => $record->update([
                    'status' => ReviewStatus::Approved,
                    'moderated_by' => Auth::id(),
                    'moderated_at' => now(),
                    'moderation_note' => null,
                ])),

            Action::make('reject')
                ->label('Do not publish')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (TempleReview $record): bool => $record->status !== ReviewStatus::Rejected && self::canModerate())
                ->schema([
                    Textarea::make('moderation_note')
                        ->label('Reason')
                        ->required()
                        ->rows(2)
                        ->helperText('The devotee sees this in the app.'),
                ])
                ->action(fn (TempleReview $record, array $data) => $record->update([
                    'status' => ReviewStatus::Rejected,
                    'moderated_by' => Auth::id(),
                    'moderated_at' => now(),
                    'moderation_note' => $data['moderation_note'],
                ])),

            Action::make('reply')
                ->label(fn (TempleReview $record): string => $record->temple_reply === null ? 'Reply' : 'Edit reply')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('primary')
                ->visible(fn (TempleReview $record): bool => $templeMayReply || self::canModerate())
                ->fillForm(fn (TempleReview $record): array => ['temple_reply' => $record->temple_reply])
                ->schema([
                    Textarea::make('temple_reply')
                        ->label('The temple\'s reply')
                        ->required()
                        ->rows(3)
                        ->maxLength(1000)
                        ->helperText('Shown under the account in the app, as the temple.'),
                ])
                ->action(function (TempleReview $record, array $data): void {
                    $record->update([
                        'temple_reply' => $data['temple_reply'],
                        'temple_replied_at' => now(),
                        'temple_replied_by' => Auth::id(),
                    ]);
                    Notification::make()->title('Reply saved.')->success()->send();
                }),
        ];
    }

    public static function canModerate(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
    }
}
