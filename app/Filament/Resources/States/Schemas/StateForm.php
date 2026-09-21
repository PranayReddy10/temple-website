<?php

namespace App\Filament\Resources\States\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class StateForm
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

                TextInput::make('code')
                    ->label('ISO code')
                    ->required()
                    ->maxLength(5)
                    ->unique(ignoreRecord: true)
                    ->helperText('ISO 3166-2:IN subdivision code, e.g. TG, TN, MH.'),

                Select::make('type')
                    ->options([
                        'state' => 'State',
                        'union_territory' => 'Union Territory',
                    ])
                    ->default('state')
                    ->required()
                    ->native(false),
            ])
            ->columns(2);
    }
}
