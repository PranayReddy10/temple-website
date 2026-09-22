<?php

namespace App\Filament\Resources\TempleAccess\Schemas;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The one form for granting a temple's own team access.
 *
 * Shared by the side-menu resource and by the relation manager nested inside
 * a temple, so the two cannot drift apart. Only the temple field differs:
 * nested inside a temple it is already decided.
 */
class TempleAccessForm
{
    public static function configure(Schema $schema, bool $withTemple = true): Schema
    {
        return $schema
            ->components(array_values(array_filter([
                $withTemple ? self::templeField() : null,
                self::accountField(),
                self::levelField(),
                self::noteField(),
            ])))
            ->columns(2);
    }

    public static function templeField(): Select
    {
        return Select::make('temple_id')
            ->label('Temple')
            ->relationship('temple', 'name')
            ->searchable()
            ->preload()
            ->required()
            ->native(false);
    }

    /**
     * The account being given access.
     *
     * Only Temple Admin accounts can be listed: the portal gate keys off the
     * role, so handing the seat to an editor would grant nothing and look
     * broken. That used to leave an empty dropdown with no way forward the
     * first time anyone opened this screen, so the account can be created
     * here in one step instead of being sent to another page to come back.
     */
    public static function accountField(): Select
    {
        return Select::make('user_id')
            ->label('Account')
            ->options(fn (): array => User::query()
                ->where('role', UserRole::TempleAdmin)
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (User $user): array => [
                    $user->getKey() => $user->name.' — '.$user->email,
                ])
                ->all())
            ->searchable()
            ->required()
            ->native(false)
            ->helperText('Temple Admin accounts only — they sign in at /temple, not /admin. Nobody listed? Use the + button beside this field to create the account here.')
            ->createOptionForm([
                TextInput::make('name')
                    ->label('Person or trust name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('Sign-in email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(table: User::class, column: 'email')
                    ->helperText('They sign in with this at /temple.'),

                TextInput::make('password')
                    ->label('Temporary password')
                    ->password()
                    ->revealable()
                    ->minLength(12)
                    ->required()
                    ->default(fn (): string => Str::password(16))
                    ->helperText('Copy this before saving — it is hashed and cannot be read back. Ask them to change it from their profile.'),
            ])
            ->createOptionUsing(fn (array $data): int => User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                // The seat is worthless under any other role: the portal
                // checks the role, not this claim.
                'role' => UserRole::TempleAdmin,
                'is_active' => true,
            ])->getKey())
            ->createOptionModalHeading('Create a temple account')
            ->createOptionAction(fn ($action) => $action->modalSubmitActionLabel('Create account'));
    }

    public static function levelField(): Select
    {
        return Select::make('role')
            ->label('Level')
            ->options(self::levels())
            ->default('manager')
            ->required()
            ->native(false);
    }

    public static function noteField(): Textarea
    {
        return Textarea::make('claim_note')
            ->label('Claim note')
            ->rows(3)
            ->helperText('How the claim was verified, e.g. confirmed by phone with the temple office.')
            ->columnSpanFull();
    }

    /** @return array<string, string> */
    public static function levels(): array
    {
        return [
            'owner' => 'Owner — the trust or temple office',
            'manager' => 'Manager — day-to-day staff',
        ];
    }
}
