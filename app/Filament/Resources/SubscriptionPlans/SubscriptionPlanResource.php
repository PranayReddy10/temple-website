<?php

namespace App\Filament\Resources\SubscriptionPlans;

use App\Filament\Resources\SubscriptionPlans\Pages\ManageSubscriptionPlans;
use App\Models\SubscriptionPlan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * What devotees can buy. A plan is never deleted once sold — it is switched
 * off — because subscriptions and payments point at it.
 */
class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static string|\UnitEnum|null $navigationGroup = 'Monetisation';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Plans';

    protected static ?string $modelLabel = 'plan';

    protected static ?string $slug = 'monetisation/plans';

    public static function canAccess(): bool
    {
        return Auth::user()?->canManageUsers() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Plan')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(80)->placeholder('Yatri Plus'),
                TextInput::make('code')->required()->maxLength(40)->alphaDash()->unique(ignoreRecord: true)
                    ->helperText('Never changes once sold; the app refers to the plan by it.')
                    ->disabledOn('edit'),
                Textarea::make('description')->rows(2)->maxLength(500)->columnSpanFull(),
                TextInput::make('price_rupees')->label('Price (₹)')->numeric()->minValue(1)->maxValue(100000)->step(0.01)->required()
                    ->afterStateHydrated(fn (TextInput $component, ?SubscriptionPlan $record) => $component->state($record ? $record->price_paise / 100 : null)),
                Select::make('duration_days')->label('Length')->required()->native(false)->options([
                    30 => '1 month', 90 => '3 months', 180 => '6 months', 365 => '1 year',
                ]),
                TextInput::make('badge')->maxLength(40)->placeholder('Most popular'),
                TextInput::make('sort_order')->numeric()->default(0),
                Toggle::make('is_active')->label('On sale')->default(true),
            ]),
            Section::make('What it includes')->columns(3)->schema([
                Toggle::make('benefits.no_ads')->label('No ads'),
                TextInput::make('benefits.memory_photos_per_visit')->label('Memory photos per visit')->numeric()->minValue(3)->maxValue(50)
                    ->helperText('The free app keeps 3.'),
                Toggle::make('benefits.premium_passport')->label('Gold edition passport cover'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->weight('medium')->description(fn (SubscriptionPlan $r) => $r->code),
                TextColumn::make('price')->state(fn (SubscriptionPlan $r) => $r->priceLabel().' '.$r->periodLabel()),
                TextColumn::make('benefits')->label('Includes')->state(fn (SubscriptionPlan $r) => collect([
                    ($r->benefits['no_ads'] ?? false) ? 'No ads' : null,
                    ($r->benefits['memory_photos_per_visit'] ?? null) ? $r->benefits['memory_photos_per_visit'].' memory photos' : null,
                    ($r->benefits['premium_passport'] ?? false) ? 'Gold passport' : null,
                ])->filter()->implode(' · '))->wrap(),
                TextColumn::make('subscriptions_count')->counts('subscriptions')->label('Sold')->alignEnd(),
                IconColumn::make('is_active')->label('On sale')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data): array => static::withPrice($data)),
            ])
            ->emptyStateHeading('No plans yet')
            ->emptyStateDescription('Create a plan such as "Yatri Plus — no ads, 10 memory photos a visit, ₹49 a month".');
    }

    /** @param  array<string, mixed>  $data */
    public static function withPrice(array $data): array
    {
        // Typed in rupees, stored in paise: ₹49.50 is 4950, never a float.
        $rupees = $data['price_rupees'] ?? null;
        unset($data['price_rupees']);

        if ($rupees !== null && $rupees !== '') {
            $data['price_paise'] = (int) round(((float) $rupees) * 100);
        }

        $benefits = (array) ($data['benefits'] ?? []);
        $data['benefits'] = array_filter([
            'no_ads' => (bool) ($benefits['no_ads'] ?? false),
            'memory_photos_per_visit' => filled($benefits['memory_photos_per_visit'] ?? null) ? (int) $benefits['memory_photos_per_visit'] : null,
            'premium_passport' => (bool) ($benefits['premium_passport'] ?? false),
        ], fn ($v) => $v !== null && $v !== false);

        return $data;
    }

    public static function getPages(): array
    {
        return ['index' => ManageSubscriptionPlans::route('/')];
    }
}
