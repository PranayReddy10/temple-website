<?php

namespace App\Filament\Pages\Settings;

use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Which ways devotees may sign in, and the client ids a Google or Apple
 * token must have been issued to before we trust it.
 */
class ManageSignIn extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'App';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Sign-in methods';

    protected static ?string $slug = 'app/sign-in';

    protected static function definitions(): array
    {
        return [
            'auth_password_enabled' => ['boolean', true],
            'auth_google_enabled' => ['boolean', false],
            'auth_google_server_client_id' => ['string', null],
            'auth_google_ios_client_id' => ['string', null],
            'auth_google_client_ids' => ['string', null],
            'auth_apple_enabled' => ['boolean', false],
            'auth_apple_client_ids' => ['string', null],
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Email or phone with a password')
                ->icon('heroicon-o-envelope')
                ->schema([
                    Toggle::make('auth_password_enabled')->label('Allow sign-up and sign-in with a password')
                        ->helperText('Turning this off hides the form in the app; accounts that already have a password keep them.'),
                ]),

            Section::make('Google')
                ->description('Create OAuth clients in Google Cloud Console → APIs & Services → Credentials: one "Web application" (its id goes in the first box), one Android (package name and SHA-1 of the signing key) and one iOS.')
                ->icon('heroicon-o-globe-alt')
                ->schema([
                    Toggle::make('auth_google_enabled')->label('Show "Continue with Google"'),
                    TextInput::make('auth_google_server_client_id')->label('Web client id (server client id)')
                        ->placeholder('1234567890-abc.apps.googleusercontent.com')
                        ->helperText('The app asks Google for a token addressed to this id, and the server checks it.'),
                    TextInput::make('auth_google_ios_client_id')->label('iOS client id')
                        ->helperText('Also add its reversed id as a URL scheme in ios/Runner/Info.plist.'),
                    Textarea::make('auth_google_client_ids')->label('Other accepted client ids (optional)')->rows(2)
                        ->helperText('One per line or comma separated. The web and iOS ids above are always accepted.'),
                ]),

            Section::make('Apple')
                ->description('Shown on iPhone and iPad only. Enable "Sign in with Apple" for the app id in the Apple Developer account and the Xcode project.')
                ->icon('heroicon-o-device-phone-mobile')
                ->schema([
                    Toggle::make('auth_apple_enabled')->label('Show "Sign in with Apple" on iOS'),
                    Textarea::make('auth_apple_client_ids')->label('Accepted client ids')->rows(2)
                        ->placeholder('com.example.templepassport')
                        ->helperText('The iOS bundle id, plus a Services id if Apple sign-in is ever offered on the web.'),
                ]),
        ]);
    }
}
