<?php

namespace App\Models;

use App\Enums\UserRole;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'is_active', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    /**
     * Defaults held on the model, not only in the database.
     *
     * A database default is not applied to the in-memory instance, so a freshly
     * created user had a null is_active. canAccessPanel() declares a bool
     * return, so that null became a TypeError and a 500 where a clean 403
     * belonged.
     */
    protected $attributes = [
        'role' => UserRole::Editor->value,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Gate for the admin panel itself. A deactivated account keeps its record
     * and audit trail but can no longer sign in.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // Cast rather than returned directly: a row written by an import or
        // raw SQL that omitted the column would otherwise throw a TypeError,
        // turning "no access" into a 500.
        return (bool) $this->is_active;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function canPublish(): bool
    {
        return $this->role?->canPublish() ?? false;
    }

    public function canManageUsers(): bool
    {
        return $this->role?->canManageUsers() ?? false;
    }
}
