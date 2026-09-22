<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\VerificationStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TempleEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'temple_id', 'type', 'title', 'description',
        'image_disk', 'image_path',
        'starts_on', 'ends_on', 'is_all_day', 'starts_at', 'ends_at',
        'recurrence', 'status', 'published_at',
        'created_by', 'reviewed_by', 'review_note',
    ];

    protected $attributes = [
        'type' => 'festival',
        'status' => 'draft',
        'recurrence' => 'none',
        'is_all_day' => true,
    ];

    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'status' => EventStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_all_day' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // --- Scopes ---

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', EventStatus::Published);
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('status', EventStatus::PendingReview);
    }

    /** Events that have not finished yet. */
    public function scopeUpcoming(Builder $query, ?CarbonInterface $from = null): Builder
    {
        $from = ($from ?? now())->toDateString();

        return $query->where(function (Builder $q) use ($from) {
            $q->whereDate('ends_on', '>=', $from)
                ->orWhere(fn (Builder $inner) => $inner
                    ->whereNull('ends_on')
                    ->whereDate('starts_on', '>=', $from));
        });
    }

    // --- Helpers ---

    public function lastDay(): CarbonInterface
    {
        return $this->ends_on ?? $this->starts_on;
    }

    public function coversDate(CarbonInterface $date): bool
    {
        // Whole-day comparison: an event dated today must read as running at
        // any hour, not only at midnight.
        return $date->copy()->startOfDay()->betweenIncluded(
            $this->starts_on->copy()->startOfDay(),
            $this->lastDay()->copy()->startOfDay(),
        );
    }

    public function dateLabel(): string
    {
        $from = $this->starts_on->format('d M Y');
        $to = $this->lastDay()->format('d M Y');

        return $from === $to ? $from : "{$from} – {$to}";
    }

    public function imageUrl(): ?string
    {
        if (blank($this->image_path)) {
            return null;
        }

        return Storage::disk($this->image_disk ?? config('filesystems.media'))
            ->url($this->image_path);
    }

    /**
     * Whether this temple may put an event in front of devotees without staff
     * review.
     *
     * A verified temple broadcasting to thousands is the point of the feature.
     * An unverified one doing the same is a spam vector, so verification level
     * decides — and the whole behaviour can be switched off from settings when
     * something goes wrong.
     */
    public function templeMaySelfPublish(): bool
    {
        if (! (bool) setting('temple_self_publish_enabled', null, false)) {
            return false;
        }

        return in_array(
            $this->temple?->verification_status,
            [VerificationStatus::Verified, VerificationStatus::Official],
            true,
        );
    }
}
