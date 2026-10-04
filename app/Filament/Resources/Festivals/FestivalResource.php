<?php

namespace App\Filament\Resources\Festivals;

use App\Filament\Resources\Festivals\Pages\CreateFestival;
use App\Filament\Resources\Festivals\Pages\EditFestival;
use App\Filament\Resources\Festivals\Pages\ListFestivals;
use App\Models\Festival;
use App\Support\DevotionalClock;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * India's festival and vrat calendar as devotees see it in the app. Dates
 * are computed from the panchang (New Delhi); a regional almanac can differ
 * by a day, and any date corrected here stays corrected on later imports.
 */
class FestivalResource extends Resource
{
    protected static ?string $model = Festival::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Daily Devotion';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Festival calendar';

    protected static ?string $slug = 'festivals';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(160)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $set, $get) => blank($get('slug')) ? $set('slug', Str::slug((string) $state)) : null),
                TextInput::make('slug')->required()->maxLength(80),
                DatePicker::make('starts_on')->label('Date')->required()->native(false),
                DatePicker::make('ends_on')->label('Until')->afterOrEqual('starts_on')->native(false)->helperText('Only for a festival of several days.'),
                Select::make('kind')->options(Festival::KINDS)->default('festival')->required()->native(false),
                TextInput::make('tithi')->label('Tithi / almanac day')->maxLength(80)->placeholder('Chaitra Shukla Navami'),
                TextInput::make('deity')->maxLength(40)->placeholder('rama, shiva, durga…')->helperText('A deity slug: the app colours the card in that deity\'s day.'),
                Toggle::make('is_major')->label('Major festival')->inline(false),
                Textarea::make('description')->rows(3)->columnSpanFull(),
                Toggle::make('is_published')->label('Shown in the app')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('starts_on')->label('Date')->date('D, d M Y')->sortable(),
                TextColumn::make('name')->weight('medium')->searchable()->description(fn (Festival $f): ?string => $f->tithi),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state): string => $state === 'vrat' ? 'Vrat' : 'Festival')
                    ->color(fn (string $state): string => $state === 'vrat' ? 'gray' : 'warning'),
                IconColumn::make('is_major')->label('Major')->boolean(),
                TextColumn::make('computed_on')->label('Computed')->date('d M')->toggleable(isToggledHiddenByDefault: true)
                    ->description(fn (Festival $f): ?string => $f->computed_on && ! $f->computed_on->isSameDay($f->starts_on) ? 'moved by an editor' : null),
                IconColumn::make('is_published')->label('Shown')->boolean(),
            ])
            ->filters([
                Filter::make('ahead')->label('Today and ahead')->query(fn (Builder $query): Builder => $query->whereDate('starts_on', '>=', DevotionalClock::now()->toDateString()))->toggle()->default(),
                SelectFilter::make('kind')->options(Festival::KINDS)->default('festival'),
                TernaryFilter::make('is_major')->label('Major'),
                SelectFilter::make('year')->options(fn (): array => Festival::query()->selectRaw('distinct substr(starts_on, 1, 4) as y')->orderBy('y')->pluck('y', 'y')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->whereYear('starts_on', $data['value'])),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('starts_on')
            ->emptyStateHeading('No festivals loaded')
            ->emptyStateDescription('Run php artisan festivals:import to load the computed calendar.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFestivals::route('/'),
            'create' => CreateFestival::route('/create'),
            'edit' => EditFestival::route('/{record}/edit'),
        ];
    }
}
