<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Where a temple somebody added from the app stands with the editors. */
enum TempleSuggestionStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Duplicate = 'duplicate';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Being reviewed',
            self::Approved => 'Added',
            self::Duplicate => 'Already listed',
            self::Rejected => 'Not added',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Duplicate => 'info',
            self::Rejected => 'danger',
        };
    }
}
