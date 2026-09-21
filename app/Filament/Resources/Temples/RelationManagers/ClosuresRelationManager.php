<?php

namespace App\Filament\Resources\Temples\RelationManagers;

use App\Models\TempleClosure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClosuresRelationManager extends RelationManager
{
    protected static string $relationship = 'closures';

    protected static ?string $title = 'Closures & special hours';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-exclamation-triangle';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('reason')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. Temple closed for renovation')
                    ->columnSpanFull(),

                DatePicker::make('starts_on')
                    ->label('From')
                    ->required(),

                DatePicker::make('ends_on')
                    ->label('To')
                    ->helperText('Leave blank for a single-day closure.')
                    // A range that ends before it starts would silently never match.
                    ->afterOrEqual('starts_on'),

                Toggle::make('is_full_day')
                    ->label('Closed all day')
                    ->default(true)
                    ->live()
                    ->helperText('Turn off if the temple opens, but on different hours.')
                    ->columnSpanFull(),

                TimePicker::make('opens_at')
                    ->label('Opens')
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => ! $get('is_full_day')),

                TimePicker::make('closes_at')
                    ->label('Closes')
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => ! $get('is_full_day')),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reason')
            ->columns([
                TextColumn::make('reason')->wrap(),

                TextColumn::make('period')
                    ->label('Dates')
                    ->state(function (TempleClosure $record): string {
                        $from = $record->starts_on->format('d M Y');
                        $to = $record->lastDay()->format('d M Y');

                        return $from === $to ? $from : "{$from} – {$to}";
                    }),

                IconColumn::make('is_full_day')
                    ->label('All day')
                    ->boolean(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (TempleClosure $record): string => match (true) {
                        $record->coversDate(now()) => 'Active now',
                        $record->starts_on->isFuture() => 'Upcoming',
                        default => 'Past',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Active now' => 'danger',
                        'Upcoming' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Filter::make('upcoming')
                    ->label('Current and upcoming only')
                    ->query(fn (Builder $query): Builder => $query->upcoming())
                    ->toggle()
                    ->default(),
            ])
            ->headerActions([
                CreateAction::make()->label('Add closure'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('starts_on', 'desc')
            ->emptyStateHeading('No closures recorded')
            ->emptyStateDescription('Add festival closures, renovation periods or changed hours.');
    }
}
