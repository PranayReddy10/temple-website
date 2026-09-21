<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel
{
    case SuperAdmin = 'super_admin';
    case Editor = 'editor';

    public function getLabel(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Editor => 'Editor',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Full access, including user management and publishing.',
            self::Editor => 'Can create and edit temple records, but cannot publish or manage users.',
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
}
