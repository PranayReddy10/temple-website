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
                ->modalHeading('List this drive?')
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

            /*
             * Verification is a badge, not a stage: it says the team has
             * checked the drive is genuine. It does not end the drive — that
             * happens on its last day, or when the organiser says so.
             */
            Action::make('verify')
                ->label('Verify')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (SevaDrive $record): bool => ! $record->isVerified() && $record->status->isPublic())
                ->requiresConfirmation()
                ->modalHeading('Verify this drive?')
                ->modalDescription(fn (SevaDrive $record): string => 'Check the place, the photographs and the organiser are genuine. It gets a Verified badge'
                    .(filled($record->upi_id) ? ' and opens for donations to '.$record->upi_id : '')
                    .'. It stays '.mb_strtolower($record->effectiveStatus()?->getLabel() ?? '').'.')
                ->action(fn (SevaDrive $record) => $record->verify(Auth::id())),

            Action::make('decline_verification')
                ->label('Decline verification')
                ->icon('heroicon-o-x-circle')
                ->color('warning')
                ->visible(fn (SevaDrive $record): bool => $record->verificationPending())
                ->form([
                    Textarea::make('reason')
                        ->label('What is missing')
                        ->required()
                        ->rows(3)
                        ->helperText('The organiser sees this, adds what is asked for, and can ask again. The drive stays listed as not verified.'),
                ])
                ->action(fn (SevaDrive $record, array $data) => $record->unverify($data['reason'])),

            Action::make('unverify')
                ->label('Remove verification')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (SevaDrive $record): bool => $record->isVerified())
                ->form([
                    Textarea::make('reason')
                        ->label('Why')
                        ->required()
                        ->rows(3)
                        ->helperText('The organiser sees this. The badge goes and donations close; the drive stays listed.'),
                ])
                ->action(fn (SevaDrive $record, array $data) => $record->unverify($data['reason'])),

            Action::make('complete')
                ->label('Mark completed')
                ->icon('heroicon-o-flag')
                ->color('gray')
                ->visible(fn (SevaDrive $record): bool => $record->status === SevaDriveStatus::Approved)
                ->requiresConfirmation()
                ->modalDescription('It stops taking volunteers and moves to Completed. Drives also complete by themselves once their last day is over.')
                ->action(function (SevaDrive $record): void {
                    $record->status = SevaDriveStatus::Completed;
                    $record->completed_at = now();
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
