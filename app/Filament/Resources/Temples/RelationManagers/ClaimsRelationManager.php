<?php

namespace App\Filament\Resources\Temples\RelationManagers;

use App\Filament\Resources\TempleAccess\Schemas\TempleAccessForm;
use App\Filament\Resources\TempleAccess\Tables\TempleAccessTable;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Staff-side approval of temple authority claims, in the temple's own record.
 *
 * A claim is an assertion that someone represents this temple. Approving one
 * hands them edit access to it, so approval is a deliberate staff action with
 * a recorded approver, never an automatic consequence of signing up.
 *
 * The form and actions come from TempleAccessForm/TempleAccessTable, which
 * the side-menu resource uses too: the same decision reached from either
 * direction must behave the same way.
 */
class ClaimsRelationManager extends RelationManager
{
    protected static string $relationship = 'claims';

    protected static ?string $title = 'Temple authority access';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-key';

    /** Granting access to a temple is a super-admin decision. */
    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->canManageUsers() ?? false;
    }

    public function form(Schema $schema): Schema
    {
        // The temple is already decided by the record we are nested inside.
        return TempleAccessForm::configure($schema, withTemple: false);
    }

    public function table(Table $table): Table
    {
        return TempleAccessTable::applyEmptyState(
            $table
                ->recordTitleAttribute('id')
                ->modifyQueryUsing(fn ($query) => $query->with(['user', 'approver']))
                ->columns(TempleAccessTable::columns(withTemple: false))
                ->filters(TempleAccessTable::filters())
                ->headerActions([
                    CreateAction::make()
                        ->label('Grant access')
                        ->modalHeading('Grant access to this temple')
                        ->mutateDataUsing(TempleAccessTable::stampAsGrantedByStaff(...)),
                ])
                ->recordActions(TempleAccessTable::recordActions())
                ->defaultSort('created_at', 'desc')
        );
    }
}
