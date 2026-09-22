<?php

namespace App\Filament\Resources\DevotionalDays;

use App\Filament\Resources\DevotionalDays\Pages\CreateDevotionalDay;
use App\Filament\Resources\DevotionalDays\Pages\EditDevotionalDay;
use App\Filament\Resources\DevotionalDays\Pages\ListDevotionalDays;
use App\Filament\Resources\DevotionalDays\RelationManagers\MediaRelationManager;
use App\Filament\Resources\DevotionalDays\Schemas\DevotionalDayForm;
use App\Filament\Resources\DevotionalDays\Tables\DevotionalDaysTable;
use App\Models\DevotionalDay;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class DevotionalDayResource extends Resource
{
    protected static ?string $model = DevotionalDay::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Daily Devotion';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Days & Deities';

    protected static ?string $modelLabel = 'devotional day';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return DevotionalDayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DevotionalDaysTable::configure($table);
    }

    /** Shows which deity is leading today without opening the page. */
    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::query()
            ->where('weekday', now()->dayOfWeek)
            ->where('is_active', true)
            ->with('deity')
            ->orderBy('sort_order')
            ->first()?->deity?->name;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Today is '.now()->format('l');
    }

    public static function getRelations(): array
    {
        return [
            MediaRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDevotionalDays::route('/'),
            'create' => CreateDevotionalDay::route('/create'),
            'edit' => EditDevotionalDay::route('/{record}/edit'),
        ];
    }
}
