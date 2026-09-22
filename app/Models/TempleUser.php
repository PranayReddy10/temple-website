<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Links a temple authority to the temple they represent.
 *
 * A row here is a *claim*. It grants nothing until staff approve it, because
 * anyone can assert they run a temple.
 */
class TempleUser extends Pivot
{
    protected $table = 'temple_user';

    public $incrementing = true;

    protected $fillable = [
        'temple_id', 'user_id', 'role',
        'requested_at', 'approved_at', 'approved_by',
        'claim_note', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * The three sides of a claim, declared here rather than left to the
     * callers.
     *
     * A pivot used as a model still needs its own relations: without them
     * `with('user')` and a `user.name` column throw a BadMethodCallException
     * the moment a single row exists, while an empty table renders fine
     * because Laravel skips eager loading when there is nothing to load.
     */
    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The staff member who approved the claim, for the audit trail. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereNotNull('approved_at');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('approved_at')->whereNull('rejection_reason');
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->whereNull('approved_at')->whereNotNull('rejection_reason');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isRejected(): bool
    {
        return $this->approved_at === null && filled($this->rejection_reason);
    }

    public function status(): string
    {
        return match (true) {
            $this->isApproved() => 'approved',
            $this->isRejected() => 'rejected',
            default => 'pending',
        };
    }
}
