<?php

namespace App\Filament\Resources\Temples\RelationManagers;

use App\Enums\TimingKind;
use App\Models\TempleTiming;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TimingsRelationManager extends RelationManager
{
    protected static string $relationship = 'timings';

    protected static ?string $title = 'Timings';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-clock';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('kind')
                    ->label('Type')
                    ->options(TimingKind::class)
                    ->default(TimingKind::General)
                    ->required()
                    ->native(false),

                TextInput::make('label')
                    ->label('Name')
                    ->maxLength(255)
                    ->placeholder('e.g. Suprabhatam, Mangala Aarti')
                    ->helperText('Leave blank for plain opening hours.'),

                Select::make('day_of_week')
                    ->label('Day')
                    ->options(TempleTiming::dayNames())
                    ->placeholder('Every day')
                    ->native(false)
                    ->helperText('Leave blank if this applies every day.'),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0),

                TimePicker::make('opens_at')
                    ->label('Opens')
                    ->seconds(false),

                TimePicker::make('closes_at')
                    ->label('Closes')
                    ->seconds(false),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull()
                    ->placeholder('e.g. Closed 13:00–16:00 for abhishekam'),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('kind')
                    ->label('Type')
                    ->badge(),

                TextColumn::make('label')
                    ->placeholder('—'),

                TextColumn::make('day')
                    ->label('Day')
                    ->state(fn (TempleTiming $record): string => $record->dayLabel()),

                TextColumn::make('window')
                    ->label('Hours')
                    ->state(fn (TempleTiming $record): string => $record->window()),

                TextColumn::make('notes')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(TimingKind::class),
            ])
            ->headerActions([
                CreateAction::make()->label('Add timing'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->emptyStateHeading('No timings recorded')
            ->emptyStateDescription('Add opening hours, darshan and aarti times.');
    }
}
