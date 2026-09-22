<?php

namespace App\Filament\Temple\Resources\MyTemples;

use App\Filament\Resources\Temples\RelationManagers\ClosuresRelationManager;
use App\Filament\Resources\Temples\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Temples\RelationManagers\PujasRelationManager;
use App\Filament\Resources\Temples\RelationManagers\TimingsRelationManager;
use App\Filament\Temple\Resources\MyTemples\Pages\EditMyTemple;
use App\Filament\Temple\Resources\MyTemples\Pages\ListMyTemples;
use App\Filament\Temple\Resources\MyTemples\Schemas\MyTempleForm;
use App\Filament\Temple\Resources\MyTemples\Tables\MyTemplesTable;
use App\Models\Temple;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MyTempleResource extends Resource
{
    protected static ?string $model = Temple::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'My Temples';

    protected static ?string $modelLabel = 'temple';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'temples';

    public static function form(Schema $schema): Schema
    {
        return MyTempleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MyTemplesTable::configure($table);
    }

    /**
     * The security boundary for this whole panel.
     *
     * Every list, count and search in the temple portal runs through here, and
     * it returns nothing at all unless the signed-in user has an approved
     * claim on the temple.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('id', static::visibleTempleIds());
    }

    /**
     * The same boundary for a record reached directly by URL.
     *
     * Filament's default for this falls back to the scoped query above, so on
     * current versions either one alone already closes
     * /temple/temples/{id}/edit. Both are stated explicitly on purpose: the
     * fallback is framework behaviour we do not control, and a future change
     * to it, or to one of these methods, must not be able to silently open
     * that URL. Verified by removing each in turn and confirming the tests
     * still fail only when both are gone.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->whereIn('id', static::visibleTempleIds());
    }

    /** @return array<int, int> */
    protected static function visibleTempleIds(): array
    {
        // A missing user yields an empty list rather than an unscoped query.
        return Auth::user()?->approvedTempleIds() ?? [];
    }

    public static function canCreate(): bool
    {
        // New temples are added by editorial staff, not by temple teams.
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            PhotosRelationManager::class,
            TimingsRelationManager::class,
            PujasRelationManager::class,
            ClosuresRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyTemples::route('/'),
            'edit' => EditMyTemple::route('/{record}/edit'),
        ];
    }
}
