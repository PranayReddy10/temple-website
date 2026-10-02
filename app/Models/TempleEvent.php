<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\VerificationStatus;
use App\Support\DevotionalClock;
use App\Support\MediaUrl;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class TempleEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'temple_id', 'type', 'title', 'description',
        'image_disk', 'image_path',
        'starts_on', 'ends_on', 'is_all_day', 'starts_at', 'ends_at',
        'recurrence', 'status', 'published_at',
        'created_by', 'reviewed_by', 'review_note',
        'group_name', 'open_to_all', 'registration_enabled', 'ticket_price_paise',
        'capacity', 'max_people_per_registration', 'songs',
    ];

    /** One-off, the same dates every year, or every week on the first date's weekday. */
    public const RECURRENCES = ['none', 'yearly', 'weekly'];

    protected $attributes = [
        'type' => 'festival',
        'status' => 'draft',
        'recurrence' => 'none',
        'is_all_day' => true,
        'open_to_all' => true,
        'registration_enabled' => false,
        'ticket_price_paise' => 0,
        'max_people_per_registration' => 10,
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
            'open_to_all' => 'boolean',
            'registration_enabled' => 'boolean',
            'ticket_price_paise' => 'integer',
            'capacity' => 'integer',
            'max_people_per_registration' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Paid tickets are money records: an event that sold them is set
        // back to draft, never deleted with its tickets.
        static::deleting(function (TempleEvent $event): void {
            if ($event->registrations()->where('amount_paise', '>', 0)->whereIn('status', ['pending_payment', 'confirmed', 'verified', 'expired', 'refunded'])->exists()) {
                throw ValidationException::withMessages(['event' => 'Tickets were sold for this event, so it cannot be deleted. Set it back to draft to hide it.']);
            }
        });
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

    /** Devotees who said they will come, or bought tickets. */
    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
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
                    ->whereDate('starts_on', '>=', $from))
                // A weekly gathering with no last date goes on.
                ->orWhere(fn (Builder $inner) => $inner
                    ->where('recurrence', 'weekly')
                    ->whereNull('ends_on'));
        });
    }

    // --- Helpers ---

    public function lastDay(): CarbonInterface
    {
        return $this->ends_on ?? $this->starts_on;
    }

    public function isWeekly(): bool
    {
        return $this->recurrence === 'weekly';
    }

    /**
     * Whether the event takes place on a date: within its dates, and for a
     * weekly gathering, on its weekday and within the series.
     */
    public function occursOn(CarbonInterface $date): bool
    {
        $day = self::day($date);

        if ($day->lt(self::day($this->starts_on))) {
            return false;
        }

        if ($this->isWeekly()) {
            return $day->dayOfWeek === self::day($this->starts_on)->dayOfWeek
                && ($this->ends_on === null || $day->lte(self::day($this->ends_on)));
        }

        return $day->lte(self::day($this->lastDay()));
    }

    /**
     * The dates it takes place on, from a day (today by default): every
     * date of a one-off event still to come, or the next weeks of a weekly
     * gathering. Calendar dates, compared as dates whatever the time zone.
     *
     * @return list<CarbonImmutable>
     */
    public function nextDates(?CarbonInterface $from = null, int $limit = 8): array
    {
        $from = self::day($from ?? DevotionalClock::now());
        $start = self::day($this->starts_on);
        $cursor = $from->gt($start) ? $from : $start;
        $dates = [];

        if ($this->isWeekly()) {
            $end = $this->ends_on === null ? null : self::day($this->ends_on);
            while ($cursor->dayOfWeek !== $start->dayOfWeek) {
                $cursor = $cursor->addDay();
            }
            while (count($dates) < $limit && ($end === null || $cursor->lte($end))) {
                $dates[] = $cursor;
                $cursor = $cursor->addWeek();
            }

            return $dates;
        }

        $last = self::day($this->lastDay());
        for ($d = $cursor; $d->lte($last) && count($dates) < $limit; $d = $d->addDay()) {
            $dates[] = $d;
        }

        return $dates;
    }

    /** The calendar date of a moment, as midnight UTC, for comparing dates. */
    public static function day(CarbonInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date instanceof CarbonInterface ? $date->toDateString() : $date, 'UTC')->startOfDay();
    }

    public function nextDate(?CarbonInterface $from = null): ?CarbonInterface
    {
        return $this->nextDates($from, 1)[0] ?? null;
    }

    public function isTicketed(): bool
    {
        return $this->registration_enabled && $this->ticket_price_paise > 0;
    }

    public function amountPaiseFor(int $people): int
    {
        return $this->registration_enabled ? $this->ticket_price_paise * max(1, $people) : 0;
    }

    public function priceLabel(): ?string
    {
        if (! $this->registration_enabled) {
            return null;
        }

        return $this->ticket_price_paise > 0 ? '₹'.number_format($this->ticket_price_paise / 100, 2).' per person' : 'Free';
    }

    /** People coming on a date: confirmed and received registrations. */
    public function goingOn(CarbonInterface|string $date): int
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return (int) $this->registrations()->live()->whereDate('occurs_on', $day)->sum('people');
    }

    /** Places left on a date, or null for no limit. */
    public function remainingOn(CarbonInterface|string $date): ?int
    {
        return $this->capacity === null ? null : max(0, $this->capacity - $this->goingOn($date));
    }

    /** @return list<string> */
    public function songList(): array
    {
        return collect(preg_split('/\r\n|\r|\n/', (string) $this->songs))->map(fn ($l) => trim($l))->filter()->values()->all();
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

        return MediaUrl::for($this->image_disk, $this->image_path);
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
