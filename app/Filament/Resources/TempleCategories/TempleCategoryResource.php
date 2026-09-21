<?php

namespace App\Filament\Resources\TempleCategories;

use App\Filament\Resources\TempleCategories\Pages\CreateTempleCategory;
use App\Filament\Resources\TempleCategories\Pages\EditTempleCategory;
use App\Filament\Resources\TempleCategories\Pages\ListTempleCategories;
use App\Filament\Resources\TempleCategories\Schemas\TempleCategoryForm;
use App\Filament\Resources\TempleCategories\Tables\TempleCategoriesTable;
use App\Models\TempleCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class TempleCategoryResource extends Resource
{
    protected static ?string $model = TempleCategory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-plus';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TempleCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TempleCategoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTempleCategories::route('/'),
            'create' => CreateTempleCategory::route('/create'),
            'edit' => EditTempleCategory::route('/{record}/edit'),
        ];
    }
}
