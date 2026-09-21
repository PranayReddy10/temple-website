<?php

namespace App\Filament\Resources\TempleCategories\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TempleCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug((string) $state))),

                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),

                Select::make('kind')
                    ->label('Kind')
                    ->options([
                        'circuit' => 'Pilgrimage circuit',
                        'type' => 'Temple type',
                    ])
                    ->default('circuit')
                    ->required()
                    ->native(false)
                    ->live()
                    ->helperText('A circuit has a fixed, well-known membership such as the 12 Jyotirlingas. A type does not.'),

                TextInput::make('expected_count')
                    ->label('Expected number of temples')
                    ->numeric()
                    ->minValue(1)
                    // Only a recognised circuit has a canonical count to measure
                    // completeness against.
                    ->visible(fn (Get $get): bool => $get('kind') === 'circuit')
                    ->helperText('Lets the dashboard show completeness, e.g. 9 of 12 recorded.'),

                Textarea::make('description')
                    ->rows(3)
                    ->columnSpanFull(),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ])
            ->columns(2);
    }
}
