<?php

namespace App\Filament\Pages\Settings;

use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Google Analytics (through Firebase) and Google Search Console.
 *
 * The Android and iOS apps report with the Firebase ids already saved under
 * Push setup; the website (the Flutter web app and the temple pages) reports
 * to the web stream whose ids are entered here. Nothing is sent until
 * "Collect usage analytics" is on.
 */
class ManageAnalytics extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'App';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Analytics & SEO';

    protected static ?string $slug = 'app/analytics';

    protected static function definitions(): array
    {
        return [
            'analytics_enabled' => ['boolean', false],
            'firebase_web_api_key' => ['string', null],
            'firebase_web_app_id' => ['string', null],
            'firebase_measurement_id' => ['string', null],
            'google_site_verification' => ['string', null],
            'google_site_verification_file' => ['string', null],
            'bing_site_verification' => ['string', null],
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Google Analytics')
                ->description('Reports screens, temple views, searches, sign-ins, bookings and payments. No names, emails or phone numbers are sent. The apps use the Firebase project under Push setup; link that project to Google Analytics in the Firebase console.')
                ->icon('heroicon-o-chart-bar')
                ->schema([
                    Toggle::make('analytics_enabled')->label('Collect usage analytics')
                        ->helperText('Covers the Android and iOS apps and the website. Say so in the privacy policy.'),
                ]),
            Section::make('Website (web app)')
                ->description('Firebase console → Project settings → General → Add app → Web. Copy the apiKey, appId and measurementId from its config. The measurement id is also used on the temple pages, so the whole of the website reports to one stream.')
                ->columns(2)
                ->schema([
                    TextInput::make('firebase_web_api_key')->label('Web API key'),
                    TextInput::make('firebase_web_app_id')->label('Web app id')->placeholder('1:123:web:abc'),
                    TextInput::make('firebase_measurement_id')->label('Measurement id')->placeholder('G-XXXXXXXXXX')
                        ->regex('/^G-[A-Z0-9]+$/')->columnSpanFull(),
                ]),
            Section::make('Google Search Console')
                ->description('Easiest: add a Domain property for darshansaathi.com and verify it with the DNS TXT record in Hostinger → DNS. For a URL-prefix property, use either option below, then submit sitemap.xml.')
                ->icon('heroicon-o-magnifying-glass')
                ->schema([
                    TextInput::make('google_site_verification_file')->label('HTML file name')
                        ->placeholder('google1234567890abcdef.html')
                        ->regex('/^google[0-9a-f]+\.html$/')
                        ->helperText('From "HTML file" verification: only the file name is needed; the website serves it.'),
                    TextInput::make('google_site_verification')->label('HTML tag content')
                        ->placeholder('The content="…" value of the google-site-verification tag')
                        ->helperText('Added to the temple pages. The home page is the web app, so Google may prefer the HTML file or DNS.'),
                    TextInput::make('bing_site_verification')->label('Bing Webmaster (msvalidate.01)')
                        ->helperText('Optional: Bing can also import the site straight from Search Console.'),
                ]),
        ]);
    }
}
