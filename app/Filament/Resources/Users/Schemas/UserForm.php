<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),

                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->minLength(12)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                    // On edit, an empty password box means "leave it alone"
                    // rather than "set the password to empty".
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->helperText(fn (string $operation): string => $operation === 'create'
                        ? 'At least 12 characters.'
                        : 'Leave blank to keep the current password.'),

                Select::make('role')
                    ->options(UserRole::class)
                    ->default(UserRole::Editor)
                    ->required()
                    ->native(false)
                    ->live()
                    ->helperText(fn (Get $get): string => self::roleOf($get('role'))?->description() ?? ''),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('A deactivated account keeps its history but can no longer sign in.'),
            ])
            ->columns(2);
    }

    /** Form state may hold either a UserRole instance or its string value. */
    protected static function roleOf(mixed $state): ?UserRole
    {
        return $state instanceof UserRole ? $state : UserRole::tryFrom((string) $state);
    }
}
