<?php

namespace App\Filament\Resources\Districts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class DistrictForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('state_id')
                    ->label('State')
                    ->relationship('state', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->native(false),

                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug((string) $state))),

                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    // District names repeat across states, so uniqueness is
                    // scoped to the parent state rather than global.
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, $get) => $rule->where('state_id', $get('state_id'))),
            ])
            ->columns(2);
    }
}
