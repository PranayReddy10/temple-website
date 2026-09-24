<?php

namespace App\Filament\Pages\Settings;

use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Firebase Cloud Messaging.
 *
 * The app initialises Firebase from the public ids below, which it reads from
 * /app/config, so no google-services.json has to be built into it. The
 * service account is what lets this server send; it never leaves the server.
 */
class ManagePush extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string|\UnitEnum|null $navigationGroup = 'App';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Push setup (Firebase)';

    protected static ?string $slug = 'app/push';

    protected static function definitions(): array
    {
        return [
            'push_enabled' => ['boolean', false],
            'firebase_project_id' => ['string', null],
            'firebase_messaging_sender_id' => ['string', null],
            'firebase_android_api_key' => ['string', null],
            'firebase_android_app_id' => ['string', null],
            'firebase_ios_api_key' => ['string', null],
            'firebase_ios_app_id' => ['string', null],
            'firebase_ios_bundle_id' => ['string', null],
            'firebase_service_account' => ['secret', null],
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Push notifications')
                ->icon('heroicon-o-bell')
                ->schema([
                    Toggle::make('push_enabled')->label('Send push notifications')
                        ->helperText('When off, notifications still appear in the app\'s inbox; nothing is pushed to phones.'),
                ]),
            Section::make('Firebase project')
                ->description('Firebase console → Project settings → General. Add an Android app and an iOS app, then copy their ids here.')
                ->columns(2)
                ->schema([
                    TextInput::make('firebase_project_id')->label('Project id'),
                    TextInput::make('firebase_messaging_sender_id')->label('Sender id (project number)'),
                    TextInput::make('firebase_android_api_key')->label('Android API key'),
                    TextInput::make('firebase_android_app_id')->label('Android app id')->placeholder('1:123:android:abc'),
                    TextInput::make('firebase_ios_api_key')->label('iOS API key'),
                    TextInput::make('firebase_ios_app_id')->label('iOS app id')->placeholder('1:123:ios:abc'),
                    TextInput::make('firebase_ios_bundle_id')->label('iOS bundle id')->columnSpanFull(),
                ]),
            Section::make('Server key')
                ->description('Project settings → Service accounts → Generate new private key. Paste the whole JSON file. iOS also needs an APNs key uploaded under Cloud Messaging.')
                ->schema([
                    static::secretInput('firebase_service_account', 'Service account JSON'),
                ]),
        ]);
    }
}
