<?php

namespace App\Filament\Temple\Resources\HundiDonations;

use App\Filament\Temple\Resources\HundiDonations\Pages\ListHundiDonations;
use App\Filament\Temple\TemplePortal;
use App\Models\TempleDonation;
use App\Support\DevotionalClock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The online hundi as the temple sees it: every gift, newest first. A gift
 * made anonymously reads "A devotee" here and never answers to a search for
 * who gave it; only the receipt reference finds it.
 */
class HundiDonationResource extends Resource
{
    protected static ?string $model = TempleDonation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-gift';

    protected static string|UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Online hundi';

    protected static ?string $modelLabel = 'hundi gift';

    protected static ?string $pluralModelLabel = 'online hundi';

    protected static ?string $slug = 'hundi';

    public static function canAccess(): bool
    {
        return Auth::user()?->isTempleAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('temple_id', Auth::user()?->approvedTempleIds() ?? [])
            ->whereIn('status', [TempleDonation::PAID, TempleDonation::REFUNDED]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function table(Table $table): Table
    {
        $several = count(Auth::user()?->approvedTempleIds() ?? []) > 1;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['temple:id,name', 'devotee:id,name']))
            ->searchPlaceholder('Receipt or donor name')
            ->columns([
                TextColumn::make('paid_at')->label('When')->dateTime('d M Y, g:i A', DevotionalClock::timezone())->sortable(),
                TextColumn::make('temple.name')->label('Temple')->visible($several),
                TextColumn::make('donor')->label('Given by')
                    ->state(fn (TempleDonation $d): string => $d->displayName())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(self::donorMatching($search)))
                    ->description(fn (TempleDonation $d): ?string => $d->note),
                TextColumn::make('purpose')->formatStateUsing(fn (TempleDonation $d): string => $d->purposeLabel())->badge()->color('gray'),
                TextColumn::make('amount_paise')->label('Amount')->state(fn (TempleDonation $d): string => $d->amountLabel())->alignEnd()->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (TempleDonation $d): string => $d->statusLabel())
                    ->color(fn (string $state): string => $state === TempleDonation::PAID ? 'success' : 'danger'),
                TextColumn::make('reference')->label('Receipt')->fontFamily('mono')->copyable(),
                TextColumn::make('settlement_id')->label('Paid out')->state(fn (TempleDonation $d): string => $d->settlement_id ? 'Yes' : 'Not yet'),
            ])
            ->filters([
                SelectFilter::make('temple_id')->label('Temple')
                    ->options(fn (): array => TemplePortal::options())
                    ->visible($several),
                Filter::make('today')->label('Today')->toggle()
                    ->query(fn (Builder $q) => $q->whereDate('paid_on', DevotionalClock::now()->toDateString())),
                Filter::make('month')->label('This month')->toggle()
                    ->query(fn (Builder $q) => $q->whereDate('paid_on', '>=', DevotionalClock::now()->startOfMonth()->toDateString())),
                SelectFilter::make('purpose')->options(TempleDonation::PURPOSES),
            ])
            ->defaultSort('paid_at', 'desc')
            ->emptyStateIcon('heroicon-o-gift')
            ->emptyStateHeading('No hundi gifts yet')
            ->emptyStateDescription('Once the online hundi is on, gifts devotees make in the app appear here.');
    }

    /**
     * Gifts a typed search means: a receipt reference finds any gift; a name
     * or phone number only gifts made in the donor's name.
     */
    public static function donorMatching(string $search): \Closure
    {
        $search = trim($search);
        $digits = preg_replace('/\D/', '', $search);
        $phone = strlen($digits) >= 4 && preg_match('/[A-Za-z]/', $search) !== 1 ? substr($digits, -10) : null;
        $ref = strtoupper(preg_replace('/\s+/', '', $search));

        return function (Builder $q) use ($search, $phone, $ref): void {
            $q->where(fn (Builder $r) => strlen($ref) >= 6 ? $r->where('reference', 'like', $ref.'%') : $r->whereRaw('1 = 0'))
                ->orWhere(function (Builder $named) use ($search, $phone): void {
                    $named->where('is_anonymous', false)->where(function (Builder $w) use ($search, $phone): void {
                        if ($phone !== null) {
                            $digitsOf = "replace(replace(replace(replace(replace(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
                            $w->whereHas('devotee', fn (Builder $d) => $d->whereRaw($digitsOf.' like ?', ['%'.$phone.'%']));
                        } else {
                            $w->where('donor_name', 'like', '%'.$search.'%')
                                ->orWhereHas('devotee', fn (Builder $d) => $d->where('name', 'like', '%'.$search.'%'));
                        }
                    });
                });
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListHundiDonations::route('/')];
    }
}
