<?php

namespace App\Filament\Temple\Resources\MyTemples\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * What a temple's own team may edit.
 *
 * Deliberately narrower than the editorial form. Identity and taxonomy (name,
 * deity, state, pilgrimage circuits) stay with editorial staff because they
 * decide how the temple is classified across the whole directory, and trust
 * fields stay with staff because a temple confirming its own information
 * would defeat the point of having a verification level at all.
 *
 * What the temple team owns is everything only they can really know: how to
 * reach them, when they open, and what a visitor should expect.
 */
class MyTempleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Temple')
                    ->description('Name, deity and classification are maintained by the editorial team. Contact us if any of it is wrong.')
                    ->icon('heroicon-o-building-library')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Temple name')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('deity.name')
                            ->label('Primary deity')
                            ->disabled()
                            ->dehydrated(false),
                    ]),

                Section::make('About')
                    ->icon('heroicon-o-book-open')
                    ->schema([
                        Textarea::make('short_description')
                            ->label('Short description')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('One or two sentences, shown in search results.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Location')
                    ->icon('heroicon-o-map-pin')
                    ->columns(2)
                    ->schema([
                        Textarea::make('address')->rows(3)->columnSpanFull(),
                        TextInput::make('city')->label('City / town / village')->maxLength(255),
                        TextInput::make('pincode')
                            ->label('PIN code')
                            ->maxLength(10)
                            ->rule('regex:/^[1-9][0-9]{5}$/'),
                        TextInput::make('latitude')
                            ->numeric()->minValue(-90)->maxValue(90)->step('0.0000001')
                            ->requiredWith('longitude'),
                        TextInput::make('longitude')
                            ->numeric()->minValue(-180)->maxValue(180)->step('0.0000001')
                            ->requiredWith('latitude'),
                    ]),

                Section::make('Official contact')
                    ->icon('heroicon-o-phone')
                    ->columns(2)
                    ->schema([
                        TextInput::make('official_website')->url()->maxLength(255),
                        TextInput::make('contact_phone')->label('Phone')->tel()->maxLength(40),
                        TextInput::make('contact_email')->label('Email')->email()->maxLength(255),
                    ]),

                Section::make('Visitor rules')
                    ->description('What a devotee should know before arriving.')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->columns(2)
                    ->schema([
                        Textarea::make('dress_code')->rows(2)->columnSpanFull(),
                        TextInput::make('photography_policy')->label('Photography')->maxLength(255),
                        TextInput::make('mobile_policy')->label('Mobile phones')->maxLength(255),
                        TextInput::make('footwear_policy')->label('Footwear')->maxLength(255),
                        Textarea::make('entry_rules')->label('Entry rules')->rows(3)->columnSpanFull(),
                        Textarea::make('queue_information')->label('Queue information')->rows(3)->columnSpanFull(),
                    ]),
            ])
            ->columns(1);
    }
}
