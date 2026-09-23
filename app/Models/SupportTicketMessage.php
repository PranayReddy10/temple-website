<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One message on a ticket: a reply that is sent, or a note that is not.
 */
class SupportTicketMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'support_ticket_id', 'author_type', 'author_id', 'body', 'is_internal',
    ];

    /**
     * Not internal unless said so.
     *
     * The safer default is arguable both ways — a note leaked is worse than a
     * reply withheld — but a default of "internal" would mean a reply written
     * in a hurry never reaches the person waiting for it, which is the
     * failure this whole table exists to prevent. Every call site sets it.
     */
    protected $attributes = [
        'is_internal' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeVisibleToReporter(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    public function authorName(): string
    {
        return $this->author?->name ?? 'System';
    }

    /** Whether this came from the team rather than from the person who wrote in. */
    public function isFromStaff(): bool
    {
        return $this->author_type === User::class;
    }
}
