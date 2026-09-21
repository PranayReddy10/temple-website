<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Photo groupings from section 4 of the project plan. */
enum PhotoCategory: string implements HasLabel
{
    case Exterior = 'exterior';
    case Architecture = 'architecture';
    case Deity = 'deity';
    case Festival = 'festival';
    case Surroundings = 'surroundings';
    case Gallery = 'gallery';

    public function getLabel(): string
    {
        return match ($this) {
            self::Exterior => 'Exterior',
            self::Architecture => 'Architecture',
            self::Deity => 'Deity / sanctum',
            self::Festival => 'Festival',
            self::Surroundings => 'Surroundings',
            self::Gallery => 'General gallery',
        };
    }

    /**
     * Many temples prohibit photography inside the sanctum. Flagging these
     * categories reminds an editor to confirm the photo was permitted before
     * publishing it.
     */
    public function needsPermissionCheck(): bool
    {
        return $this === self::Deity;
    }
}
