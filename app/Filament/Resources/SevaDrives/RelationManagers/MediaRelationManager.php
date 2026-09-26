<?php

namespace App\Filament\Resources\SevaDrives\RelationManagers;

use App\Filament\Support\MediaColumn;
use App\Models\SevaDriveMedia;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use App\Support\UploadRules;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Every photograph and video on a drive, with a way to take one down. */
class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Photos & videos';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-photo';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('stage')->options(['before' => 'Before', 'after' => 'After'])->required()->default('before')->native(false),
            Select::make('type')->options(['photo' => 'Photo', 'video' => 'Video'])->required()->default('photo')->live()->native(false),
            FileUpload::make('path')
                ->label('File')
                ->disk(fn (): string => config('filesystems.media'))
                ->directory(fn (): string => 'seva-drives/'.$this->getOwnerRecord()->getKey())
                ->visibility('public')
                ->acceptedFileTypes(fn (Get $get): array => UploadRules::typesFor($get('type') === 'video' ? 'seva_video' : 'seva_photo'))
                ->maxSize(fn (Get $get): int => UploadRules::maxKbFor($get('type') === 'video' ? 'seva_video' : 'seva_photo'))
                ->requiredWithout('video_url')
                ->columnSpanFull(),
            TextInput::make('video_url')
                ->label('Or a link to the video')
                ->url()
                ->visible(fn (Get $get): bool => $get('type') === 'video')
                ->columnSpanFull(),
            TextInput::make('caption')->maxLength(255)->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                MediaColumn::make('preview', fn (SevaDriveMedia $record): ?string => $record->type === SevaDriveMedia::TYPE_PHOTO ? $record->path : null)
                    ->label('')
                    ->height(56),
                TextColumn::make('stage')->badge()->color(fn (string $state): string => $state === SevaDriveMedia::STAGE_AFTER ? 'success' : 'gray'),
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('caption')->placeholder('—')->limit(40),
                IconColumn::make('is_hidden')->label('Hidden')->boolean(),
                TextColumn::make('created_at')->label('Added')->since(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (SevaDriveMedia $record): ?string => $record->url())
                    ->openUrlInNewTab(),
                Action::make('toggle_hidden')
                    ->label(fn (SevaDriveMedia $record): string => $record->is_hidden ? 'Show' : 'Hide')
                    ->icon(fn (SevaDriveMedia $record): string => $record->is_hidden ? 'heroicon-o-eye' : 'heroicon-o-eye-slash')
                    ->color('warning')
                    ->action(function (SevaDriveMedia $record): void {
                        $record->is_hidden = ! $record->is_hidden;
                        $record->save();
                    }),
                DeleteAction::make(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add photo or video')
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'disk' => filled($data['path'] ?? null) ? config('filesystems.media') : null,
                    ]),
            ])
            ->defaultSort('stage')
            ->emptyStateHeading('Nothing uploaded');
    }
}
