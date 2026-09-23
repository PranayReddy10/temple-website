<?php

namespace App\Filament\Resources\Deities;

use App\Filament\RelationManagers\DevotionalMediaRelationManager;
use App\Filament\RelationManagers\TranslationsRelationManager;
use App\Filament\Resources\Deities\Pages\CreateDeity;
use App\Filament\Resources\Deities\Pages\EditDeity;
use App\Filament\Resources\Deities\Pages\ListDeities;
use App\Filament\Resources\Deities\Schemas\DeityForm;
use App\Filament\Resources\Deities\Tables\DeitiesTable;
use App\Models\Deity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class DeityResource extends Resource
{
    protected static ?string $model = Deity::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return DeityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeitiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            // A deity's aarti plays wherever that deity is worshipped, so it
            // belongs here rather than being repeated on every weekday.
            DevotionalMediaRelationManager::class,
            TranslationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeities::route('/'),
            'create' => CreateDeity::route('/create'),
            'edit' => EditDeity::route('/{record}/edit'),
        ];
    }
}
