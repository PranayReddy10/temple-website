<?php

namespace App\Filament\RelationManagers;

use App\Models\Translation;
use App\Support\Locales;
use App\Support\Translation\AutoTranslateFailed;
use App\Support\Translation\AutoTranslator;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                ViewField::make('english')
                    ->view('filament.forms.reference-text')
                    // Read from the chosen field each time the form redraws,
                    // so it follows the Field picker (and loads when editing).
                    ->viewData(fn (Get $get): array => [
                        'text' => $this->baseValue($get('field')),
                        'picked' => filled($get('field')),
                    ])
                    ->dehydrated(false)
                    ->columnSpanFull(),

                Textarea::make('value')
                    ->label('Translation')
                    ->rows(4)
                    ->required()
                    ->columnSpanFull()
                    // A machine's draft from the English, to read and correct;
                    // it un-ticks Reviewed so it cannot be served unread.
                    ->hintAction(
                        Action::make('autoTranslate')
                            ->label('Auto-translate')
                            ->icon('heroicon-o-sparkles')
                            ->visible(fn (): bool => AutoTranslator::enabled())
                            ->action(function (Get $get, Set $set): void {
                                $english = $this->baseValue($get('field'));
                                $locale = $get('locale');

                                if (blank($english) || ! is_string($locale)) {
                                    Notification::make()->title('Pick a language and a field that has English text first.')->warning()->send();

                                    return;
                                }

                                try {
                                    $set('value', AutoTranslator::translate($english, $locale));
                                    $set('is_reviewed', false);
                                } catch (AutoTranslateFailed $e) {
                                    Notification::make()->title($e->getMessage())->danger()->send();
                                }
                            }),
                    ),

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
                Action::make('translateMissing')
                    ->label('Auto-translate missing')
                    ->icon('heroicon-o-sparkles')
                    ->color('gray')
                    ->visible(fn (): bool => AutoTranslator::enabled())
                    ->modalDescription('Fills every field that has English text but no translation yet, as drafts waiting for review. Existing translations are left alone.')
                    ->schema([
                        Select::make('locales')
                            ->label('Languages')
                            ->options(fn (): array => collect(Locales::options())->except(Locales::fallback())->all())
                            ->default(fn (): array => Locales::appTranslations())
                            ->multiple()
                            ->required(),
                    ])
                    ->action(fn (array $data) => $this->translateMissing((array) $data['locales'])),
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

    /**
     * Drafts for every filled field still missing in each language, saved
     * unreviewed. Stops at the first failure (usually the day's free quota),
     * keeping what was done.
     *
     * @param  array<int, string>  $locales
     */
    public function translateMissing(array $locales): void
    {
        $record = $this->getOwnerRecord();
        $done = 0;

        try {
            foreach ($locales as $locale) {
                if (! Locales::isSupported($locale) || $locale === Locales::fallback()) {
                    continue;
                }

                foreach ($record->translatableFields() as $field) {
                    $english = $record->getAttribute($field);
                    $exists = $record->translations()->where(['locale' => $locale, 'field' => $field])->exists();

                    if (! is_string($english) || blank($english) || $exists) {
                        continue;
                    }

                    $record->setTranslation($field, $locale, AutoTranslator::translate($english, $locale), isReviewed: false);
                    $done++;
                }
            }
        } catch (AutoTranslateFailed $e) {
            Notification::make()
                ->title($done > 0 ? "{$done} drafted, then stopped" : 'Nothing was translated')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($done > 0 ? "{$done} translations drafted" : 'Nothing was missing')
            ->body($done > 0 ? 'They wait under "Waiting for review" until someone reads them.' : null)
            ->success()
            ->send();
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
