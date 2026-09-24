<?php

namespace App\Filament\Resources\DevoteeSubscriptions;

use App\Filament\Resources\DevoteeSubscriptions\Pages\ManageDevoteeSubscriptions;
use App\Models\Devotee;
use App\Models\DevoteeSubscription;
use App\Models\SubscriptionPlan;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/** Who holds which plan: bought in the app, or granted here. */
class DevoteeSubscriptionResource extends Resource
{
    protected static ?string $model = DevoteeSubscription::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static string|\UnitEnum|null $navigationGroup = 'Monetisation';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Subscribers';

    protected static ?string $modelLabel = 'subscription';

    protected static ?string $slug = 'monetisation/subscribers';

    public static function canAccess(): bool
    {
        return Auth::user()?->canManageUsers() ?? false;
    }

    /** The grant form: a plan given without payment (a temple partner, a support gesture). */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('devotee_id')->label('Devotee')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => Devotee::query()->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn (Devotee $d) => [$d->id => $d->name.' — '.($d->email ?? $d->phone ?? '#'.$d->id)])->all())
                ->getOptionLabelUsing(fn ($value) => Devotee::query()->whereKey($value)->value('name')),
            Select::make('subscription_plan_id')->label('Plan')->required()->options(fn () => SubscriptionPlan::query()->orderBy('sort_order')->pluck('name', 'id')->all()),
            TextInput::make('days')->label('Days')->numeric()->minValue(1)->maxValue(3660)->helperText('Leave empty for the plan\'s own length.'),
            TextInput::make('note')->maxLength(255)->placeholder('Why it was granted'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['devotee:id,name,email', 'plan:id,name', 'payment:id,uuid,gateway', 'grantedBy:id,name']))
            ->columns([
                TextColumn::make('devotee.name')->label('Devotee')->searchable()->description(fn (DevoteeSubscription $r) => $r->devotee?->email),
                TextColumn::make('plan.name')->label('Plan'),
                TextColumn::make('starts_at')->label('From')->date('d M Y')->sortable(),
                TextColumn::make('ends_at')->label('Until')->date('d M Y')->sortable(),
                TextColumn::make('state')->state(fn (DevoteeSubscription $r) => $r->cancelled_at ? 'Cancelled' : ($r->isCurrent() ? 'Active' : ($r->starts_at->isFuture() ? 'Queued' : 'Ended')))
                    ->badge()->color(fn (string $state) => match ($state) { 'Active' => 'success', 'Queued' => 'info', 'Cancelled' => 'danger', default => 'gray' }),
                TextColumn::make('source')->state(fn (DevoteeSubscription $r) => $r->payment ? 'Paid · '.ucfirst($r->payment->gateway) : 'Granted by '.($r->grantedBy?->name ?? 'staff'))->description(fn (DevoteeSubscription $r) => $r->note),
            ])
            ->filters([
                Filter::make('active')->label('Active now')->query(fn (Builder $query) => $query->current())->toggle()->default(),
            ])
            ->recordActions([
                Action::make('cancel')->label('Cancel')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (DevoteeSubscription $r) => $r->cancelled_at === null && $r->ends_at->isFuture())
                    ->requiresConfirmation()
                    ->modalDescription('The benefits stop now. A payment is not refunded by this: refund it under Payments.')
                    ->action(fn (DevoteeSubscription $r) => $r->forceFill(['cancelled_at' => now()])->save()),
            ])
            ->defaultSort('ends_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageDevoteeSubscriptions::route('/')];
    }
}
