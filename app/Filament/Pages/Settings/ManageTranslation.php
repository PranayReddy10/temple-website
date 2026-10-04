<?php

namespace App\Filament\Pages\Settings;

use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Auto-translate (App\Support\Translation\AutoTranslator).
 *
 * The admin panel's Languages tab and the trust app use it to fill in a
 * first draft of a temple's name, dress code and the rest in Telugu, Hindi,
 * Tamil and Kannada. A draft is never served to devotees until someone
 * reads it: see the Reviewed switch on each translation.
 */
class ManageTranslation extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-language';

    protected static string|\UnitEnum|null $navigationGroup = 'App';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Auto-translate';

    protected static ?string $slug = 'app/translate';

    protected static function definitions(): array
    {
        return [
            'auto_translate_enabled' => ['boolean', true],
            'auto_translate_provider' => ['string', 'mymemory'],
            'auto_translate_email' => ['string', null],
            'google_translate_api_key' => ['secret', null],
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Auto-translate')
                ->icon('heroicon-o-language')
                ->schema([
                    Toggle::make('auto_translate_enabled')->label('Offer auto-translate')
                        ->helperText('An "Auto-translate" button on translations in this panel and in the trust app. What it writes is a draft: devotees see it only once someone has read and saved or reviewed it.'),
                    Radio::make('auto_translate_provider')->label('Service')
                        ->options([
                            'mymemory' => 'MyMemory — free, no key',
                            'google' => 'Google Cloud Translation — API key, first 500,000 characters a month free',
                        ])
                        ->default('mymemory')
                        ->live(),
                ]),
            Section::make('MyMemory')
                ->description('About 5,000 characters a day for free; with a contact email, about 50,000.')
                ->visible(fn (Get $get): bool => $get('auto_translate_provider') !== 'google')
                ->schema([
                    TextInput::make('auto_translate_email')->label('Contact email')->email()
                        ->placeholder('support@darshansaathi.com'),
                ]),
            Section::make('Google Cloud Translation')
                ->description('Google Cloud console → APIs & Services → enable Cloud Translation API → Credentials → Create API key (restrict it to the Cloud Translation API). Billing must be on, but the first 500,000 characters each month cost nothing. Without a key, MyMemory is used.')
                ->visible(fn (Get $get): bool => $get('auto_translate_provider') === 'google')
                ->schema([
                    static::secretInput('google_translate_api_key', 'API key'),
                ]),
        ]);
    }
}
