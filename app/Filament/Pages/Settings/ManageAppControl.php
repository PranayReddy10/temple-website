<?php

namespace App\Filament\Pages\Settings;

use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Maintenance mode and the update popup, as the app reads them from
 * GET /api/v1/app/config on every launch.
 */
class ManageAppControl extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static string|\UnitEnum|null $navigationGroup = 'App';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'App control';

    protected static ?string $slug = 'app/control';

    protected static function definitions(): array
    {
        return [
            'app_maintenance_enabled' => ['boolean', false],
            'app_maintenance_title' => ['string', null],
            'app_maintenance_message' => ['string', null],
            'app_maintenance_until' => ['string', null],

            'app_android_latest_version' => ['string', null],
            'app_android_min_version' => ['string', null],
            'app_android_store_url' => ['string', null],
            'app_ios_latest_version' => ['string', null],
            'app_ios_min_version' => ['string', null],
            'app_ios_store_url' => ['string', null],
            'app_update_title' => ['string', null],
            'app_update_message' => ['string', null],
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Maintenance mode')
                ->description('While on, the app shows a full-screen notice instead of its content. The admin panel and this API keep working.')
                ->icon('heroicon-o-wrench-screwdriver')
                ->schema([
                    Toggle::make('app_maintenance_enabled')->label('App is under maintenance')->live(),
                    TextInput::make('app_maintenance_title')->label('Title')->placeholder('We will be back shortly')->maxLength(120),
                    Textarea::make('app_maintenance_message')->label('Message')->rows(3)->maxLength(500)
                        ->placeholder('The app is being updated. Please try again in a little while.'),
                    DateTimePicker::make('app_maintenance_until')->label('Expected back (optional)')->seconds(false)
                        ->helperText('Shown to devotees as the time to try again. Maintenance does not switch itself off.'),
                ]),

            Section::make('Update popup')
                ->description('Versions are compared as 1.2.3. Below the minimum, the app cannot be used until updated. Below the latest, an update is offered and can be dismissed.')
                ->icon('heroicon-o-arrow-up-circle')
                ->columns(2)
                ->schema([
                    TextInput::make('app_android_latest_version')->label('Android — latest version')->placeholder('0.6.0')->rule('nullable|regex:/^\d+(\.\d+){0,3}$/'),
                    TextInput::make('app_android_min_version')->label('Android — minimum supported')->placeholder('0.5.0')->rule('nullable|regex:/^\d+(\.\d+){0,3}$/'),
                    TextInput::make('app_android_store_url')->label('Play Store link')->url()->columnSpanFull()
                        ->placeholder('https://play.google.com/store/apps/details?id=…'),
                    TextInput::make('app_ios_latest_version')->label('iOS — latest version')->placeholder('0.6.0')->rule('nullable|regex:/^\d+(\.\d+){0,3}$/'),
                    TextInput::make('app_ios_min_version')->label('iOS — minimum supported')->placeholder('0.5.0')->rule('nullable|regex:/^\d+(\.\d+){0,3}$/'),
                    TextInput::make('app_ios_store_url')->label('App Store link')->url()->columnSpanFull()
                        ->placeholder('https://apps.apple.com/app/id…'),
                    TextInput::make('app_update_title')->label('Popup title')->placeholder('A new version is ready')->maxLength(120)->columnSpanFull(),
                    Textarea::make('app_update_message')->label('What is new')->rows(3)->maxLength(1000)->columnSpanFull(),
                ]),
        ]);
    }
}
