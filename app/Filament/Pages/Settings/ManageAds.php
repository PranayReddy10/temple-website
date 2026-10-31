<?php

namespace App\Filament\Pages\Settings;

use BackedEnum;
use App\Support\Seo;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Ads in the app (AdMob or AppLovin MAX) and on the website (AdSense):
 * which network, which ad units, and where they may appear.
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

    protected static ?int $navigationSort = 4;

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

            'ads_web_enabled' => ['boolean', false],
            'adsense_client' => ['string', null],
            'adsense_test_mode' => ['boolean', false],
            'adsense_slot_temple' => ['string', null],
            'adsense_slot_list' => ['string', null],
            'adsense_slot_home' => ['string', null],
            'adsense_slot_festival' => ['string', null],
            'ads_txt_extra' => ['string', null],
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

            Section::make('Website ads (Google AdSense)')
                ->description(new HtmlString('On darshansaathi.com: the home page, temple lists, temple pages and festival pages only — never sign-in, account, booking, hundi, ticket or payment pages, and never for a devotee on a no-ads plan. Sign up at <a href="https://adsense.google.com" target="_blank" rel="noopener" class="underline">adsense.google.com</a> with the site darshansaathi.com; Google checks the site, which takes a few days to two weeks.'))
                ->icon('heroicon-o-globe-alt')
                ->columns(2)
                ->schema([
                    Toggle::make('ads_web_enabled')->label('Show ads on the website')
                        ->helperText('Needs the publisher id. Before approval, switch it on anyway: Google looks for its code on the site while it reviews.'),
                    Toggle::make('adsense_test_mode')->label('Test ads only')
                        ->helperText('Marks requests as tests: nothing is earned or charged. Never click your own live ads.'),
                    TextInput::make('adsense_client')->label('Publisher id')
                        ->placeholder('ca-pub-1234567890123456')
                        ->regex('/^(ca-)?pub-\d{10,20}$/')
                        ->helperText('AdSense → Account → Settings → Account information. Also writes darshansaathi.com/ads.txt for you.')
                        ->columnSpanFull(),
                    Placeholder::make('auto_ads')->label('Auto ads')
                        ->content('With the publisher id set, Google places ads by itself where you allow it: AdSense → Ads → By site → darshansaathi.com → Auto ads. The ad units below are optional fixed places on top of that.')
                        ->columnSpanFull(),
                    TextInput::make('adsense_slot_temple')->label('Temple page — between sections')->placeholder('1234567890')->regex('/^\d{6,20}$/')
                        ->helperText('AdSense → Ads → By ad unit → Display ads: the data-ad-slot number.'),
                    TextInput::make('adsense_slot_list')->label('Temple lists — after the results')->placeholder('1234567890')->regex('/^\d{6,20}$/'),
                    TextInput::make('adsense_slot_home')->label('Home page — between sections')->placeholder('1234567890')->regex('/^\d{6,20}$/'),
                    TextInput::make('adsense_slot_festival')->label('Festival pages')->placeholder('1234567890')->regex('/^\d{6,20}$/'),
                    Textarea::make('ads_txt_extra')->label('More ads.txt lines')->rows(3)
                        ->placeholder('facebook.com, 1234567890, RESELLER, c3e20eee3f780d68')
                        ->helperText('Only if another ad network asks you to add lines. The AdSense line is written for you.')
                        ->columnSpanFull(),
                    Placeholder::make('ads_txt_check')->label('Check')
                        ->content(fn () => new HtmlString('<a href="'.e(Seo::url('ads.txt')).'" target="_blank" rel="noopener" class="underline">Open ads.txt</a> · <a href="'.e(Seo::url('app-ads.txt')).'" target="_blank" rel="noopener" class="underline">app-ads.txt</a> (the same lines, for AdMob in the app)'))
                        ->columnSpanFull(),
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
