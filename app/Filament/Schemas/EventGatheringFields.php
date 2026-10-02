<?php

namespace App\Filament\Schemas;

use App\Filament\Support\PaymentsApproval;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The parts of an event that make it a gathering devotees join: who leads
 * it, weekly repetition, "I'll join" or paid tickets, and what will be sung.
 * Shared by the event form inside a temple and the events queue.
 */
class EventGatheringFields
{
    public static function recurrence(): Select
    {
        return Select::make('recurrence')
            ->options([
                'none' => 'One-off',
                'weekly' => 'Every week (on the first date\'s weekday)',
                'yearly' => 'Every year on these dates',
            ])
            ->default('none')
            ->native(false)
            ->helperText('Weekly suits bhajan gatherings: "To" is then the last week, blank to keep going. Festivals that follow the lunar calendar shift each year, so add those as separate entries rather than marking them yearly.');
    }

    public static function section(): Section
    {
        return Section::make('Gathering and tickets')
            ->description('For bhajan gatherings and programs devotees come to. Paid tickets are settled with the temple under Finance.')
            ->columns(2)
            ->collapsible()
            ->schema([
                TextInput::make('group_name')->label('Led by (mandali, group or speaker)')->maxLength(160),
                Toggle::make('open_to_all')->label('Open to all')->default(true)->inline(false),
                Toggle::make('registration_enabled')
                    ->label('Devotees can join in the app')
                    ->helperText('"I\'ll join" when free; tickets when priced.')
                    ->live()
                    ->columnSpanFull(),
                TextInput::make('ticket_price_paise')
                    ->label('Ticket price per person (₹)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100000)
                    ->placeholder('0 for free')
                    ->formatStateUsing(fn ($state): ?string => $state ? (string) ($state / 100) : null)
                    ->dehydrateStateUsing(fn ($state): int => (int) round(((float) $state) * 100))
                    // Paid tickets take money: only for an approved temple.
                    ->rule(PaymentsApproval::rule(fn (Get $get, mixed $value): bool => (bool) $get('registration_enabled') && (float) $value > 0))
                    ->visible(fn (Get $get): bool => (bool) $get('registration_enabled')),
                TextInput::make('capacity')
                    ->label('People per date')
                    ->numeric()
                    ->minValue(1)
                    ->placeholder('No limit')
                    ->visible(fn (Get $get): bool => (bool) $get('registration_enabled')),
                TextInput::make('max_people_per_registration')
                    ->label('Most people at a time')
                    ->numeric()
                    ->minValue(1)
                    ->default(10)
                    ->visible(fn (Get $get): bool => (bool) $get('registration_enabled')),
                Textarea::make('songs')->label('Songs (one per line)')->rows(4)->columnSpanFull(),
            ]);
    }
}
