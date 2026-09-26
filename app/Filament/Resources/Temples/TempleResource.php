<?php

namespace App\Filament\Resources\Temples;

use App\Filament\Resources\Temples\Pages\CreateTemple;
use App\Filament\Resources\Temples\Pages\EditTemple;
use App\Filament\Resources\Temples\Pages\ListTemples;
use App\Filament\RelationManagers\DevotionalMediaRelationManager;
use App\Filament\Resources\Temples\RelationManagers\BookingsRelationManager;
use App\Filament\Resources\Temples\RelationManagers\ClaimsRelationManager;
use App\Filament\Resources\Temples\RelationManagers\ClosuresRelationManager;
use App\Filament\Resources\Temples\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Temples\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Temples\RelationManagers\PujasRelationManager;
use App\Filament\Resources\Temples\RelationManagers\TimingsRelationManager;
use App\Filament\RelationManagers\TranslationsRelationManager;
use App\Filament\Resources\Temples\Schemas\TempleForm;
use App\Filament\Resources\Temples\Tables\TemplesTable;
use App\Models\Temple;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TempleResource extends Resource
{
    protected static ?string $model = Temple::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|\UnitEnum|null $navigationGroup = 'Temples';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TempleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TemplesTable::configure($table);
    }

    /** Avoids an N+1 query for the deity, state and district columns. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['deity', 'state', 'district'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'city'];
    }

    public static function getGlobalSearchResultDetails($record): array
    {
        return [
            'Deity' => $record->deity?->name ?? '—',
            'Location' => collect([$record->city, $record->state?->name])->filter()->join(', ') ?: '—',
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        // Surfaces the review queue without an extra click.
        $pending = static::getModel()::query()->where('status', \App\Enums\TempleStatus::InReview)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Temples waiting for review';
    }

    public static function getRelations(): array
    {
        return [
            PhotosRelationManager::class,
            TimingsRelationManager::class,
            PujasRelationManager::class,
            BookingsRelationManager::class,
            ClosuresRelationManager::class,
            EventsRelationManager::class,
            // This temple's own songs. Tirumala's Suprabhatam is sung at
            // Tirumala; where a temple has none, its deity's are used.
            DevotionalMediaRelationManager::class,
            TranslationsRelationManager::class,
            ClaimsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTemples::route('/'),
            'create' => CreateTemple::route('/create'),
            'edit' => EditTemple::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
