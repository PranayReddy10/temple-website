<?php

namespace App\Filament\Support;

use App\Enums\PhotoModerationStatus;
use App\Filament\Support\MediaColumn;
use App\Models\VisitPhoto;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The columns, filters and decisions shared by both views of an uploaded
 * photo: the moderation queue, and a devotee's own uploads.
 *
 * One definition so a decision made from either place records the same thing.
 * A moderator approving from inside an account and one approving from the
 * queue must not produce differently-shaped rows.
 */
class PhotoModeration
{
    /** @return array<int, mixed> */
    public static function columns(bool $withDevotee = true): array
    {
        return array_values(array_filter([
            // The stamp, because that is what would be published. The
            // original is one click away on the record.
            MediaColumn::make(
                'preview',
                fn (VisitPhoto $record): ?string => $record->stamp_path ?? $record->original_path,
            )->label('')->height(48),

            $withDevotee
                ? TextColumn::make('devotee.name')->label('Devotee')->searchable()->weight('medium')
                : null,

            TextColumn::make('temple.name')->label('Temple')->searchable()->wrap(),

            TextColumn::make('caption')->label('Caption')->placeholder('—')->limit(40)->toggleable(),

            TextColumn::make('status')->label('Moderation')->badge()->sortable(),

            IconColumn::make('is_public')
                ->label('Devotee shared')
                ->boolean()
                ->tooltip('Whether the devotee asked for this to be public. Approval alone does not publish it.'),

            IconColumn::make('is_visible_to_others')
                ->label('Visible')
                ->state(fn (VisitPhoto $record): bool => $record->isVisibleToOthers())
                ->boolean()
                ->tooltip('Approved and shared: both are required'),

            TextColumn::make('moderator.name')->label('Decided by')->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('created_at')->label('Uploaded')->since()->sortable(),
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

            SelectFilter::make('status')->label('Moderation')->options(PhotoModerationStatus::class)->multiple(),

            Filter::make('requested_public')
                ->label('Devotee asked to share')
                ->query(fn (Builder $query): Builder => $query->where('is_public', true))
                ->toggle(),
        ];
    }

    /** @return array<int, mixed> */
    public static function recordActions(): array
    {
        return [
            Action::make('view_original')
                ->label('Original')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->url(fn (VisitPhoto $record): ?string => $record->originalUrl())
                ->openUrlInNewTab()
                ->visible(fn (VisitPhoto $record): bool => filled($record->originalUrl())),

            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (VisitPhoto $record): bool => $record->status !== PhotoModerationStatus::Approved
                    && self::canModerate())
                ->requiresConfirmation()
                ->modalDescription('If the devotee asked for it to be shared, it becomes visible to others.')
                ->action(fn (VisitPhoto $record) => $record->update([
                    'status' => PhotoModerationStatus::Approved,
                    'moderated_by' => Auth::id(),
                    'moderated_at' => now(),
                    'moderation_note' => null,
                ])),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (VisitPhoto $record): bool => $record->status !== PhotoModerationStatus::Rejected
                    && self::canModerate())
                ->form([
                    Textarea::make('moderation_note')
                        ->label('Reason')
                        ->required()
                        ->rows(2)
                        // Returned to the devotee through the API: silence
                        // reads as failure, and they will upload it again.
                        ->helperText('The devotee sees this in the app.'),
                ])
                ->action(fn (VisitPhoto $record, array $data) => $record->update([
                    'status' => PhotoModerationStatus::Rejected,
                    'moderated_by' => Auth::id(),
                    'moderated_at' => now(),
                    'moderation_note' => $data['moderation_note'],
                ])),
        ];
    }

    /** Moderating user-uploaded imagery is an editorial-staff job. */
    public static function canModerate(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
    }
}
