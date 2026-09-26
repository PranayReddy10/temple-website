<?php

namespace App\Support;

/**
 * What may be uploaded where, defined once.
 *
 * These limits were written into each upload field separately, which meant
 * the only way to answer "what can I upload, and how big" was to open five
 * forms and read them — and the only way to change one was to remember all
 * the places it appears. The Storage screen reads this, and so do the fields.
 *
 * Sizes are in kilobytes, because that is what Filament's maxSize() takes.
 */
final class UploadRules
{
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const AUDIO_TYPES = ['audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/wav'];

    public const VIDEO_TYPES = ['video/mp4', 'video/webm'];

    /**
     * Every kind of upload the admin offers, with what it accepts.
     *
     * @return array<string, array{label: string, where: string, types: array<int, string>, max_kb: int, note: string}>
     */
    public static function all(): array
    {
        return [
            'temple_photo' => [
                'label' => 'Temple photos',
                'where' => 'Temples → a temple → Photos, and the cover image on its form',
                'types' => self::IMAGE_TYPES,
                'max_kb' => 12288,
                'note' => 'Resized on upload to a 1200px medium and a 400px thumbnail, so the original can be large.',
            ],
            'deity_image' => [
                'label' => 'Deity images',
                'where' => 'Master Data → Deities',
                'types' => self::IMAGE_TYPES,
                'max_kb' => 8192,
                'note' => 'Square or taller. Landscape crops badly on the day screen.',
            ],
            'puja_image' => [
                'label' => 'Puja and seva images',
                'where' => 'Temples → a temple → Puja & Seva',
                'types' => self::IMAGE_TYPES,
                'max_kb' => 4096,
                'note' => 'Shown small beside the seva, so a large file buys nothing.',
            ],
            'event_image' => [
                'label' => 'Event images',
                'where' => 'Temples → Events & Programs',
                'types' => self::IMAGE_TYPES,
                'max_kb' => 8192,
                'note' => '',
            ],
            'devotional_media' => [
                'label' => 'Songs, chants and videos',
                'where' => 'A deity, a temple or a weekday → Songs, photos & videos',
                'types' => [...self::AUDIO_TYPES, ...self::IMAGE_TYPES, ...self::VIDEO_TYPES],
                'max_kb' => 51200,
                'note' => 'Linking to where a recording is officially published is usually better than hosting a copy: the rights stay where they already are, and it costs no storage.',
            ],
            'mantra_recording' => [
                'label' => 'Mantra recordings',
                'where' => 'The Mantra section of a deity, temple or weekday',
                'types' => self::AUDIO_TYPES,
                'max_kb' => 51200,
                'note' => 'Or paste a YouTube link instead of uploading anything.',
            ],
            'devotee_avatar' => [
                'label' => 'Devotee profile photos',
                'where' => 'Uploaded from the app; shown under Devotees',
                'types' => self::IMAGE_TYPES,
                'max_kb' => 4096,
                'note' => 'Shown as a small circle. Without one the app and the admin both draw the devotee\'s initials instead.',
            ],
            'visit_photo' => [
                'label' => 'Devotee photos (Photo Stamp)',
                'where' => 'Uploaded from the app, reviewed under Devotees → Photo Stamps',
                'types' => self::IMAGE_TYPES,
                'max_kb' => 12288,
                'note' => 'The devotee\'s original and the generated card are stored separately.',
            ],
            'seva_photo' => [
                'label' => 'Seva drive photos',
                'where' => 'Uploaded from the app, reviewed under Community → Seva Drives',
                'types' => self::IMAGE_TYPES,
                'max_kb' => 12288,
                'note' => 'Before and after photographs of the place, up to '.\App\Models\SevaDriveMedia::MAX_PER_STAGE.' of each.',
            ],
            'seva_video' => [
                'label' => 'Seva drive videos',
                'where' => 'Uploaded from the app, reviewed under Community → Seva Drives',
                'types' => [...self::VIDEO_TYPES, 'video/quicktime'],
                'max_kb' => 51200,
                'note' => 'The server\'s own PHP upload limit must be at least this large. A YouTube or Instagram link costs no storage and is offered alongside.',
            ],
        ];
    }

    /** @return array<int, string> */
    public static function typesFor(string $key): array
    {
        return self::all()[$key]['types'] ?? self::IMAGE_TYPES;
    }

    /**
     * "jpeg,png,webp" — the tail of Laravel's mimes: validation rule.
     *
     * The API validates uploads too, and it was doing so against its own
     * hand-written list. Reading it from here means an API upload and the
     * admin form agree about what a photo is.
     */
    public static function mimesRuleFor(string $key): string
    {
        return collect(self::typesFor($key))
            ->map(fn (string $type): string => match ($type) {
                'image/jpeg' => 'jpeg,jpg',
                'audio/mpeg' => 'mp3',
                'audio/mp4' => 'm4a',
                'video/quicktime' => 'mov',
                default => str($type)->afterLast('/')->toString(),
            })
            ->flatMap(fn (string $extensions): array => explode(',', $extensions))
            ->unique()
            ->implode(',');
    }

    public static function maxKbFor(string $key): int
    {
        return self::all()[$key]['max_kb'] ?? 8192;
    }

    /** "JPEG, PNG, WebP" rather than a list of MIME types. */
    public static function readableTypes(array $types): string
    {
        return collect($types)
            ->map(fn (string $type): string => match ($type) {
                'image/jpeg' => 'JPEG',
                'image/png' => 'PNG',
                'image/webp' => 'WebP',
                'audio/mpeg' => 'MP3',
                'audio/mp4' => 'M4A',
                'audio/aac' => 'AAC',
                'audio/ogg' => 'OGG',
                'audio/wav' => 'WAV',
                'video/mp4' => 'MP4',
                'video/webm' => 'WebM',
                'video/quicktime' => 'MOV',
                default => strtoupper(str($type)->afterLast('/')->toString()),
            })
            ->unique()
            ->implode(', ');
    }

    /**
     * "JPEG, PNG or WebP, up to 12 MB." — for a field's helper text.
     *
     * Written from the same numbers the field enforces, so the sentence under
     * the box can never promise something the box then rejects.
     */
    public static function summary(string $key): string
    {
        $types = collect(explode(', ', self::readableTypes(self::typesFor($key))));

        $list = $types->count() > 1
            ? $types->slice(0, -1)->implode(', ').' or '.$types->last()
            : $types->implode('');

        return $list.', up to '.self::readableSize(self::maxKbFor($key)).'.';
    }

    public static function readableSize(int $kilobytes): string
    {
        return $kilobytes >= 1024
            ? round($kilobytes / 1024).' MB'
            : $kilobytes.' KB';
    }
}
