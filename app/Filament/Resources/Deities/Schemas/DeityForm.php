<?php

namespace App\Filament\Resources\Deities\Schemas;

use App\Filament\Schemas\MantraFields;
use App\Models\Deity;
use App\Support\UploadRules;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class DeityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The deity')
                    ->description('How this deity is named and recognised across regions.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug((string) $state))),

                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Used in URLs. Changing it breaks any link already shared.'),

                        Textarea::make('alternate_names')
                            ->label('Alternate and regional names')
                            ->rows(2)
                            ->helperText('Comma separated, e.g. Murugan, Kartikeya, Subrahmanya, Skanda. These are searched, so a devotee typing the name they know will find the right temples.')
                            ->columnSpanFull(),

                        Textarea::make('description')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Section::make('Image')
                    ->description('Shown on the deity page and behind the day this deity belongs to.')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('image_path')
                            ->label('Deity image')
                            ->image()
                            ->imageEditor()
                            ->disk(fn (): string => config('filesystems.media'))
                            ->directory('deities')
                            ->visibility('public')
                            ->maxSize(UploadRules::maxKbFor('deity_image'))
                            ->acceptedFileTypes(UploadRules::typesFor('deity_image'))
                            // The disk is recorded on the row so the image
                            // keeps resolving after a move to Spaces.
                            ->afterStateUpdated(fn (Set $set) => $set('image_disk', config('filesystems.media')))
                            ->helperText('A portrait or murti image. Landscape crops badly on the day screen, so prefer square or taller. '.UploadRules::summary('deity_image'))
                            ->columnSpanFull(),

                        TextInput::make('image_credit')
                            ->label('Credit')
                            ->maxLength(255)
                            // The same rule as temple photos: attribution is
                            // not optional for work we did not make.
                            ->helperText('Who the image is by, and under what terms. Required for anything we did not create ourselves.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Mantra')
                    ->description('The mantra belongs here rather than on each weekday — a day carries its own only where a tradition differs, and otherwise falls back to this one. A temple with no mantra of its own falls back here too.')
                    ->columns(1)
                    ->schema(MantraFields::components(Deity::class)),

                Section::make('Appearance and order')
                    ->columns(2)
                    ->schema([
                        ColorPicker::make('accent_color')
                            ->label('Accent colour')
                            ->helperText('Tints the app and the admin on this deity\'s day. Left blank, the brand saffron is used.'),

                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Lower numbers appear first in the app.'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('An inactive deity stays on its temples but disappears from pickers and the app.')
                            ->columnSpanFull(),
                    ]),
            ])
            ->columns(1);
    }
}
