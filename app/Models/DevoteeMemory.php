<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a devotee wrote about a visit.
 *
 * Private unless they say otherwise. Staff can see that a memory exists and
 * when it was written — the admin needs to know the feature is used and to
 * act on a report — but the text of a private one is not theirs to read, and
 * the admin screens honour that rather than relying on nobody looking.
 */
class DevoteeMemory extends Model
{
    use HasFactory;

    protected $fillable = [
        'devotee_id', 'temple_id', 'devotee_visit_id',
        'title', 'body', 'happened_on', 'is_private',
    ];

    protected $attributes = [
        'is_private' => true,
    ];

    protected function casts(): array
    {
        return [
            'happened_on' => 'date',
            'is_private' => 'boolean',
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

    public function visit(): BelongsTo
    {
        return $this->belongsTo(DevoteeVisit::class, 'devotee_visit_id');
    }

    public function scopeShared(Builder $query): Builder
    {
        return $query->where('is_private', false);
    }

    /** What the date is about, falling back to when it was written. */
    public function occurredOn(): ?\Carbon\CarbonInterface
    {
        return $this->happened_on ?? $this->created_at;
    }

    /**
     * A one-line preview for a list, or a placeholder for a private one.
     *
     * Deliberately not the opening words of a private memory: a truncated
     * prayer is still the prayer.
     */
    public function preview(int $length = 80): string
    {
        if ($this->is_private) {
            return 'Private — not shown';
        }

        return str($this->title ?: $this->body)->squish()->limit($length)->toString();
    }
}
