<?php

namespace App\Filament\RelationManagers;

use App\Models\Translation;
use App\Support\Locales;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * This record in other languages — a temple, a deity, anything translatable.
 *
 * The field list comes from the model's own $translatable, so a slug or a set
 * of coordinates cannot be translated by picking it here — those produce a
 * record that is broken rather than localised.
 *
 * Reviewed is the gate to devotees. An unreviewed row is stored and visible
 * to staff but never served: a deity's name rendered wrongly in someone's own
 * language is worse than the English they can at least recognise.
 */
class TranslationsRelationManager extends RelationManager
{
    protected static string $relationship = 'translations';

    protected static ?string $title = 'Languages';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-language';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('locale')
                    ->label('Language')
                    ->options(Locales::options())
                    ->required()
                    ->native(false)
                    ->live(),

                Select::make('field')
                    ->label('Field')
                    ->options(fn (): array => $this->fieldOptions())
                    ->required()
                    ->native(false)
                    ->live()
                    ->helperText('Only fields that can be translated are listed. A slug or a coordinate is not one.'),

                // The English alongside, because translating from memory of
                // what the field said is how a dress code ends up describing
                // the wrong temple.
                Textarea::make('english')
                    ->label('English (for reference)')
                    ->rows(3)
                    ->disabled()
                    ->dehydrated(false)
                    ->columnSpanFull()
                    ->placeholder('Pick a field to see the English text.')
                    ->afterStateHydrated(fn ($component, Get $get) => $component->state(
                        $this->baseValue($get('field')),
                    ))
                    ->formatStateUsing(fn ($state, Get $get) => $this->baseValue($get('field'))),

                Textarea::make('value')
                    ->label('Translation')
                    ->rows(4)
                    ->required()
                    ->columnSpanFull(),

                Toggle::make('is_reviewed')
                    ->label('Reviewed')
                    ->helperText('Only reviewed translations are served to devotees.')
                    ->disabled(fn (): bool => ! (Auth::user()?->canPublish() ?? false))
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('field')
            ->columns([
                TextColumn::make('locale')
                    ->label('Language')
                    ->badge()
                    ->formatStateUsing(fn (Translation $record): string => $record->localeName())
                    ->sortable(),

                TextColumn::make('field')
                    ->label('Field')
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())
                    ->sortable(),

                TextColumn::make('value')->label('Translation')->limit(60)->wrap(),

                IconColumn::make('is_reviewed')
                    ->label('Served')
                    ->boolean()
                    ->tooltip(fn (Translation $record): string => $record->is_reviewed
                        ? 'Devotees see this'
                        : 'Stored, but not served until reviewed'),

                TextColumn::make('reviewer.name')->label('Reviewed by')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('locale')->label('Language')->options(Locales::options())->multiple(),

                Filter::make('unreviewed')
                    ->label('Waiting for review')
                    ->query(fn (Builder $query): Builder => $query->where('is_reviewed', false))
                    ->toggle(),
            ])
            ->headerActions([
                CreateAction::make()->label('Add a translation'),
            ])
            ->recordActions([
                Action::make('review')
                    ->label('Mark reviewed')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Translation $record): bool => ! $record->is_reviewed
                        && (Auth::user()?->canPublish() ?? false))
                    ->requiresConfirmation()
                    ->modalDescription('Devotees reading this language will see it from now on.')
                    ->action(fn (Translation $record) => $record->update([
                        'is_reviewed' => true,
                        'reviewed_by' => Auth::id(),
                    ])),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('locale')
            ->emptyStateIcon('heroicon-o-language')
            ->emptyStateHeading('Only in English so far')
            ->emptyStateDescription('Add a translation so devotees reading Telugu, Hindi or Tamil see this in their own language. On a temple the dress code and entry rules matter most: not understanding those means being turned away at the gate.');
    }

    /**
     * The owner's own translatable fields.
     *
     * Read from the model rather than listed here, so a slug or a set of
     * coordinates cannot be translated by picking it: those produce a record
     * that is broken rather than localised, and the model is the only place
     * that knows which is which.
     *
     * @return array<string, string>
     */
    protected function fieldOptions(): array
    {
        $record = $this->getOwnerRecord();

        return collect($record->translatableFields())
            ->mapWithKeys(fn (string $field): array => [
                $field => str($field)->headline()->toString()
                    // Flagged rather than hidden: an empty field is worth
                    // filling in English before anyone translates it.
                    .(blank($record->getAttribute($field)) ? ' — empty in English' : ''),
            ])
            ->all();
    }

    protected function baseValue(mixed $field): ?string
    {
        if (! is_string($field) || $field === '') {
            return null;
        }

        $record = $this->getOwnerRecord();

        return method_exists($record, 'isTranslatable') && $record->isTranslatable($field)
            ? $record->getAttribute($field)
            : null;
    }
}
