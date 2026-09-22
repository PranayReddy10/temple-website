<?php

namespace App\Filament\Resources\TempleEvents;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Filament\Resources\TempleEvents\Pages\EditTempleEvent;
use App\Filament\Resources\TempleEvents\Pages\ListTempleEvents;
use App\Models\TempleEvent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Every temple's events in one list, so review is a queue.
 *
 * Events are created inside a temple, which is the right place to create
 * them. Reviewing them is the opposite problem: a submission arrives from a
 * temple you were not thinking about, and finding it by first opening the
 * right temple record means knowing the answer before you look.
 */
class TempleEventResource extends Resource
{
    protected static ?string $model = TempleEvent::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Temples';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Events & Programs';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Event')
                    ->columns(2)
                    ->schema([
                        Select::make('temple_id')
                            ->label('Temple')
                            ->relationship('temple', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false),

                        Select::make('type')->options(EventType::class)->required()->native(false),

                        TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),

                        Textarea::make('description')->rows(4)->columnSpanFull(),

                        DatePicker::make('starts_on')->label('From')->required(),

                        DatePicker::make('ends_on')
                            ->label('To')
                            ->afterOrEqual('starts_on')
                            ->helperText('Leave blank for a single day.'),
                    ]),

                Section::make('Review')
                    ->columns(2)
                    ->schema([
                        Select::make('status')->options(EventStatus::class)->required()->native(false),

                        Textarea::make('review_note')
                            ->label('Note to the temple')
                            ->rows(2)
                            ->helperText('Shown to the temple team when an event is sent back.'),
                    ]),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('temple'))
            ->columns([
                ImageColumn::make('image')
                    ->label('')
                    ->state(fn (TempleEvent $record): ?string => $record->imageUrl())
                    ->height(40),

                TextColumn::make('title')->searchable()->sortable()->weight('medium')->wrap(),

                TextColumn::make('temple.name')->label('Temple')->searchable()->sortable()->wrap(),

                TextColumn::make('type')->badge()->toggleable(),

                TextColumn::make('dates')
                    ->label('Dates')
                    ->state(fn (TempleEvent $record): string => $record->dateLabel()),

                TextColumn::make('status')->badge()->sortable(),
            ])
            ->filters([
                Filter::make('awaiting_review')
                    ->label('Awaiting review')
                    ->query(fn (Builder $query): Builder => $query->awaitingReview())
                    ->toggle(),

                SelectFilter::make('status')->options(EventStatus::class)->multiple(),
                SelectFilter::make('type')->options(EventType::class)->multiple(),
                SelectFilter::make('temple')->relationship('temple', 'name')->searchable()->preload(),

                Filter::make('upcoming')
                    ->label('Current and upcoming only')
                    ->query(fn (Builder $query): Builder => $query->upcoming())
                    ->toggle(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (TempleEvent $record): bool => $record->status === EventStatus::PendingReview)
                    ->requiresConfirmation()
                    ->modalDescription('The event becomes visible to devotees in the app.')
                    ->action(fn (TempleEvent $record) => $record->update([
                        'status' => EventStatus::Published,
                        'reviewed_by' => Auth::id(),
                        'review_note' => null,
                    ])),

                Action::make('reject')
                    ->label('Send back')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (TempleEvent $record): bool => $record->status === EventStatus::PendingReview)
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
            ->persistFiltersInSession()
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading('No events yet')
            ->emptyStateDescription('Festivals and programs added inside a temple appear here, and anything a temple submits for review lands in this list.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTempleEvents::route('/'),
            'edit' => EditTempleEvent::route('/{record}/edit'),
        ];
    }

    /** Submissions are the reason to look, so the count is the badge. */
    public static function getNavigationBadge(): ?string
    {
        $pending = TempleEvent::query()->awaitingReview()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
