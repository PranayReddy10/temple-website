<?php

namespace App\Filament\Resources\Devotees;

use App\Filament\Resources\Devotees\Pages\ListDevotees;
use App\Filament\Resources\Devotees\Pages\ViewDevotee;
use App\Filament\Resources\Devotees\RelationManagers\MemoriesRelationManager;
use App\Filament\Resources\Devotees\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Devotees\RelationManagers\VisitsRelationManager;
use App\Filament\Resources\Devotees\RelationManagers\YatrasRelationManager;
use App\Filament\Resources\Devotees\Tables\DevoteesTable;
use App\Models\Devotee;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * App users.
 *
 * No create form and no free edit page: a devotee's account is theirs. What
 * staff legitimately need is to see who is using the product, answer a
 * support request, and suspend an account that is abusing it. So the writes
 * here are each a deliberate action, super admins only: edit details at the
 * devotee's request, mark an email or phone verified once staff have
 * checked it (or remove that), reset a password, suspend or restore.
 *
 * Memories are the sharp edge. A devotee's private writing about what they
 * prayed for is not staff's to read, so the list shows that a memory exists
 * and when, and shows its text only where the devotee chose to share it.
 */
class DevoteeResource extends Resource
{
    protected static ?string $model = Devotee::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Devotees';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Devotee Accounts';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return DevoteesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            VisitsRelationManager::class,
            YatrasRelationManager::class,
            PhotosRelationManager::class,
            MemoriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDevotees::route('/'),
            'view' => ViewDevotee::route('/{record}'),
        ];
    }

    /** Soft-deleted accounts are hidden unless the trashed filter asks for them. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount([
            'visits',
            'yatras',
            'photos',
            'memories',
            'savedTemples',
        ]);
    }

    public static function getNavigationBadge(): ?string
    {
        return number_format(Devotee::query()->count());
    }

    /** Devotee records are staff-wide; a temple admin never sees this panel. */
    public static function canAccess(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
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
}
