<?php

namespace App\Filament\Resources\Temples\RelationManagers;

use App\Enums\UserRole;
use App\Models\TempleUser;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Staff-side approval of temple authority claims.
 *
 * A claim is an assertion that someone represents this temple. Approving one
 * hands them edit access to it, so approval is a deliberate staff action with
 * a recorded approver, never an automatic consequence of signing up.
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
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Account')
                    ->options(fn (): array => User::where('role', UserRole::TempleAdmin)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->helperText('Only accounts with the Temple Admin role appear here. Create one under Users first.'),

                Select::make('role')
                    ->label('Level')
                    ->options([
                        'owner' => 'Owner — the trust or temple office',
                        'manager' => 'Manager — day-to-day staff',
                    ])
                    ->default('manager')
                    ->required()
                    ->native(false),

                Textarea::make('claim_note')
                    ->label('Claim note')
                    ->rows(3)
                    ->helperText('How the claim was verified, e.g. confirmed by phone with the temple office.')
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label('Account')->weight('medium'),
                TextColumn::make('user.email')->label('Email')->copyable(),

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

                TextColumn::make('approved_at')->label('Approved')->since()->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Grant access')
                    ->mutateDataUsing(function (array $data): array {
                        $data['requested_at'] = now();
                        // Created by staff, so it is approved in the same act.
                        $data['approved_at'] = now();
                        $data['approved_by'] = Auth::id();

                        return $data;
                    }),
            ])
            ->recordActions([
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
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No temple access granted')
            ->emptyStateDescription('Grant access so this temple\'s own team can maintain its timings, photos and sevas.');
    }
}
