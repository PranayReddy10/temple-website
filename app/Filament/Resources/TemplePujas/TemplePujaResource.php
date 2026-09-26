<?php

namespace App\Filament\Resources\TemplePujas;

use App\Filament\Resources\TemplePujas\Pages\EditTemplePuja;
use App\Filament\Resources\TemplePujas\Pages\ListTemplePujas;
use App\Filament\Schemas\TemplePujaForm;
use App\Filament\Support\MediaColumn;
use App\Models\TemplePuja;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Every temple's pujas and sevas in one list.
 *
 * They are created inside a temple, which is right when that temple is what
 * you are thinking about. This is the other direction: "which sevas have no
 * price", "which booking links have not been confirmed as official" and
 * "what did we publish this week" are questions across all temples, and
 * answering them by opening temples one at a time is not answering them.
 */
class TemplePujaResource extends Resource
{
    protected static ?string $model = TemplePuja::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-fire';

    protected static string|\UnitEnum|null $navigationGroup = 'Temples';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Puja & Sevas';

    protected static ?string $modelLabel = 'puja or seva';

    protected static ?string $pluralModelLabel = 'pujas and sevas';

    protected static ?string $slug = 'pujas';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        // The same form as inside the temple: one definition, so the fee and
        // booking rules cannot differ between the two screens.
        return TemplePujaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('temple:id,name,city'))
            ->columns([
                MediaColumn::make(
                    'image',
                    fn (TemplePuja $record): ?string => $record->image_path,
                    fn (TemplePuja $record): string => $record->image_disk ?? config('filesystems.media'),
                )->label('')->height(40)->circular(),

                TextColumn::make('name')
                    ->label('Puja / seva')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->wrap(),

                TextColumn::make('temple.name')
                    ->label('Temple')
                    ->searchable()
                    ->sortable()
                    ->description(fn (TemplePuja $record): ?string => $record->temple?->city)
                    ->wrap(),

                TextColumn::make('fee')
                    ->label('Fee')
                    ->state(fn (TemplePuja $record): string => $record->feeLabel())
                    ->badge()
                    // "No published price" is not free, and the colour must
                    // not suggest otherwise.
                    ->color(fn (TemplePuja $record): string => match (true) {
                        (bool) $record->is_free => 'success',
                        filled($record->fee_amount) => 'info',
                        default => 'warning',
                    }),

                TextColumn::make('starts_at')
                    ->label('Time')
                    ->time('H:i')
                    ->placeholder('—')
                    ->toggleable(),

                IconColumn::make('booking_is_official')
                    ->label('Official booking')
                    ->boolean()
                    ->tooltip(fn (TemplePuja $record): string => $record->booking_is_official
                        ? 'An editor confirmed this is the temple\'s own booking route'
                        : 'Not confirmed as official. A link that looks official is not.')
                    ->toggleable(),

                IconColumn::make('app_booking_enabled')
                    ->label('In app')
                    ->state(fn (TemplePuja $record): bool => $record->isBookableInApp())
                    ->boolean()
                    ->trueIcon('heroicon-o-device-phone-mobile')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray')
                    ->tooltip(fn (TemplePuja $record): string => $record->isBookableInApp()
                        ? 'Devotees book this in the app'
                        : 'Information only; booking in the app is off')
                    ->toggleable(),

                IconColumn::make('is_published')->label('Published')->boolean()->sortable(),

                TextColumn::make('updated_at')->label('Updated')->since()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('temple')->relationship('temple', 'name')->searchable()->preload(),

                Filter::make('published')
                    ->label('Published only')
                    ->query(fn (Builder $query): Builder => $query->where('is_published', true))
                    ->toggle(),

                /*
                 * The data-quality filters, which are the reason this list
                 * exists at all.
                 */
                Filter::make('no_price')
                    ->label('No published price')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('is_free', false)
                        ->whereNull('fee_amount'))
                    ->toggle(),

                Filter::make('bookable_in_app')
                    ->label('Bookable in the app')
                    ->query(fn (Builder $query): Builder => $query->where('app_booking_enabled', true))
                    ->toggle(),

                Filter::make('unconfirmed_booking')
                    ->label('Booking link not confirmed official')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('booking_url')
                        ->where('booking_is_official', false))
                    ->toggle(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('updated_at', 'desc')
            ->persistFiltersInSession()
            ->emptyStateIcon('heroicon-o-fire')
            ->emptyStateHeading('No pujas or sevas yet')
            ->emptyStateDescription('Add them inside a temple record. They all appear here, which is where to check prices and booking links across every temple at once.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTemplePujas::route('/'),
            'edit' => EditTemplePuja::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
    }

    public static function canCreate(): bool
    {
        // A puja without a temple is meaningless, so it is created inside one.
        return false;
    }
}
