<?php

namespace App\Filament\Resources\Temples\RelationManagers;

use App\Filament\Support\MediaColumn;
use App\Filament\Schemas\TemplePujaForm;
use App\Models\TemplePuja;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
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
        return TemplePujaForm::configure($schema, $this->getOwnerRecord()->getKey());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                MediaColumn::make(
                    'image',
                    fn (TemplePuja $record): ?string => $record->image_path,
                    fn (TemplePuja $record): string => $record->image_disk ?? config('filesystems.media'),
                )->label('')->height(40)->circular(),

                TextColumn::make('name')->searchable()->weight('medium')->wrap()
                    ->description(fn (TemplePuja $record): ?string => $record->kind?->getLabel()),

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

                IconColumn::make('app_booking_enabled')
                    ->label('In app')
                    ->state(fn (TemplePuja $record): bool => $record->isBookableInApp())
                    ->boolean()
                    ->trueIcon('heroicon-o-device-phone-mobile')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray')
                    ->tooltip(fn (TemplePuja $record): string => $record->isBookableInApp()
                        ? 'Devotees book this in the app'
                        : 'Information only; booking in the app is off'),

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
