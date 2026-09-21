<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('role')
                    ->badge()
                    ->color(fn (UserRole $state): string => $state === UserRole::SuperAdmin ? 'danger' : 'gray')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('last_login_at')
                    ->label('Last sign-in')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')->options(UserRole::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    // Deleting yourself would lock you out mid-session, and
                    // deleting the last super admin would lock everyone out.
                    ->hidden(fn (User $record): bool => $record->is(Auth::user()))
                    ->before(function (User $record, DeleteAction $action): void {
                        if ($record->isSuperAdmin() && User::where('role', UserRole::SuperAdmin)->count() <= 1) {
                            $action->failureNotificationTitle('Cannot delete the only super admin.');
                            $action->failure();
                            $action->halt();
                        }
                    }),
            ])
            ->defaultSort('name');
    }
}
