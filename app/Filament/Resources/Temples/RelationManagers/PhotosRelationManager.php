<?php

namespace App\Filament\Resources\Temples\RelationManagers;

use App\Enums\PhotoCategory;
use App\Models\TemplePhoto;
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
use Filament\Tables\Columns\ImageColumn;
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
                    ->maxSize(8192)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->required()
                    ->helperText('JPEG, PNG or WebP, up to 8 MB. Display sizes are generated automatically.')
                    ->columnSpanFull(),

                Select::make('category')
                    ->options(PhotoCategory::class)
                    ->default(PhotoCategory::Gallery)
                    ->required()
                    ->native(false)
                    ->live()
                    ->helperText(fn (Get $get): string => PhotoCategory::tryFrom((string) $get('category'))?->needsPermissionCheck()
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
                ImageColumn::make('thumbnail')
                    ->label('Preview')
                    ->state(fn (TemplePhoto $record): ?string => $record->thumbnailUrl())
                    ->height(56),

                TextColumn::make('caption')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(60),

                TextColumn::make('category')
                    ->badge(),

                TextColumn::make('credit')
                    ->placeholder('Own photo')
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
