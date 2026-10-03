<?php

namespace App\Filament\Pages\Settings;

use App\Support\Seo;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

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

    protected static string|\UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 3;

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
            'custom_head_html' => ['string', null],
            'custom_body_html' => ['string', null],
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
                    TextInput::make('google_site_verification')->label('HTML tag')
                        ->placeholder('<meta name="google-site-verification" content="…" />')
                        ->helperText('Paste the whole tag from Search Console, or just its content. It goes on every page of darshansaathi.com, the home page included. Check with "Open the home page source" below, then press Verify in Search Console.')
                        ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (filled($value) && Seo::verificationCode($value) === null) {
                                $fail('Paste the google-site-verification tag, or the code inside content="…".');
                            }
                        }),
                    TextInput::make('bing_site_verification')->label('Bing Webmaster (msvalidate.01)')
                        ->placeholder('<meta name="msvalidate.01" content="…" />')
                        ->helperText('The whole tag or its content. Optional: Bing can also import the site straight from Search Console.'),
                    Placeholder::make('home_source')->label('Check')
                        ->content(fn () => new HtmlString('<a href="view-source:'.e(Seo::url('/')).'" target="_blank" rel="noopener">Open the home page source</a> (or open '.e(Seo::url('/')).' and press Ctrl+U) and look for the tag in &lt;head&gt;.')),
                ]),

            Section::make('Code for every page')
                ->description('For tags other services ask you to add: Google Tag Manager, Meta (Facebook) Pixel, Microsoft Clarity, a chat widget, another site verification. Added to every page of darshansaathi.com — the home page, the temple, state and deity pages, and the policy pages. Not added to the admin panel or the temple portal.')
                ->icon('heroicon-o-code-bracket')
                ->collapsible()
                ->schema([
                    Textarea::make('custom_head_html')
                        ->label('Head code — goes just before </head>')
                        ->rows(8)
                        ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; font-size: 12px'])
                        ->placeholder("<!-- e.g. Google Tag Manager -->\n<script>…</script>")
                        ->helperText('Paste exactly what the service gives you. Only paste code from services you trust: it runs on every page devotees open.'),
                    Textarea::make('custom_body_html')
                        ->label('Body code — goes just after <body>')
                        ->rows(6)
                        ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; font-size: 12px'])
                        ->placeholder('<!-- e.g. Google Tag Manager (noscript) -->\n<noscript>…</noscript>'),
                ]),
        ]);
    }
}
