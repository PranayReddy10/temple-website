<?php

namespace App\Filament\Resources\Temples\RelationManagers;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Filament\Support\MediaColumn;
use App\Models\TempleEvent;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Events & programs';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-calendar-days';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What is happening')
                    ->columns(2)
                    ->schema([
                        Select::make('type')
                            ->options(EventType::class)
                            ->default(EventType::Festival)
                            ->required()
                            ->native(false),

                        TextInput::make('title')->required()->maxLength(255),

                        Textarea::make('description')->rows(4)->columnSpanFull(),

                        FileUpload::make('image_path')
                            ->label('Image')
                            ->image()
                            ->disk(fn (): string => config('filesystems.media'))
                            ->directory(fn (): string => 'events/'.$this->getOwnerRecord()->getKey())
                            ->visibility('public')
                            ->maxSize(8192)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->columnSpanFull(),
                    ]),

                Section::make('When')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('starts_on')->label('From')->required(),

                        DatePicker::make('ends_on')
                            ->label('To')
                            ->afterOrEqual('starts_on')
                            ->helperText('Leave blank for a single day.'),

                        Toggle::make('is_all_day')
                            ->label('All day')
                            ->default(true)
                            ->live()
                            ->columnSpanFull(),

                        TimePicker::make('starts_at')->label('Starts')->seconds(false)
                            ->visible(fn (Get $get): bool => ! $get('is_all_day')),
                        TimePicker::make('ends_at')->label('Ends')->seconds(false)
                            ->visible(fn (Get $get): bool => ! $get('is_all_day')),

                        Select::make('recurrence')
                            ->options([
                                'none' => 'One-off',
                                'yearly' => 'Every year on these dates',
                            ])
                            ->default('none')
                            ->native(false)
                            ->helperText('Festivals that follow the lunar calendar shift each year, so add those as separate entries rather than marking them yearly.'),
                    ]),

                Section::make('Publishing')
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->options(EventStatus::class)
                            ->default(EventStatus::Draft)
                            ->required()
                            ->native(false)
                            ->helperText(fn (): string => Auth::user()?->role?->isStaff()
                                ? 'Staff publish directly.'
                                : 'Unless this temple is verified and self-publishing is enabled, publishing sends it for review instead.'),
                    ]),
            ])
            ->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                MediaColumn::make(
                    'image',
                    fn (TempleEvent $record): ?string => $record->image_path,
                    fn (TempleEvent $record): string => $record->image_disk ?? config('filesystems.media'),
                )->label('')->height(40),

                TextColumn::make('title')->searchable()->weight('medium')->wrap(),

                TextColumn::make('type')->badge(),

                TextColumn::make('dates')
                    ->label('Dates')
                    ->state(fn (TempleEvent $record): string => $record->dateLabel()),

                TextColumn::make('status')->badge(),

                TextColumn::make('when')
                    ->label('Timing')
                    ->state(fn (TempleEvent $record): string => match (true) {
                        $record->coversDate(now()) => 'Happening now',
                        $record->starts_on->isFuture() => 'Upcoming',
                        default => 'Past',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Happening now' => 'success',
                        'Upcoming' => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('type')->options(EventType::class),
                SelectFilter::make('status')->options(EventStatus::class),
                Filter::make('upcoming')
                    ->label('Current and upcoming only')
                    ->query(fn (Builder $query): Builder => $query->upcoming())
                    ->toggle(),
            ])
            ->headerActions([CreateAction::make()->label('Add event')])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (TempleEvent $record): bool => $record->status === EventStatus::PendingReview
                        && (Auth::user()?->role?->isStaff() ?? false))
                    ->requiresConfirmation()
                    ->action(fn (TempleEvent $record) => $record->update([
                        'status' => EventStatus::Published,
                        'reviewed_by' => Auth::id(),
                        'review_note' => null,
                    ])),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (TempleEvent $record): bool => $record->status === EventStatus::PendingReview
                        && (Auth::user()?->role?->isStaff() ?? false))
                    ->form([
                        Textarea::make('review_note')->label('Reason')->required()->rows(2),
                    ])
                    ->action(fn (TempleEvent $record, array $data) => $record->update([
                        'status' => EventStatus::Rejected,
                        'reviewed_by' => Auth::id(),
                        'review_note' => $data['review_note'],
                    ])),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('starts_on', 'desc')
            ->emptyStateHeading('No events yet')
            ->emptyStateDescription('Add festivals, programs and announcements devotees should know about.');
    }
}
