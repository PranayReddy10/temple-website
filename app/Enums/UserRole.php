<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel
{
    case SuperAdmin = 'super_admin';
    case Editor = 'editor';
    case TempleAdmin = 'temple_admin';

    public function getLabel(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Editor => 'Editor',
            self::TempleAdmin => 'Temple Admin',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Full access, including user management and publishing.',
            self::Editor => 'Can create and edit temple records, but cannot publish or manage users.',
            self::TempleAdmin => 'Represents a temple. Can manage only the temples they have been approved for, through the temple portal.',
        };
    }

    /**
     * Only a super admin may move a temple into or out of the published state.
     * Editors submit for review instead.
     */
    public function canPublish(): bool
    {
        return $this === self::SuperAdmin;
    }

    public function canManageUsers(): bool
    {
        return $this === self::SuperAdmin;
    }

    /** The one role that represents a temple rather than the product team. */
    public function isTempleAdmin(): bool
    {
        return $this === self::TempleAdmin;
    }

    /** Staff roles work in the editorial admin panel. */
    public function isStaff(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Editor], true);
    }

    /**
     * Which panel this role signs into. A role belongs to exactly one, so
     * a temple admin can never reach the editorial panel and vice versa.
     */
    public function panelId(): string
    {
        return $this === self::TempleAdmin ? 'temple' : 'admin';
    }
}
