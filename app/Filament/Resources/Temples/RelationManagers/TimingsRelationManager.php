<?php

namespace App\Filament\Resources\Temples\RelationManagers;

use App\Enums\TimingKind;
use App\Models\TempleTiming;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

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

                CheckboxList::make('days')
                    ->label('Days')
                    ->options(self::dayOptions())
                    ->columns(['default' => 4, 'md' => 7])
                    ->gridDirection('row')
                    ->columnSpanFull()
                    ->helperText('Leave all unticked for every day. A timing for some days (say Sat & Sun) replaces the every-day timing of the same type on those days.')
                    ->hintActions([
                        Action::make('everyDay')->label('Every day')->link()->action(fn (Set $set) => $set('days', [])),
                        Action::make('weekdays')->label('Mon–Fri')->link()->action(fn (Set $set) => $set('days', array_map('strval', TempleTiming::WEEKDAYS))),
                        Action::make('weekend')->label('Sat & Sun')->link()->action(fn (Set $set) => $set('days', array_map('strval', TempleTiming::WEEKEND))),
                    ]),

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
                    ->label('Days')
                    ->badge()
                    ->color(fn (TempleTiming $record): string => $record->isEveryDay() ? 'gray' : 'info')
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
                // Most temples keep other hours at the weekend.
                Action::make('splitWeekend')
                    ->label('Different timings on Sat & Sun')
                    ->icon('heroicon-o-calendar-days')
                    ->color('gray')
                    ->visible(fn (): bool => $this->getOwnerRecord()->timings()->whereNull('days')->exists())
                    ->requiresConfirmation()
                    ->modalDescription('Every-day timings become Mon–Fri, and a Sat & Sun copy of each is added with the same hours. Then edit the Sat & Sun ones to the weekend hours.')
                    ->action(function (): void {
                        $copies = TempleTiming::splitWeekend($this->getOwnerRecord()->timings()->get());
                        Notification::make()->title($copies->count().' Sat & Sun timings added')->body('Edit them to the weekend hours.')->success()->send();
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('splitWeekendSelected')
                        ->label('Different timings on Sat & Sun')
                        ->icon('heroicon-o-calendar-days')
                        ->requiresConfirmation()
                        ->modalDescription('The ticked every-day timings become Mon–Fri, each with a Sat & Sun copy to edit.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $copies = TempleTiming::splitWeekend($records);
                            Notification::make()->title($copies->count().' Sat & Sun timings added')->success()->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->emptyStateHeading('No timings recorded')
            ->emptyStateDescription('Add opening hours, darshan and aarti times.');
    }

    /** @return array<int, string> Monday first */
    public static function dayOptions(): array
    {
        $names = TempleTiming::dayNames();

        return collect(TempleTiming::WEEK)->mapWithKeys(fn (int $d) => [$d => $names[$d]])->all();
    }
}
