<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A devotee asking to be told about a temple.
 *
 * Distinct from saving (a bookmark for oneself) and from planning a trip.
 * What they are told is per temple and per kind, so a devotee who follows
 * forty temples and wants festival reminders from three can have exactly
 * that; nothing is sent on the strength of a follow alone.
 */
class TempleFollow extends Model
{
    protected $fillable = ['devotee_id', 'temple_id', 'notify_festivals', 'notify_events'];

    protected $attributes = [
        'notify_festivals' => true,
        'notify_events' => true,
    ];

    protected function casts(): array
    {
        return [
            'notify_festivals' => 'boolean',
            'notify_events' => 'boolean',
        ];
    }

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class);
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    /** Followers who asked for reminders of this kind of event. */
    public function scopeWantingReminders(Builder $query, \App\Enums\EventType $type): Builder
    {
        return $query->where($type === \App\Enums\EventType::Festival ? 'notify_festivals' : 'notify_events', true);
    }
}
