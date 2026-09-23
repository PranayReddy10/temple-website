<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a ticket stands.
 *
 * "Waiting on reporter" is the one that earns its place: without it, a ticket
 * that has been answered and is waiting for a reply looks identical to one
 * nobody has touched, and the queue stops being a queue.
 */
enum TicketStatus: string implements HasColor, HasIcon, HasLabel
{
    case New = 'new';
    case Open = 'open';
    case WaitingOnReporter = 'waiting_on_reporter';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Open => 'Being looked at',
            self::WaitingOnReporter => 'Waiting for a reply',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'danger',
            self::Open => 'warning',
            self::WaitingOnReporter => 'info',
            self::Resolved => 'success',
            self::Closed => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::New => 'heroicon-m-inbox-arrow-down',
            self::Open => 'heroicon-m-wrench',
            self::WaitingOnReporter => 'heroicon-m-clock',
            self::Resolved => 'heroicon-m-check-badge',
            self::Closed => 'heroicon-m-archive-box',
        };
    }

    /**
     * Whether this ticket is still somebody's job.
     *
     * The single definition of "open", so the badge, the queue filter and any
     * future report all mean the same thing by it. Waiting on a reply still
     * counts: the person is waiting, and a ticket parked there forever is a
     * ticket that was dropped politely.
     */
    public function needsAttention(): bool
    {
        return in_array($this, [self::New, self::Open, self::WaitingOnReporter], true);
    }

    /** @return array<int, string> */
    public static function openValues(): array
    {
        return array_map(
            fn (self $case): string => $case->value,
            array_filter(self::cases(), fn (self $case): bool => $case->needsAttention()),
        );
    }
}
