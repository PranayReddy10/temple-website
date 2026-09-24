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
 * Ads in the app: which network, which ad units, and where they may appear.
 *
 * Placements are chosen for where an ad interrupts least: between sections of
 * a temple page and among list results, never in the passport, a check-in,
 * a scan, sign-in or checkout. A devotee with an active no-ads plan sees
 * none, whatever is set here.
 */
class ManageAds extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static string|\UnitEnum|null $navigationGroup = 'Monetisation';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Ads';

    protected static ?string $slug = 'monetisation/ads';

    protected static function definitions(): array
    {
        return [
            'ads_enabled' => ['boolean', false],
            'ads_test_mode' => ['boolean', true],
            'ads_network' => ['string', 'admob'],
            'ads_list_interval' => ['integer', 6],
            'ads_temple_detail' => ['boolean', true],
            'ads_explore' => ['boolean', true],
            'ads_home' => ['boolean', true],
            'ads_day_page' => ['boolean', false],

            'ads_admob_android_native' => ['string', null],
            'ads_admob_ios_native' => ['string', null],
            'ads_admob_android_banner' => ['string', null],
            'ads_admob_ios_banner' => ['string', null],

            'ads_applovin_sdk_key' => ['string', null],
            'ads_applovin_android_native' => ['string', null],
            'ads_applovin_ios_native' => ['string', null],
            'ads_applovin_android_banner' => ['string', null],
            'ads_applovin_ios_banner' => ['string', null],
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Ads')
                ->icon('heroicon-o-megaphone')
                ->schema([
                    Toggle::make('ads_enabled')->label('Show ads in the app')
                        ->helperText('Subscribers on a no-ads plan never see them.'),
                    Toggle::make('ads_test_mode')->label('Test ads only')
                        ->helperText('Uses the networks\' own test units. Keep this on until the app is live in the stores: clicking real ads during testing can get the account suspended.'),
                    Radio::make('ads_network')->label('Ad network')->live()->options([
                        'admob' => 'Google AdMob',
                        'applovin_max' => 'AppLovin MAX',
                    ])->descriptions([
                        'admob' => 'Add Meta Audience Network and AppLovin as mediation sources in the AdMob console to compete for the same slots.',
                        'applovin_max' => 'Add Google (AdMob) and Meta Audience Network as bidders in the MAX dashboard.',
                    ])->helperText('Meta Audience Network only serves through a mediation platform (it is bidding-only), so it is added inside AdMob or MAX rather than chosen here.'),
                ]),

            Section::make('Where ads appear')
                ->description('Native ads, styled like the cards around them and labelled "Ad".')
                ->icon('heroicon-o-squares-2x2')
                ->columns(2)
                ->schema([
                    Toggle::make('ads_temple_detail')->label('Temple page — between sections'),
                    Toggle::make('ads_explore')->label('Explore and search results')
                        ->helperText('One among the results, every N temples.'),
                    Toggle::make('ads_home')->label('Home — between sections'),
                    Toggle::make('ads_day_page')->label('Weekday deity pages'),
                    TextInput::make('ads_list_interval')->label('In lists, one ad every N items')->numeric()->minValue(3)->maxValue(30),
                ]),

            Section::make('AdMob ad units')
                ->description('AdMob → Apps → Ad units. The AdMob app id itself is built into the app (AndroidManifest.xml and Info.plist) and cannot be changed from here.')
                ->visible(fn (Get $get): bool => $get('ads_network') !== 'applovin_max')
                ->columns(2)
                ->schema([
                    TextInput::make('ads_admob_android_native')->label('Android native')->placeholder('ca-app-pub-…/…'),
                    TextInput::make('ads_admob_ios_native')->label('iOS native')->placeholder('ca-app-pub-…/…'),
                    TextInput::make('ads_admob_android_banner')->label('Android banner'),
                    TextInput::make('ads_admob_ios_banner')->label('iOS banner'),
                ]),

            Section::make('AppLovin MAX ad units')
                ->description('MAX → Manage → Ad units.')
                ->visible(fn (Get $get): bool => $get('ads_network') === 'applovin_max')
                ->columns(2)
                ->schema([
                    TextInput::make('ads_applovin_sdk_key')->label('SDK key')->columnSpanFull(),
                    TextInput::make('ads_applovin_android_native')->label('Android native'),
                    TextInput::make('ads_applovin_ios_native')->label('iOS native'),
                    TextInput::make('ads_applovin_android_banner')->label('Android banner / MREC'),
                    TextInput::make('ads_applovin_ios_banner')->label('iOS banner / MREC'),
                ]),
        ]);
    }
}
