<?php

namespace App\Filament\Resources\TempleAccess\Tables;

use App\Filament\Resources\TempleAccess\Schemas\TempleAccessForm;
use App\Models\TempleUser;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The columns, filters and approval actions shared by both views of a claim.
 *
 * The nested view answers "who runs this temple"; the side-menu view answers
 * "what is waiting on me". Same rows, same actions, different starting point.
 */
class TempleAccessTable
{
    /** @return array<int, TextColumn> */
    public static function columns(bool $withTemple = true): array
    {
        return array_values(array_filter([
            $withTemple
                ? TextColumn::make('temple.name')
                    ->label('Temple')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->wrap()
                : null,

            TextColumn::make('user.name')
                ->label('Account')
                ->searchable()
                ->weight($withTemple ? null : 'medium'),

            TextColumn::make('user.email')->label('Email')->searchable()->copyable(),

            TextColumn::make('role')
                ->label('Level')
                ->badge()
                ->formatStateUsing(fn (string $state): string => ucfirst($state)),

            TextColumn::make('status')
                ->label('Status')
                ->state(fn (TempleUser $record): string => $record->status())
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'approved' => 'success',
                    'rejected' => 'danger',
                    default => 'warning',
                }),

            TextColumn::make('approver.name')
                ->label('Approved by')
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('approved_at')->label('Approved')->since()->placeholder('—')->sortable(),
        ]));
    }

    /** @return array<int, mixed> */
    public static function filters(): array
    {
        return [
            Filter::make('pending')
                ->label('Awaiting approval')
                ->query(fn (Builder $query): Builder => $query->pending())
                ->toggle(),

            Filter::make('approved')
                ->label('Active access only')
                ->query(fn (Builder $query): Builder => $query->approved())
                ->toggle(),

            SelectFilter::make('role')->label('Level')->options(TempleAccessForm::levels()),
        ];
    }

    /** @return array<int, mixed> */
    public static function recordActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (TempleUser $record): bool => ! $record->isApproved())
                ->requiresConfirmation()
                ->modalDescription('This gives the account edit access to this temple.')
                ->action(fn (TempleUser $record) => $record->update([
                    'approved_at' => now(),
                    'approved_by' => Auth::id(),
                    'rejection_reason' => null,
                ])),

            Action::make('revoke')
                ->label('Revoke')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (TempleUser $record): bool => $record->isApproved())
                ->requiresConfirmation()
                ->modalDescription('Access is removed immediately. The claim record is kept.')
                ->form([
                    Textarea::make('rejection_reason')
                        ->label('Reason')
                        ->required()
                        ->rows(2),
                ])
                ->action(fn (TempleUser $record, array $data) => $record->update([
                    'approved_at' => null,
                    'rejection_reason' => $data['rejection_reason'],
                ])),

            DeleteAction::make(),
        ];
    }

    /**
     * Staff creating a claim are the verification, so it is approved in the
     * same act rather than left for someone to approve after themselves.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function stampAsGrantedByStaff(array $data): array
    {
        $data['requested_at'] = now();
        $data['approved_at'] = now();
        $data['approved_by'] = Auth::id();

        return $data;
    }

    public static function applyEmptyState(Table $table): Table
    {
        return $table
            ->emptyStateIcon('heroicon-o-key')
            ->emptyStateHeading('No temple access granted yet')
            ->emptyStateDescription('Grant access so a temple\'s own trust can maintain its timings, photos, sevas and events. Creating the account is part of the same form.');
    }
}
