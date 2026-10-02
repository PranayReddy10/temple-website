<?php

namespace App\Filament\Resources\Devotees;

use App\Enums\Gender;
use App\Models\Devotee;
use App\Models\State;
use App\Support\Locales;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * What support may change on a devotee's account: their details, when they
 * ask and cannot do it in the app, and confirming an email or phone number
 * staff have checked themselves (by calling the number, or the devotee
 * writing from that address). Super admins only. A changed email or phone
 * is no longer confirmed: the new one has not been checked.
 */
final class DevoteeAdminActions
{
    public static function allowed(): bool
    {
        return Auth::user()?->canManageUsers() ?? false;
    }

    public static function edit(): Action
    {
        return Action::make('edit_details')
            ->label('Edit details')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (): bool => self::allowed())
            ->modalHeading(fn (Devotee $record): string => 'Edit '.$record->name)
            ->modalDescription('Only at the devotee\'s request. A changed email or phone number is no longer marked verified.')
            ->fillForm(fn (Devotee $record): array => [
                'name' => $record->name,
                'email' => $record->email,
                'phone' => $record->phone,
                'gender' => $record->gender?->value,
                'date_of_birth' => $record->date_of_birth?->toDateString(),
                'home_state_id' => $record->home_state_id,
                'locale' => $record->locale,
            ])
            ->schema([
                TextInput::make('name')->required()->maxLength(120),
                TextInput::make('email')->email()->maxLength(255)
                    ->rules(fn (Devotee $record): array => [Rule::unique('devotees', 'email')->ignore($record->getKey())]),
                TextInput::make('phone')->tel()->maxLength(20)->regex('/^[0-9+ ()-]{6,20}$/')
                    ->rules(fn (Devotee $record): array => [Rule::unique('devotees', 'phone')->ignore($record->getKey())]),
                Select::make('gender')->options(collect(Gender::cases())->mapWithKeys(fn (Gender $g) => [$g->value => ucfirst(str_replace('_', ' ', $g->value))])->all())->native(false),
                DatePicker::make('date_of_birth')->label('Date of birth')->maxDate(now())->native(false),
                Select::make('home_state_id')->label('Home state')->options(fn (): array => State::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                Select::make('locale')->label('Language')->options(fn (): array => collect(Locales::supported())->map(fn (array $l): string => $l['name'])->all())->native(false),
            ])
            ->action(function (Devotee $record, array $data): void {
                $email = filled($data['email'] ?? null) ? strtolower(trim($data['email'])) : null;
                $phone = filled($data['phone'] ?? null) ? trim($data['phone']) : null;

                if ($email === null && $phone === null) {
                    Notification::make()->title('Keep an email or a phone number: it is how they sign in.')->danger()->send();

                    return;
                }

                $record->fill([
                    'name' => $data['name'],
                    'email' => $email,
                    'phone' => $phone,
                    'gender' => $data['gender'] ?? null,
                    'date_of_birth' => $data['date_of_birth'] ?? null,
                    'home_state_id' => $data['home_state_id'] ?? null,
                    'locale' => $data['locale'] ?? $record->locale,
                ]);

                if ($record->isDirty('email')) {
                    $record->email_verified_at = null;
                }
                if ($record->isDirty('phone')) {
                    $record->phone_verified_at = null;
                }

                $record->save();
                Notification::make()->title('Details saved.')->success()->send();
            });
    }

    public static function verifyEmail(): Action
    {
        return Action::make('verify_email')
            ->label('Mark email verified')
            ->icon('heroicon-o-envelope-open')
            ->color('success')
            ->visible(fn (Devotee $record): bool => self::allowed() && filled($record->email) && $record->email_verified_at === null)
            ->requiresConfirmation()
            ->modalDescription(fn (Devotee $record): string => 'Only once you know '.$record->email.' is theirs, for example they wrote to support from it.')
            ->action(function (Devotee $record): void {
                $record->forceFill(['email_verified_at' => now()])->save();
                Notification::make()->title('Email marked verified.')->success()->send();
            });
    }

    public static function verifyPhone(): Action
    {
        return Action::make('verify_phone')
            ->label('Mark phone verified')
            ->icon('heroicon-o-phone')
            ->color('success')
            ->visible(fn (Devotee $record): bool => self::allowed() && filled($record->phone) && $record->phone_verified_at === null)
            ->requiresConfirmation()
            ->modalDescription(fn (Devotee $record): string => 'Only once you know '.$record->phone.' is theirs, for example by calling it.')
            ->action(function (Devotee $record): void {
                $record->forceFill(['phone_verified_at' => now()])->save();
                Notification::make()->title('Phone marked verified.')->success()->send();
            });
    }

    public static function unverify(): Action
    {
        return Action::make('unverify')
            ->label('Remove verification')
            ->icon('heroicon-o-shield-exclamation')
            ->color('warning')
            ->visible(fn (Devotee $record): bool => self::allowed() && $record->isVerified())
            ->requiresConfirmation()
            ->modalDescription('Marks both the email and the phone number as not confirmed, for an account that looks like it is not who it claims.')
            ->action(function (Devotee $record): void {
                $record->forceFill(['email_verified_at' => null, 'phone_verified_at' => null])->save();
                Notification::make()->title('Verification removed.')->success()->send();
            });
    }
}
