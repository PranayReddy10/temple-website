<?php

namespace App\Filament\Resources\DevotionalDays\Schemas;

use App\Filament\Schemas\MantraFields;
use App\Models\DevotionalDay;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DevotionalDayForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The day')
                    ->description('More than one deity per day is normal — Saturday is Shani in some traditions and Venkateswara in others. Add a row for each, and use the order to decide which leads.')
                    ->icon('heroicon-o-calendar-days')
                    ->columns(2)
                    ->schema([
                        Select::make('weekday')
                            ->label('Day of the week')
                            ->options(DevotionalDay::weekdayNames())
                            ->required()
                            ->native(false),

                        Select::make('deity_id')
                            ->label('Deity')
                            ->relationship('deity', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false),

                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Somavara — Shiva')
                            ->columnSpanFull(),

                        TextInput::make('subtitle')
                            ->maxLength(255)
                            ->placeholder('e.g. Monday belongs to Mahadeva')
                            ->columnSpanFull(),

                        Textarea::make('significance')
                            ->rows(3)
                            ->helperText('Why this day is kept for this deity. Shown on the app home screen.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Mantra')
                    ->description('Only where this day\'s tradition differs from the deity\'s own mantra, which is filled in on the deity record and used here automatically.')
                    ->icon('heroicon-o-speaker-wave')
                    ->columns(1)
                    ->schema(MantraFields::components(DevotionalDay::class, withMeaning: false)),

                Section::make('Appearance and order')
                    ->icon('heroicon-o-swatch')
                    ->columns(3)
                    ->schema([
                        ColorPicker::make('accent_color')
                            ->label('Accent colour')
                            ->helperText('The app themes the day with this.'),

                        TextInput::make('sort_order')
                            ->label('Order')
                            ->numeric()
                            ->default(1)
                            ->helperText('Lower leads the day.'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
            ])
            ->columns(1);
    }
}
