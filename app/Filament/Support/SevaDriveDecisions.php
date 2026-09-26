<?php

namespace App\Filament\Support;

use App\Enums\SevaDriveStatus;
use App\Models\SevaDrive;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Auth;

/**
 * What staff decide about a seva drive, defined once.
 *
 * Shared by the queue and the drive's own page, so approving from either
 * place records the same thing.
 */
class SevaDriveDecisions
{
    /** @return array<int, Action> */
    public static function actions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (SevaDrive $record): bool => in_array($record->status, [SevaDriveStatus::Pending, SevaDriveStatus::Rejected], true))
                ->requiresConfirmation()
                ->modalHeading('Open this drive to volunteers?')
                ->modalDescription('It will be listed in the app and anybody signed in can join. Check the place, the date and the plan are what they appear to be.')
                ->action(fn (SevaDrive $record) => self::decide($record, SevaDriveStatus::Approved)),

            Action::make('reject')
                ->label('Turn down')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (SevaDrive $record): bool => in_array($record->status, [SevaDriveStatus::Pending, SevaDriveStatus::Approved], true))
                ->form([
                    Textarea::make('moderation_note')
                        ->label('Why')
                        ->required()
                        ->rows(3)
                        // Shown in the app: an organiser told nothing will
                        // raise the same drive again.
                        ->helperText('The organiser sees this in the app, and can edit the drive and send it back.'),
                ])
                ->action(fn (SevaDrive $record, array $data) => self::decide($record, SevaDriveStatus::Rejected, $data['moderation_note'])),

            Action::make('verify')
                ->label('Verify the work')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (SevaDrive $record): bool => $record->status === SevaDriveStatus::Completed)
                ->requiresConfirmation()
                ->modalHeading('Verify this drive?')
                ->modalDescription(fn (SevaDrive $record): string => 'Compare the before and after photographs first. Verifying shows a Verified badge'
                    .(filled($record->upi_id) ? ' and opens donations to '.$record->upi_id.'.' : '.'))
                ->action(function (SevaDrive $record): void {
                    $record->status = SevaDriveStatus::Verified;
                    $record->verified_at = now();
                    $record->verified_by = Auth::id();
                    $record->save();
                }),

            Action::make('send_back')
                ->label('Not verified')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (SevaDrive $record): bool => in_array($record->status, [SevaDriveStatus::Completed, SevaDriveStatus::Verified], true))
                ->form([
                    Textarea::make('moderation_note')
                        ->label('What is missing')
                        ->required()
                        ->rows(3)
                        ->helperText('The organiser sees this, adds what is asked for, and marks it done again. Donations close meanwhile.'),
                ])
                ->action(function (SevaDrive $record, array $data): void {
                    $record->status = SevaDriveStatus::Approved;
                    $record->verified_at = null;
                    $record->verified_by = null;
                    $record->moderation_note = $data['moderation_note'];
                    $record->save();
                }),

            Action::make('toggle_donations')
                ->label(fn (SevaDrive $record): string => $record->donations_enabled ? 'Pause donations' : 'Resume donations')
                ->icon(fn (SevaDrive $record): string => $record->donations_enabled ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                ->color('gray')
                ->visible(fn (SevaDrive $record): bool => filled($record->upi_id))
                ->requiresConfirmation()
                ->modalDescription('Paused, the UPI ID stops being shown in the app. Nothing else about the drive changes.')
                ->action(function (SevaDrive $record): void {
                    $record->donations_enabled = ! $record->donations_enabled;
                    $record->save();
                }),

            /*
             * Misleading: the drive stays up with a warning, because people
             * who joined or gave need to see what happened to it; joining and
             * donations close.
             */
            Action::make('mark_misleading')
                ->label('Mark misleading')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->visible(fn (SevaDrive $record): bool => ! $record->is_misleading && $record->status !== SevaDriveStatus::Blocked)
                ->form([
                    Textarea::make('misleading_note')
                        ->label('What is misleading')
                        ->required()
                        ->rows(3)
                        ->helperText('Shown on the drive in the app, as a warning to everybody. Joining and donations close.'),
                ])
                ->action(fn (SevaDrive $record, array $data) => $record->markMisleading($data['misleading_note'])),

            Action::make('clear_misleading')
                ->label('Remove misleading warning')
                ->icon('heroicon-o-check-circle')
                ->color('gray')
                ->visible(fn (SevaDrive $record): bool => $record->is_misleading)
                ->requiresConfirmation()
                ->action(fn (SevaDrive $record) => $record->markMisleading(null)),

            /*
             * Blocked: taken down for everybody but staff and the organiser,
             * who sees the reason. Unblocking puts it back as it was.
             */
            Action::make('block')
                ->label('Block')
                ->icon('heroicon-o-shield-exclamation')
                ->color('danger')
                ->visible(fn (SevaDrive $record): bool => $record->status !== SevaDriveStatus::Blocked)
                ->form([
                    Textarea::make('block_reason')
                        ->label('Why')
                        ->required()
                        ->rows(3)
                        ->helperText('The organiser sees this. Nobody else can open the drive while it is blocked.'),
                ])
                ->action(fn (SevaDrive $record, array $data) => $record->block($data['block_reason'])),

            Action::make('unblock')
                ->label('Unblock')
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->visible(fn (SevaDrive $record): bool => $record->status === SevaDriveStatus::Blocked)
                ->requiresConfirmation()
                ->modalDescription(fn (SevaDrive $record): string => 'It goes back to "'
                    .(SevaDriveStatus::tryFrom((string) $record->status_before_block)?->getLabel() ?? 'Waiting for review').'".')
                ->action(fn (SevaDrive $record) => $record->unblock()),
        ];
    }

    protected static function decide(SevaDrive $drive, SevaDriveStatus $status, ?string $note = null): void
    {
        $drive->status = $status;
        $drive->moderated_by = Auth::id();
        $drive->moderated_at = now();
        $drive->moderation_note = $note;
        $drive->save();
    }
}
