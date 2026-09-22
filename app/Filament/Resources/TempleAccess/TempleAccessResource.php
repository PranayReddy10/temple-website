<?php

namespace App\Filament\Resources\TempleAccess;

use App\Filament\Resources\TempleAccess\Pages\ListTempleAccess;
use App\Filament\Resources\TempleAccess\Schemas\TempleAccessForm;
use App\Filament\Resources\TempleAccess\Tables\TempleAccessTable;
use App\Models\TempleUser;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Every temple trust's access, in one place in the side menu.
 *
 * The same rows are editable from inside a temple, which is the right place
 * when the temple is what you are thinking about. This is the other half:
 * approvals are a queue, and a queue you can only reach by first guessing
 * which temple it belongs to is not a queue.
 */
class TempleAccessResource extends Resource
{
    protected static ?string $model = TempleUser::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Temple Trust Access';

    protected static ?string $modelLabel = 'temple access';

    protected static ?string $pluralModelLabel = 'temple access';

    protected static ?string $slug = 'temple-access';

    public static function form(Schema $schema): Schema
    {
        return TempleAccessForm::configure($schema, withTemple: true);
    }

    public static function table(Table $table): Table
    {
        return TempleAccessTable::applyEmptyState(
            $table
                ->modifyQueryUsing(fn ($query) => $query->with(['temple', 'user', 'approver']))
                ->columns(TempleAccessTable::columns(withTemple: true))
                ->filters(TempleAccessTable::filters())
                ->headerActions([
                    CreateAction::make()
                        ->label('Grant access')
                        ->modalHeading('Grant temple access')
                        ->mutateDataUsing(TempleAccessTable::stampAsGrantedByStaff(...)),
                ])
                ->recordActions(TempleAccessTable::recordActions())
                ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
                ->defaultSort('created_at', 'desc')
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTempleAccess::route('/'),
        ];
    }

    /** Pending claims are the reason to look, so the count is the badge. */
    public static function getNavigationBadge(): ?string
    {
        $pending = TempleUser::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** Granting access to a temple is a super-admin decision. */
    public static function canAccess(): bool
    {
        return Auth::user()?->canManageUsers() ?? false;
    }
}
