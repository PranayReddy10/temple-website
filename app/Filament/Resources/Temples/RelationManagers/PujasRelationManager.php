<?php

namespace App\Filament\Resources\Temples\RelationManagers;

use App\Models\TemplePuja;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PujasRelationManager extends RelationManager
{
    protected static string $relationship = 'pujas';

    protected static ?string $title = 'Puja & Seva';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-fire';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Abhishekam, Archana, Kalyanotsavam')
                            ->columnSpanFull(),

                        Textarea::make('description')->rows(3)->columnSpanFull(),
                        Textarea::make('includes')
                            ->label('What is included')
                            ->rows(2)
                            ->columnSpanFull(),
                        Textarea::make('eligibility')
                            ->rows(2)
                            ->helperText('Any restriction the temple publishes on who may participate.')
                            ->columnSpanFull(),
                    ]),

                Section::make('When')
                    ->columns(3)
                    ->schema([
                        TimePicker::make('starts_at')->label('Start time')->seconds(false),
                        TextInput::make('duration_minutes')
                            ->label('Duration (minutes)')
                            ->numeric()
                            ->minValue(1),
                        TextInput::make('schedule_note')
                            ->label('Schedule note')
                            ->maxLength(255)
                            ->placeholder('e.g. Daily, Fridays only'),
                    ]),

                Section::make('Fee')
                    ->columns(3)
                    ->description('Record only what the temple actually publishes. Leaving the amount blank means "no published price", which is not the same as free.')
                    ->schema([
                        Toggle::make('is_free')
                            ->label('Free of charge')
                            ->live(),
                        TextInput::make('fee_amount')
                            ->label('Published fee')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₹')
                            ->disabled(fn (Get $get): bool => (bool) $get('is_free'))
                            ->dehydrateStateUsing(fn ($state, Get $get) => $get('is_free') ? null : $state),
                        TextInput::make('fee_currency')
                            ->default('INR')
                            ->maxLength(3)
                            ->disabled(fn (Get $get): bool => (bool) $get('is_free')),
                    ]),

                Section::make('Booking')
                    ->columns(2)
                    ->description('An unofficial route must never be presented as official.')
                    ->schema([
                        TextInput::make('booking_url')
                            ->label('Booking URL')
                            ->url()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->columnSpanFull(),

                        Toggle::make('booking_is_official')
                            ->label('This is the temple\'s official booking route')
                            ->helperText('Only tick this if the link is operated by the temple or its governing body. Third-party resellers are not official.')
                            ->disabled(fn (Get $get): bool => blank($get('booking_url'))),

                        TextInput::make('booking_note')
                            ->label('Booking note')
                            ->maxLength(255),
                    ]),

                Section::make('Publishing')
                    ->columns(2)
                    ->schema([
                        TextInput::make('sort_order')->numeric()->default(0),
                        Toggle::make('is_published')->label('Published')->default(true),
                    ]),
            ])
            ->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium')->wrap(),

                TextColumn::make('when')
                    ->label('When')
                    ->state(function (TemplePuja $record): string {
                        $time = $record->starts_at ? substr((string) $record->starts_at, 0, 5) : null;

                        return collect([$time, $record->schedule_note])->filter()->join(' · ') ?: '—';
                    }),

                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(fn (TemplePuja $record): string => $record->durationLabel() ?? '—')
                    ->toggleable(),

                TextColumn::make('fee')
                    ->label('Fee')
                    ->state(fn (TemplePuja $record): string => $record->feeLabel())
                    ->badge()
                    ->color(fn (TemplePuja $record): string => match (true) {
                        $record->is_free => 'success',
                        $record->fee_amount !== null => 'primary',
                        default => 'gray',
                    }),

                TextColumn::make('booking')
                    ->label('Booking')
                    ->state(fn (TemplePuja $record): string => match (true) {
                        $record->hasOfficialBooking() => 'Official',
                        filled($record->booking_url) => 'Third party',
                        default => '—',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Official' => 'success',
                        'Third party' => 'warning',
                        default => 'gray',
                    }),

                IconColumn::make('is_published')->label('Published')->boolean(),
            ])
            ->headerActions([CreateAction::make()->label('Add puja / seva')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->emptyStateHeading('No pujas recorded')
            ->emptyStateDescription('Add the sevas this temple publishes, with their timings and official fees.');
    }
}
