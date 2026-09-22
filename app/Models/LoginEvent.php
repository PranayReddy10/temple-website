<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One sign-in attempt.
 *
 * Append-only by intent: an event is a fact about a moment, so there is no
 * updated_at and nothing here is edited. The analytics screen reads it; the
 * app writes it through LoginRecorder and nothing else should.
 */
class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'authenticatable_type', 'authenticatable_id', 'guard',
        'identifier', 'succeeded', 'failure_reason',
        'ip_address', 'user_agent', 'platform', 'app_version', 'occurred_at',
    ];

    protected $attributes = [
        'succeeded' => true,
    ];

    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('succeeded', true);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('succeeded', false);
    }

    public function scopeForGuard(Builder $query, string $guard): Builder
    {
        return $query->where('guard', $guard);
    }

    /** Events from the last $days days, inclusive of today. */
    public function scopeSince(Builder $query, int $days): Builder
    {
        return $query->where('occurred_at', '>=', now()->subDays($days - 1)->startOfDay());
    }

    /**
     * Distinct accounts that signed in successfully over a window.
     *
     * "Active users" means people, not sign-ins: a devotee who opens the app
     * eight times in a day is one active user, and a metric that says eight
     * is a metric that will be quoted to someone.
     */
    public static function activeAccountCount(string $guard, int $days): int
    {
        return static::query()
            ->forGuard($guard)
            ->succeeded()
            ->since($days)
            ->whereNotNull('authenticatable_id')
            ->distinct()
            ->count('authenticatable_id');
    }
}
