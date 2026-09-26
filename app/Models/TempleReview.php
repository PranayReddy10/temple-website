<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A devotee's account of a visit.
 *
 * A place of worship is not a restaurant, so nothing here rates the temple.
 * What is rated is the visit: how long the queue was, how clean and how
 * accessible the place was, what the facilities were like, and how far our
 * own listing turned out to be right. Those say something a devotee planning
 * a trip can use, without ranking the sacred. There is no overall score.
 */
class TempleReview extends Model
{
    /** The dimensions, with the question each answers. */
    public const DIMENSIONS = [
        'queue_rating' => ['label' => 'Queue and waiting', 'low' => 'Very long', 'high' => 'No wait'],
        'cleanliness_rating' => ['label' => 'Cleanliness', 'low' => 'Poor', 'high' => 'Spotless'],
        'facilities_rating' => ['label' => 'Facilities', 'low' => 'Few', 'high' => 'Everything needed'],
        'accessibility_rating' => ['label' => 'Accessibility', 'low' => 'Hard', 'high' => 'Easy for everyone'],
        'accuracy_rating' => ['label' => 'Our listing was accurate', 'low' => 'Mostly wrong', 'high' => 'Spot on'],
    ];

    protected $fillable = [
        'devotee_id', 'temple_id', 'devotee_visit_id', 'visited_on',
        'queue_rating', 'cleanliness_rating', 'facilities_rating', 'accessibility_rating', 'accuracy_rating',
        'wait_minutes', 'body',
        'status', 'moderated_by', 'moderated_at', 'moderation_note',
        'temple_reply', 'temple_replied_at', 'temple_replied_by',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'visited_on' => 'date',
            'queue_rating' => 'integer',
            'cleanliness_rating' => 'integer',
            'facilities_rating' => 'integer',
            'accessibility_rating' => 'integer',
            'accuracy_rating' => 'integer',
            'wait_minutes' => 'integer',
            'moderated_at' => 'datetime',
            'temple_replied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The author hears the decision, in the inbox (and as a push when set
        // up): silence after writing reads as the account having vanished.
        static::updated(function (TempleReview $review): void {
            if (! $review->wasChanged('status') || $review->status === ReviewStatus::Pending) {
                return;
            }

            $temple = $review->temple;
            $published = $review->status === ReviewStatus::Approved;

            $notification = AppNotification::create([
                'title' => $published ? 'Your review is published' : 'Your review was not published',
                'body' => $published
                    ? 'What you wrote about visiting '.($temple?->name ?? 'the temple').' is now on its page.'
                    : 'Your review of '.($temple?->name ?? 'the temple').' was not published'.(filled($review->moderation_note) ? ': '.$review->moderation_note : '.').' You can edit it and send it again.',
                'link_type' => $temple === null ? 'none' : 'temple',
                'link_value' => $temple?->slug,
                'audience' => 'devotee',
                'audience_id' => $review->devotee_id,
                'status' => 'scheduled',
                'scheduled_at' => now(),
            ]);

            app(\App\Support\Push\NotificationSender::class)->send($notification);
        });
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

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function replier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'temple_replied_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Approved);
    }

    public function scopeAwaitingModeration(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Pending);
    }

    public function isApproved(): bool
    {
        return $this->status === ReviewStatus::Approved;
    }

    /** @return array<string, int> the dimensions this review scored */
    public function ratings(): array
    {
        $out = [];
        foreach (array_keys(self::DIMENSIONS) as $key) {
            if ($this->{$key} !== null) {
                $out[$key] = (int) $this->{$key};
            }
        }

        return $out;
    }

    /**
     * The averages across a temple's published accounts, one per dimension,
     * with how many said so. No overall figure is computed, on purpose.
     *
     * @return array{count: int, dimensions: array<string, array{label: string, average: ?float, count: int}>, average_wait_minutes: ?int}
     */
    public static function summaryFor(Temple|int $temple): array
    {
        $id = $temple instanceof Temple ? $temple->getKey() : $temple;
        $selects = ['count(*) as total', 'avg(wait_minutes) as avg_wait'];
        foreach (array_keys(self::DIMENSIONS) as $key) {
            $selects[] = "avg({$key}) as avg_{$key}";
            $selects[] = "count({$key}) as n_{$key}";
        }
        $row = static::query()->approved()->where('temple_id', $id)->selectRaw(implode(', ', $selects))->first();

        $dimensions = [];
        foreach (self::DIMENSIONS as $key => $meta) {
            $n = (int) ($row->{'n_'.$key} ?? 0);
            $dimensions[$key] = [
                'label' => $meta['label'],
                'average' => $n === 0 ? null : round((float) $row->{'avg_'.$key}, 1),
                'count' => $n,
            ];
        }

        return [
            'count' => (int) ($row->total ?? 0),
            'dimensions' => $dimensions,
            'average_wait_minutes' => $row->avg_wait === null ? null : (int) round((float) $row->avg_wait),
        ];
    }
}
