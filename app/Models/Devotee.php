<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

/**
 * An app user.
 *
 * Kept entirely separate from the staff `users` table. See the devotees
 * migration for why; the short version is that mixing them would make
 * "a devotee account acquires a staff role" a live possibility instead of an
 * impossible one.
 *
 * This model has no role, no panel access and no relationship to any admin
 * concept. It cannot reach either Filament panel, because neither knows it
 * exists.
 */
class Devotee extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'phone', 'password',
        'avatar_path', 'avatar_disk', 'locale', 'home_state_id', 'date_of_birth',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = [
        'locale' => 'en',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'date_of_birth' => 'date',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function homeState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'home_state_id');
    }

    public function savedTemples(): BelongsToMany
    {
        return $this->belongsToMany(Temple::class, 'devotee_saved_temples')
            ->withPivot('note')
            ->withTimestamps();
    }

    public function avatarUrl(): ?string
    {
        if (blank($this->avatar_path)) {
            return null;
        }

        return Storage::disk($this->avatar_disk ?? config('filesystems.media'))
            ->url($this->avatar_path);
    }

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null || $this->phone_verified_at !== null;
    }
}
