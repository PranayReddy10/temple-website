<?php

namespace App\Filament\Resources\Devotees\Tables;

use App\Models\Devotee;
use App\Support\Locales;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class DevoteesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Devotee $record): ?string => $record->email ?? $record->phone),

                TextColumn::make('contact')
                    ->label('Signs in with')
                    ->state(fn (Devotee $record): string => match (true) {
                        filled($record->email) && filled($record->phone) => 'Email and phone',
                        filled($record->email) => 'Email',
                        filled($record->phone) => 'Phone',
                        default => 'Neither',
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Neither' ? 'danger' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('locale')
                    ->label('Language')
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : (Locales::supported()[$state]['name'] ?? $state))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('visits_count')
                    ->label('Visits')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('yatras_count')
                    ->label('Trips')
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('saved_temples_count')
                    ->label('Saved')
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_verified')
                    ->label('Verified')
                    ->state(fn (Devotee $record): bool => $record->isVerified())
                    ->boolean()
                    ->tooltip(fn (Devotee $record): string => $record->isVerified()
                        ? 'Email or phone confirmed'
                        : 'Neither email nor phone confirmed')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('locale')
                    ->label('Language')
                    ->options(fn (): array => collect(Locales::supported())
                        ->map(fn (array $l): string => $l['name'])
                        ->all()),

                Filter::make('active')
                    ->label('Active accounts only')
                    ->query(fn (Builder $query): Builder => $query->where('is_active', true))
                    ->toggle(),

                Filter::make('unverified')
                    ->label('Neither email nor phone confirmed')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNull('email_verified_at')
                        ->whereNull('phone_verified_at'))
                    ->toggle(),

                Filter::make('signed_in_recently')
                    ->label('Seen in the last 30 days')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('last_seen_at', '>=', now()->subDays(30)))
                    ->toggle(),

                Filter::make('dormant')
                    ->label('Never signed in')
                    ->query(fn (Builder $query): Builder => $query->whereNull('last_seen_at'))
                    ->toggle(),

                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),

                /*
                 * Suspension, not deletion.
                 *
                 * The account keeps its visits, photos and trips — a devotee
                 * suspended by mistake gets their pilgrimage record back when
                 * it is reversed, and a real abuser's history stays available
                 * to whoever has to deal with the consequences.
                 */
                Action::make('deactivate')
                    ->label('Suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (Devotee $record): bool => (bool) $record->is_active
                        && (Auth::user()?->canManageUsers() ?? false))
                    ->requiresConfirmation()
                    ->modalDescription('They can no longer sign in. Their visits, photos and trips are kept.')
                    ->action(fn (Devotee $record) => $record->forceFill(['is_active' => false])->save()),

                Action::make('reactivate')
                    ->label('Restore access')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Devotee $record): bool => ! $record->is_active
                        && (Auth::user()?->canManageUsers() ?? false))
                    ->requiresConfirmation()
                    ->action(fn (Devotee $record) => $record->forceFill(['is_active' => true])->save()),
            ])
            ->defaultSort('created_at', 'desc')
            ->persistFiltersInSession()
            ->emptyStateIcon('heroicon-o-user-group')
            ->emptyStateHeading('No devotees yet')
            ->emptyStateDescription('Accounts appear here as people sign up in the app.');
    }
}
