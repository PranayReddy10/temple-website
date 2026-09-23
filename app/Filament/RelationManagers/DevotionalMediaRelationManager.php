<?php

namespace App\Filament\RelationManagers;

use App\Enums\DevotionalMediaType;
use App\Models\DevotionalMedia;
use App\Support\FormState;
use App\Support\UploadRules;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Songs, chants, photos and videos — for a weekday, a deity or a temple.
 *
 * One class for all three, because the rights rule is the only thing that
 * really matters here and it is identical in every case: a recording belongs
 * to its performer or label however old the composition is. Three copies of
 * this screen would be three places for that rule to drift.
 *
 * The three owners nest rather than compete. A temple's own suprabhatam plays
 * at that temple; its deity's aarti plays wherever that deity is worshipped;
 * a weekday's media plays on that day. The models decide precedence — see
 * Temple::allMedia() and DevotionalDay::allMedia() — not this screen.
 */
class DevotionalMediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Songs, photos & videos';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-musical-note';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What it is')
                    ->columns(2)
                    ->schema([
                        Select::make('type')
                            ->options(DevotionalMediaType::class)
                            ->default(DevotionalMediaType::Song)
                            ->required()
                            ->native(false)
                            ->live(),

                        TextInput::make('title')->required()->maxLength(255),

                        Textarea::make('description')->rows(2)->columnSpanFull(),
                    ]),

                Section::make('Where it comes from')
                    ->description('Linking to an official upload is usually safer than hosting a copy: the rights stay where they already are.')
                    ->columns(2)
                    ->schema([
                        Select::make('source_type')
                            ->label('Source')
                            ->options([
                                'external' => 'Link to where it is officially published',
                                'upload' => 'Upload a file we host',
                            ])
                            ->default('external')
                            ->required()
                            ->native(false)
                            ->live(),

                        TextInput::make('external_url')
                            ->label('Link')
                            ->url()
                            ->maxLength(500)
                            ->required(fn (Get $get): bool => $get('source_type') === 'external')
                            ->visible(fn (Get $get): bool => $get('source_type') === 'external')
                            ->placeholder('https://www.youtube.com/watch?v=...'),

                        FileUpload::make('path')
                            ->label('File')
                            ->disk(fn (): string => config('filesystems.media'))
                            ->directory(fn (): string => 'devotional/'
                                .str(class_basename($this->getOwnerRecord()))->kebab()
                                .'/'.$this->getOwnerRecord()->getKey())
                            ->visibility('public')
                            ->maxSize(UploadRules::maxKbFor('devotional_media'))
                            ->acceptedFileTypes(UploadRules::typesFor('devotional_media'))
                            ->required(fn (Get $get): bool => $get('source_type') === 'upload')
                            ->visible(fn (Get $get): bool => $get('source_type') === 'upload')
                            ->helperText(UploadRules::summary('devotional_media'))
                            ->columnSpanFull(),

                        TextInput::make('duration_seconds')
                            ->label('Duration (seconds)')
                            ->numeric()
                            ->minValue(1)
                            ->visible(fn (Get $get): bool => FormState::enum(DevotionalMediaType::class, $get('type'))?->isTimed() ?? false),
                    ]),

                Section::make('Rights')
                    ->description('A devotional recording belongs to its performer or label even when the composition is centuries old. A song or video cannot be published without a licence recorded here.')
                    ->icon('heroicon-o-scale')
                    ->columns(2)
                    ->schema([
                        TextInput::make('artist')
                            ->label('Artist / performer')
                            ->maxLength(255),

                        TextInput::make('credit')
                            ->label('Credit line')
                            ->maxLength(255)
                            ->helperText('Shown to devotees alongside the media.'),

                        TextInput::make('license')
                            ->label('Licence')
                            ->maxLength(255)
                            ->placeholder('e.g. CC BY-SA 4.0, or licensed from the label')
                            ->required(fn (Get $get): bool => FormState::enum(DevotionalMediaType::class, $get('type'))?->requiresLicense() ?? false),

                        TextInput::make('license_url')
                            ->label('Licence URL')
                            ->url()
                            ->maxLength(500),
                    ]),

                Section::make('Publishing')
                    ->columns(2)
                    ->schema([
                        TextInput::make('sort_order')->numeric()->default(0),
                        Toggle::make('is_published')
                            ->label('Published')
                            ->helperText('A song or video without a licence stays unpublished however this is set.'),
                    ]),
            ])
            ->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('type')->badge(),

                TextColumn::make('title')->searchable()->weight('medium')->wrap(),

                TextColumn::make('artist')->placeholder('—')->toggleable(),

                TextColumn::make('source_type')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'external' ? 'Linked' : 'Hosted')
                    ->color(fn (string $state): string => $state === 'external' ? 'gray' : 'info'),

                TextColumn::make('duration')
                    ->label('Length')
                    ->state(fn (DevotionalMedia $record): string => $record->durationLabel() ?? '—')
                    ->toggleable(),

                TextColumn::make('license')
                    ->label('Licence')
                    ->state(fn (DevotionalMedia $record): string => $record->licenseSummary())
                    ->badge()
                    ->color(fn (DevotionalMedia $record): string => $record->mayBePublished() ? 'success' : 'danger')
                    ->wrap(),

                IconColumn::make('is_published')->label('Live')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')->options(DevotionalMediaType::class),

                Filter::make('blocked')
                    ->label('Blocked by missing licence')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('type', [DevotionalMediaType::Song->value, DevotionalMediaType::Video->value])
                        ->where(fn (Builder $q) => $q->whereNull('license')->orWhere('license', '')))
                    ->toggle(),
            ])
            ->headerActions([CreateAction::make()->label('Add media')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->emptyStateHeading('Nothing added yet')
            ->emptyStateDescription('Add the songs, chants and photos devotees see on this day.');
    }
}
