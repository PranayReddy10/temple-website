<?php

namespace App\Filament\Pages\Settings;

use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Payment gateways for subscriptions.
 *
 * Every gateway checks out on a page this server renders (/pay/...), opened
 * inside the app, and every payment is confirmed server to server before a
 * plan is switched on. The app never holds a gateway secret and never decides
 * on its own that a payment succeeded.
 */
class ManagePayments extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|\UnitEnum|null $navigationGroup = 'Monetisation';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Payment gateways';

    protected static ?string $slug = 'monetisation/payments';

    protected static function definitions(): array
    {
        return [
            'payments_enabled' => ['boolean', false],
            'payments_android' => ['boolean', true],
            'payments_ios' => ['boolean', false],
            'payments_default_gateway' => ['string', 'razorpay'],

            'payments_razorpay_enabled' => ['boolean', false],
            'payments_razorpay_key_id' => ['string', null],
            'payments_razorpay_key_secret' => ['secret', null],
            'payments_razorpay_webhook_secret' => ['secret', null],

            'payments_phonepe_enabled' => ['boolean', false],
            'payments_phonepe_env' => ['string', 'sandbox'],
            'payments_phonepe_client_id' => ['string', null],
            'payments_phonepe_merchant_id' => ['string', null],
            'payments_phonepe_client_version' => ['integer', 1],
            'payments_phonepe_client_secret' => ['secret', null],

            'payments_cashfree_enabled' => ['boolean', false],
            'payments_cashfree_env' => ['string', 'sandbox'],
            'payments_cashfree_app_id' => ['string', null],
            'payments_cashfree_secret_key' => ['secret', null],

            'payments_payu_enabled' => ['boolean', false],
            'payments_payu_env' => ['string', 'test'],
            'payments_payu_key' => ['string', null],
            'payments_payu_salt' => ['secret', null],
        ];
    }

    public function form(Schema $schema): Schema
    {
        $base = rtrim((string) config('app.url'), '/');

        return $schema->statePath('data')->components([
            Section::make('Payments')
                ->icon('heroicon-o-credit-card')
                ->schema([
                    Toggle::make('payments_enabled')->label('Sell subscriptions in the app'),
                    Toggle::make('payments_android')->label('Offer on Android')
                        ->helperText('Google Play requires Play Billing for digital goods such as ad removal. In India, Play\'s user-choice billing allows another gateway alongside it once you enrol; check your Play Console before going live.'),
                    Toggle::make('payments_ios')->label('Offer on iPhone and iPad')
                        ->helperText('Apple requires In-App Purchase for digital subscriptions. Leave off unless Apple has approved an exception: the app then lists plans without a buy button, and a plan bought elsewhere on the account still applies.'),
                    Select::make('payments_default_gateway')->label('Default gateway')->native(false)->options([
                        'razorpay' => 'Razorpay', 'phonepe' => 'PhonePe', 'cashfree' => 'Cashfree', 'payu' => 'PayU',
                    ])->helperText('The one used when the devotee does not choose. Only enabled gateways are offered.'),
                ]),

            Section::make('Razorpay')
                ->description('Dashboard → Account & Settings → API keys. Webhook: '.$base.'/api/v1/payments/webhook/razorpay (events payment.captured, payment.failed).')
                ->collapsible()
                ->columns(2)
                ->schema([
                    Toggle::make('payments_razorpay_enabled')->label('Enabled')->columnSpanFull(),
                    TextInput::make('payments_razorpay_key_id')->label('Key id')->placeholder('rzp_live_…'),
                    static::secretInput('payments_razorpay_key_secret', 'Key secret'),
                    static::secretInput('payments_razorpay_webhook_secret', 'Webhook secret'),
                ]),

            Section::make('PhonePe')
                ->description('PhonePe Business → Developer settings (Standard Checkout v2). Callback: '.$base.'/api/v1/payments/webhook/phonepe.')
                ->collapsible()
                ->columns(2)
                ->schema([
                    Toggle::make('payments_phonepe_enabled')->label('Enabled')->columnSpanFull(),
                    Radio::make('payments_phonepe_env')->label('Environment')->inline()->options(['sandbox' => 'Sandbox', 'production' => 'Production'])->columnSpanFull(),
                    TextInput::make('payments_phonepe_client_id')->label('Client id'),
                    TextInput::make('payments_phonepe_merchant_id')->label('Merchant id')
                        ->helperText('Needed for the app to open PhonePe\'s own payment sheet. From PhonePe\'s business dashboard.'),
                    TextInput::make('payments_phonepe_client_version')->label('Client version')->numeric(),
                    static::secretInput('payments_phonepe_client_secret', 'Client secret'),
                ]),

            Section::make('Cashfree')
                ->description('Cashfree Payments → Developers → API keys. Webhook: '.$base.'/api/v1/payments/webhook/cashfree.')
                ->collapsible()
                ->columns(2)
                ->schema([
                    Toggle::make('payments_cashfree_enabled')->label('Enabled')->columnSpanFull(),
                    Radio::make('payments_cashfree_env')->label('Environment')->inline()->options(['sandbox' => 'Sandbox', 'production' => 'Production'])->columnSpanFull(),
                    TextInput::make('payments_cashfree_app_id')->label('App id'),
                    static::secretInput('payments_cashfree_secret_key', 'Secret key'),
                ]),

            Section::make('PayU')
                ->description('PayU Dashboard → Developers → Key and salt.')
                ->collapsible()
                ->columns(2)
                ->schema([
                    Toggle::make('payments_payu_enabled')->label('Enabled')->columnSpanFull(),
                    Radio::make('payments_payu_env')->label('Environment')->inline()->options(['test' => 'Test', 'production' => 'Production'])->columnSpanFull(),
                    TextInput::make('payments_payu_key')->label('Merchant key'),
                    static::secretInput('payments_payu_salt', 'Salt'),
                ]),
        ]);
    }
}
