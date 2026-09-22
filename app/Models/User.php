<?php

namespace App\Models;

use App\Enums\UserRole;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    /**
     * Gate for each panel.
     *
     * A role belongs to exactly one panel: staff cannot reach the temple
     * portal and a temple admin cannot reach the editorial panel. Checking the
     * panel id here, rather than only hiding navigation, is what makes that a
     * boundary instead of a suggestion.
     *
     * The is_active cast is deliberate: a row written by an import that
     * omitted the column would otherwise throw a TypeError, turning "no
     * access" into a 500.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! (bool) $this->is_active) {
            return false;
        }

        return $this->role?->panelId() === $panel->getId();
    }

    /**
     * Temples this user may administer.
     *
     * Approved links only. An unapproved claim must grant nothing, so the
     * condition lives on the relationship rather than being applied by each
     * caller — a caller that forgets is a caller that leaks another temple.
     */
    public function temples(): BelongsToMany
    {
        return $this->belongsToMany(Temple::class)
            ->using(TempleUser::class)
            ->withPivot(['role', 'approved_at', 'requested_at'])
            ->wherePivotNotNull('approved_at')
            ->withTimestamps();
    }

    /** Every claim regardless of state, for the "my claims" screen. */
    public function templeClaims(): HasMany
    {
        return $this->hasMany(TempleUser::class);
    }

    /**
     * IDs of the temples this user may touch.
     *
     * The single source of truth for scoping the temple portal. Anything that
     * queries temples there must go through this.
     *
     * @return array<int, int>
     */
    public function approvedTempleIds(): array
    {
        if (! $this->isTempleAdmin()) {
            return [];
        }

        return $this->temples()->pluck('temples.id')->all();
    }

    public function administersTemple(Temple|int $temple): bool
    {
        $id = $temple instanceof Temple ? $temple->getKey() : $temple;

        return in_array((int) $id, $this->approvedTempleIds(), true);
    }

    public function isTempleAdmin(): bool
    {
        return $this->role === UserRole::TempleAdmin;
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
