<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum DevotionalMediaType: string implements HasColor, HasIcon, HasLabel
{
    case Photo = 'photo';
    case Song = 'song';
    case Video = 'video';
    case Chant = 'chant';

    public function getLabel(): string
    {
        return match ($this) {
            self::Photo => 'Photo',
            self::Song => 'Song / bhajan',
            self::Video => 'Video',
            self::Chant => 'Chant / mantra',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Photo => 'info',
            self::Song => 'primary',
            self::Video => 'warning',
            self::Chant => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Photo => 'heroicon-m-photo',
            self::Song => 'heroicon-m-musical-note',
            self::Video => 'heroicon-m-play-circle',
            self::Chant => 'heroicon-m-speaker-wave',
        };
    }

    /**
     * Whether publishing this type requires a recorded licence.
     *
     * A recording is owned by its performer or label even when the
     * composition is ancient, so a song or video cannot be published without
     * saying under what terms. A photo we took or a chant text is not in the
     * same position, though credit is still expected where it is due.
     */
    public function requiresLicense(): bool
    {
        return in_array($this, [self::Song, self::Video], true);
    }

    public function isTimed(): bool
    {
        return in_array($this, [self::Song, self::Video, self::Chant], true);
    }
}
