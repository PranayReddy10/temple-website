<?php

namespace App\Filament\Resources\Devotees\Pages;

use App\Filament\Resources\Devotees\DevoteeAdminActions;
use App\Filament\Resources\Devotees\DevoteeResource;
use App\Models\Devotee;
use App\Support\DevoteeAccount;
use App\Support\DevoteePasswordReset;
use App\Support\InitialsAvatarProvider;
use App\Support\Locales;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * One devotee, in full.
 *
 * Built for the two questions staff actually arrive with: "who is this person
 * and are they real", when a support message comes in, and "what has this
 * account been doing", when something looks like abuse. Everything below the
 * summary is their own records, under the relation managers.
 */
class ViewDevotee extends ViewRecord
{
    protected static string $resource = DevoteeResource::class;

    public function getHeading(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        $joined = $this->record->created_at?->format('d M Y');

        return 'Joined '.($joined ?? 'unknown').' · '.$this->activityPhrase();
    }

    protected function getHeaderActions(): array
    {
        return [
            DevoteeAdminActions::edit(),
            DevoteeAdminActions::verifyEmail(),
            DevoteeAdminActions::verifyPhone(),
            DevoteeAdminActions::unverify(),
            // A deletion request from the website form (a support ticket),
            // once support has confirmed it is the owner asking.
            Action::make('delete_account')
                ->label('Delete account')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => ! $this->record->trashed() && (Auth::user()?->canManageUsers() ?? false))
                ->requiresConfirmation()
                ->modalHeading('Delete this account?')
                ->modalDescription('Deletes their profile, check-ins, photos, memories, reviews, yatras, saved and followed temples, and signs them out everywhere. Bookings and payments are kept for the accounts. This cannot be undone.')
                ->modalSubmitActionLabel('Delete account')
                ->action(function (): void {
                    DevoteeAccount::delete($this->record);
                    Notification::make()->title('Account deleted')->success()->send();
                    $this->redirect(DevoteeResource::getUrl('index'));
                }),
            // "I forgot my password", arriving as a support message.
            Action::make('send_reset_code')
                ->label('Send reset code')
                ->icon('heroicon-o-envelope')
                ->color('gray')
                ->visible(fn (): bool => filled($this->record->email)
                    && (Auth::user()?->canManageUsers() ?? false))
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Emails a 6-digit code to '.$this->record->email.' that they type into the app to choose a new password.')
                ->action(function (): void {
                    try {
                        DevoteePasswordReset::send($this->record);
                        Notification::make()->title('Code sent to '.$this->record->email)->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Could not send the email')
                            ->body('Check App → Email (SMTP). '.$e->getMessage())->danger()->persistent()->send();
                    }
                }),

            Action::make('set_password')
                ->label('Set new password')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->visible(fn (): bool => Auth::user()?->canManageUsers() ?? false)
                ->modalDescription('For when they cannot receive email. Tell them the password privately; they are signed out on every device.')
                ->schema([
                    TextInput::make('password')->label('New password')->password()->revealable()
                        ->required()->minLength(8)->autocomplete('new-password'),
                ])
                ->action(function (array $data): void {
                    DevoteePasswordReset::setPassword($this->record, $data['password']);
                    Notification::make()->title('Password changed')->success()->send();
                }),

            Action::make('deactivate')
                ->label('Suspend account')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn (): bool => (bool) $this->record->is_active
                    && (Auth::user()?->canManageUsers() ?? false))
                ->requiresConfirmation()
                ->modalDescription('They can no longer sign in. Their visits, photos and trips are kept.')
                ->action(function (): void {
                    $this->record->forceFill(['is_active' => false])->save();
                    $this->refreshFormData(['is_active']);
                }),

            Action::make('reactivate')
                ->label('Restore access')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => ! $this->record->is_active
                    && (Auth::user()?->canManageUsers() ?? false))
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->forceFill(['is_active' => true])->save();
                    $this->refreshFormData(['is_active']);
                }),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')
                ->columns(3)
                ->schema([
                    /*
                     * Their own photo. Staff arrive here from a support message
                     * or a flagged upload, and a face is the first thing that
                     * tells them whether an account is a person or a throwaway.
                     * The disk is read from the row rather than assumed, so it
                     * keeps resolving after a move to object storage.
                     */
                    ImageEntry::make('avatar_path')
                        ->label('Photo')
                        ->disk(fn (Devotee $record): string => $record->avatar_disk ?? config('filesystems.media'))
                        ->circular()
                        // Without a size it fills its column, which makes a
                        // profile photo the loudest thing on the page.
                        ->imageSize(96)
                        ->checkFileExistence(false)
                        ->defaultImageUrl(fn (Devotee $record): string => app(InitialsAvatarProvider::class)->get($record)),

                    TextEntry::make('email')->label('Email')->copyable()->placeholder('Not given'),
                    TextEntry::make('phone')->label('Phone')->copyable()->placeholder('Not given'),

                    TextEntry::make('locale')
                        ->label('Language')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => $state === null
                            ? '—'
                            : (Locales::supported()[$state]['native'] ?? $state)),

                    IconEntry::make('is_active')->label('Can sign in')->boolean(),

                    IconEntry::make('is_verified')
                        ->label('Identity confirmed')
                        ->state(fn (Devotee $record): bool => $record->isVerified())
                        ->boolean()
                        ->tooltip('Email or phone has been confirmed'),

                    TextEntry::make('email_verified_at')->label('Email verified')->since()->placeholder('No'),
                    TextEntry::make('phone_verified_at')->label('Phone verified')->since()->placeholder('No'),
                    TextEntry::make('date_of_birth')->label('Date of birth')->date('d M Y')->placeholder('Not given'),

                    TextEntry::make('homeState.name')->label('Home state')->placeholder('Not given'),
                    TextEntry::make('gender')->label('Gender')->placeholder('Not given'),
                ]),

            Section::make('Activity')
                ->description('What this account has done, and when it was last here.')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('stamps')
                            ->label('Stamps')
                            ->state(fn (Devotee $record): string => number_format($record->stampCount()))
                            ->helperText('Verified visits, one per temple'),

                        TextEntry::make('visits_count')
                            ->label('Visits recorded')
                            ->state(fn (Devotee $record): string => number_format($record->visits()->count())),

                        TextEntry::make('yatras_count')
                            ->label('Trips')
                            ->state(fn (Devotee $record): string => number_format($record->yatras()->count())),

                        TextEntry::make('saved_count')
                            ->label('Saved temples')
                            ->state(fn (Devotee $record): string => number_format($record->savedTemples()->count())),

                        TextEntry::make('photos_count')
                            ->label('Photos uploaded')
                            ->state(fn (Devotee $record): string => number_format($record->photos()->count())),

                        TextEntry::make('memories_count')
                            ->label('Memories written')
                            ->state(fn (Devotee $record): string => number_format($record->memories()->count())),

                        TextEntry::make('sign_ins')
                            ->label('Sign-ins')
                            ->state(fn (Devotee $record): string => number_format(
                                $record->loginEvents()->succeeded()->count(),
                            )),

                        TextEntry::make('last_login')
                            ->label('Last sign-in')
                            ->state(fn (Devotee $record): string => $record->lastLoginAt()?->diffForHumans()
                                ?? 'Never'),
                    ]),
                ]),

            /*
             * Sign-in history, including the failures.
             *
             * This is the section a support conversation actually turns on:
             * "I can't get in" is answered by whether the attempts are
             * arriving at all and what they are failing on, and a run of
             * failures from addresses the devotee does not recognise is
             * something staff need to be able to see.
             */
            Section::make('Recent sign-ins')
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextEntry::make('login_history')
                        ->hiddenLabel()
                        ->state(fn (Devotee $record): string => self::loginHistory($record))
                        ->markdown(),
                ]),
        ]);
    }

    protected function activityPhrase(): string
    {
        $last = $this->record->lastLoginAt() ?? $this->record->last_seen_at;

        return $last === null
            ? 'has never signed in'
            : 'last seen '.$last->diffForHumans();
    }

    /** The last ten attempts, successes and failures alike. */
    protected static function loginHistory(Devotee $devotee): string
    {
        $events = $devotee->loginEvents()->limit(10)->get();

        if ($events->isEmpty()) {
            return '_No sign-in attempts recorded._';
        }

        $rows = $events->map(function ($event): string {
            $when = $event->occurred_at?->format('d M Y, g:i A') ?? '—';
            $outcome = $event->succeeded
                ? 'Signed in'
                : 'Failed ('.str($event->failure_reason ?? 'unknown')->replace('_', ' ').')';
            $where = $event->ip_address ?? 'unknown address';
            $what = $event->platform ?? 'unknown device';

            return "| {$when} | {$outcome} | {$where} | {$what} |";
        })->implode("\n");

        return "| When | Outcome | From | Device |\n| --- | --- | --- | --- |\n".$rows;
    }
}
