<?php

namespace App\Filament\Pages\Settings;

use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Who runs the app, as the policy pages name them. Filled into the pages
 * wherever they say {business}, {address}, {grievance_officer} or {courts};
 * the support email ({email}) is the one under Administration → Settings.
 */
class ManageBusinessDetails extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|\UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Business details';

    protected static ?string $slug = 'website/business';

    protected static function definitions(): array
    {
        return [
            'legal_business_name' => ['string', null],
            'legal_address' => ['string', null],
            'legal_grievance_officer' => ['string', null],
            'legal_jurisdiction_city' => ['string', null],
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Shown on the policy pages')
                ->description('Payment gateways (Razorpay, Cashfree, PhonePe) and the app stores check that the business name and address on the website match the account. The support email is set under Administration → Settings.')
                ->icon('heroicon-o-scale')
                ->schema([
                    TextInput::make('legal_business_name')->label('Business name')->maxLength(160)
                        ->placeholder(config('brand.name'))
                        ->helperText('The registered name: a company, firm or the proprietor\'s name. {business} on the pages.'),
                    Textarea::make('legal_address')->label('Registered address')->rows(3)->maxLength(500)
                        ->helperText('{address} on the pages (the Contact us page).'),
                    TextInput::make('legal_grievance_officer')->label('Grievance Officer')->maxLength(160)
                        ->placeholder('Name, designation')
                        ->helperText('Required by the IT Rules and the DPDP Act. {grievance_officer} in the Privacy policy.'),
                    TextInput::make('legal_jurisdiction_city')->label('City for disputes')->maxLength(80)
                        ->placeholder('Hyderabad')
                        ->helperText('Where the business is registered. {courts} in the Terms.'),
                ]),
        ]);
    }
}
