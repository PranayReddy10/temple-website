<?php

namespace App\Filament\Schemas;

use App\Enums\DevotionalMediaType;
use App\Models\DevotionalMedia;
use App\Support\MediaSource;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The mantra fields, shared by the deity, temple and weekday forms.
 *
 * The recording is the part worth explaining. It is a devotional_media row,
 * not an audio column, so it carries its artist, credit, licence and
 * duration like everything else we play — but picking one from a dropdown
 * would mean creating the media first, on another screen, and coming back.
 * Nobody does that; they give up and leave the mantra silent.
 *
 * So the field creates one inline: paste a YouTube link or upload an MP3,
 * and the recording is made and attached in the same act. The same pattern
 * as creating a temple account from the grant form.
 */
class MantraFields
{
    /**
     * @param  class-string  $ownerClass  what the recording will belong to
     * @return array<int, mixed>
     */
    public static function components(string $ownerClass, bool $withMeaning = true): array
    {
        return array_values(array_filter([
            Textarea::make('mantra')
                ->label('Mantra (in script)')
                ->rows(2)
                ->helperText('In Devanagari or the appropriate script, e.g. ॐ नमः शिवाय.'),

            Textarea::make('mantra_transliteration')
                ->label('Transliteration')
                ->rows(2)
                ->helperText('Roman script, so a devotee who does not read the original can still chant it: Om Namah Shivaya.'),

            $withMeaning
                ? Textarea::make('mantra_meaning')
                    ->label('Meaning')
                    ->rows(2)
                    ->helperText('A short plain-language sense of it. Optional.')
                : null,

            self::recordingField($ownerClass),
        ]));
    }

    public static function recordingField(string $ownerClass): Select
    {
        return Select::make('mantra_media_id')
            ->label('Recording')
            ->relationship(
                'mantraRecordingMedia',
                'title',
                // Only this record's own media, and only what can be played.
                // Offering every recording in the database would let a
                // temple's mantra point at another temple's chant.
                fn ($query, $livewire) => $query
                    ->where('mediable_type', $ownerClass)
                    ->where('mediable_id', $livewire->getRecord()?->getKey() ?? 0)
                    ->whereIn('type', [
                        DevotionalMediaType::Chant->value,
                        DevotionalMediaType::Song->value,
                    ]),
            )
            ->searchable()
            ->preload()
            ->native(false)
            ->helperText('Plays under the mantra in the app. Nothing here yet? Use the + to add one — paste a YouTube link or upload an MP3.')
            ->createOptionForm([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. Om Namah Shivaya — 108 times'),

                Select::make('source_type')
                    ->label('Where it comes from')
                    ->options([
                        'external' => 'A link — YouTube, or a direct audio file',
                        'upload' => 'Upload an MP3 we host',
                    ])
                    ->default('external')
                    ->required()
                    ->native(false)
                    ->live(),

                TextInput::make('external_url')
                    ->label('Link')
                    ->url()
                    ->maxLength(500)
                    ->placeholder('https://www.youtube.com/watch?v=… or https://…/chant.mp3')
                    ->required(fn (Get $get): bool => $get('source_type') === 'external')
                    ->visible(fn (Get $get): bool => $get('source_type') === 'external')
                    // Says what the app will do with it before it is saved,
                    // so a link that cannot be played is noticed now rather
                    // than by a devotee later.
                    ->live(onBlur: true)
                    ->helperText(fn (?string $state): string => self::linkHint($state)),

                FileUpload::make('path')
                    ->label('Audio file')
                    ->disk(fn (): string => config('filesystems.media'))
                    ->directory('devotional/mantras')
                    ->visibility('public')
                    ->maxSize(51200)
                    ->acceptedFileTypes(['audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/wav'])
                    ->required(fn (Get $get): bool => $get('source_type') === 'upload')
                    ->visible(fn (Get $get): bool => $get('source_type') === 'upload')
                    ->helperText('MP3, M4A, AAC, OGG or WAV. Up to 50 MB.'),

                TextInput::make('artist')
                    ->label('Artist / performer')
                    ->maxLength(255),

                TextInput::make('license')
                    ->label('Licence')
                    ->maxLength(255)
                    ->placeholder('e.g. CC BY-SA 4.0, or licensed from the label')
                    // A chant text is not a recording; a sung rendition is.
                    // The observer enforces the same rule regardless.
                    ->helperText('A song needs one before it can be published. A plain spoken chant does not, though credit is still expected.'),
            ])
            ->createOptionUsing(function (array $data, $livewire) use ($ownerClass): ?int {
                $owner = $livewire->getRecord();

                if ($owner === null) {
                    return null;
                }

                $media = $owner->media()->create([
                    // A mantra recording is a chant, which is what makes it
                    // publishable without a licence where one is spoken.
                    'type' => DevotionalMediaType::Chant,
                    'title' => $data['title'],
                    'source_type' => $data['source_type'],
                    'external_url' => $data['external_url'] ?? null,
                    'path' => $data['path'] ?? null,
                    'artist' => $data['artist'] ?? null,
                    'license' => $data['license'] ?? null,
                    'is_published' => true,
                ]);

                return $media->getKey();
            })
            ->createOptionModalHeading('Add a recording of this mantra')
            ->createOptionAction(fn ($action) => $action->modalSubmitActionLabel('Add recording'))
            // Nothing to attach a recording to until the record exists.
            ->disabled(fn ($livewire): bool => $livewire->getRecord() === null)
            ->hintIcon(
                fn ($livewire): ?string => $livewire->getRecord() === null ? 'heroicon-m-information-circle' : null,
                tooltip: 'Save this record first, then add its recording.',
            );
    }

    /** What the app will do with the link, said before it is saved. */
    protected static function linkHint(?string $url): string
    {
        if (blank($url)) {
            return 'A YouTube link is embedded; a direct .mp3 link plays in the app.';
        }

        return match (MediaSource::kind($url)) {
            MediaSource::YOUTUBE => MediaSource::youTubeId($url) === null
                ? 'A YouTube link, but no video in it — a channel or search page cannot be played.'
                : 'YouTube — the app will embed this and play it in place.',
            MediaSource::VIMEO => 'Vimeo — the app will embed this.',
            MediaSource::AUDIO => 'A direct audio file — the app will play this in its own player.',
            MediaSource::VIDEO => 'A direct video file — the app will play this in its own player.',
            default => 'Not something the app can play. It will be offered as a link to open instead.',
        };
    }
}
