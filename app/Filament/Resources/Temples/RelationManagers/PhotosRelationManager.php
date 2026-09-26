<?php

namespace App\Filament\Resources\Temples\RelationManagers;

use App\Enums\PhotoCategory;
use App\Filament\Support\MediaColumn;
use App\Models\TemplePhoto;
use App\Support\FormState;
use App\Support\UploadRules;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';

    protected static ?string $title = 'Photos';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-photo';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('path')
                    ->label('Image')
                    ->image()
                    ->disk(fn (): string => config('filesystems.media'))
                    ->directory(fn (): string => 'temples/'.$this->getOwnerRecord()->getKey())
                    ->visibility('public')
                    ->maxSize(UploadRules::maxKbFor('temple_photo'))
                    ->acceptedFileTypes(UploadRules::typesFor('temple_photo'))
                    ->required()
                    ->helperText(UploadRules::summary('temple_photo').' Display sizes are generated automatically.')
                    ->columnSpanFull(),

                Select::make('category')
                    ->options(PhotoCategory::class)
                    ->default(PhotoCategory::Gallery)
                    ->required()
                    ->native(false)
                    ->live()
                    ->helperText(fn (Get $get): string => FormState::enum(PhotoCategory::class, $get('category'))?->needsPermissionCheck()
                        ? 'Many temples prohibit photography in the sanctum. Confirm this photo was permitted before publishing.'
                        : ''),

                TextInput::make('caption')
                    ->maxLength(255),

                TextInput::make('credit')
                    ->label('Photographer / credit')
                    ->maxLength(255)
                    ->helperText('Required unless the photo is our own.'),

                TextInput::make('source_url')
                    ->label('Source URL')
                    ->url()
                    ->maxLength(255),

                TextInput::make('license')
                    ->maxLength(255)
                    ->placeholder('e.g. CC BY-SA 4.0, used with permission'),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0),

                Toggle::make('is_primary')
                    ->label('Lead image')
                    ->helperText('Shown first. Setting this unsets any other lead image.'),

                Toggle::make('is_published')
                    ->label('Published')
                    ->default(true),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('caption')
            ->columns([
                // Smallest available: a list of full-size temple photographs
                // is megabytes of download to render 56px thumbnails.
                MediaColumn::make(
                    'thumbnail',
                    fn (TemplePhoto $record): ?string => $record->thumbnail_path
                        ?? $record->medium_path
                        ?? $record->path,
                )->label('Preview')->height(56),

                TextColumn::make('caption')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(60),

                TextColumn::make('category')
                    ->badge(),

                TextColumn::make('credit')
                    ->placeholder('Own photo')
                    ->description(fn (TemplePhoto $record): ?string => $record->isDevoteePhoto()
                        ? ($record->templeObjected() ? 'Devotee photo · objected: '.$record->temple_objection : 'Devotee photo, from the app')
                        : null)
                    ->toggleable(),

                TextColumn::make('dimensions')
                    ->label('Size')
                    ->state(fn (TemplePhoto $record): string => $record->width && $record->height
                        ? "{$record->width} × {$record->height}"
                        : '—')
                    ->toggleable(),

                IconColumn::make('is_primary')
                    ->label('Lead')
                    ->boolean(),

                IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('category')->options(PhotoCategory::class),
            ])
            ->headerActions([
                CreateAction::make()->label('Upload photo'),
            ])
            ->recordActions([
                EditAction::make(),
                // A devotee's photo the temple does not want shown. Only for
                // those: the temple's own uploads it simply edits or deletes.
                \Filament\Actions\Action::make('object')
                    ->label('Object')
                    ->icon('heroicon-o-hand-raised')
                    ->color('warning')
                    ->visible(fn (TemplePhoto $record): bool => $record->isDevoteePhoto() && ! $record->templeObjected())
                    ->schema([
                        \Filament\Forms\Components\Textarea::make('reason')
                            ->label('Why it should not be shown')
                            ->rows(2)
                            ->maxLength(255)
                            ->required()
                            ->helperText('Seen by the editorial team; the photo comes down at once.'),
                    ])
                    ->action(function (TemplePhoto $record, array $data): void {
                        \App\Support\PhotoPromotion::object($record, $data['reason']);
                        \Filament\Notifications\Notification::make()->title('Taken down. The editors have been told why.')->success()->send();
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->emptyStateHeading('No photos yet')
            ->emptyStateDescription('Upload exterior, architecture and festival photos for this temple.');
    }
}
