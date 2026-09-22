<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum EventStatus: string implements HasColor, HasIcon, HasLabel
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Waiting for review',
            self::Published => 'Published',
            self::Rejected => 'Rejected',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingReview => 'warning',
            self::Published => 'success',
            self::Rejected => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-m-pencil-square',
            self::PendingReview => 'heroicon-m-clock',
            self::Published => 'heroicon-m-check-badge',
            self::Rejected => 'heroicon-m-x-circle',
        };
    }

    /** Only published events are ever exposed through the public API. */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }
}
