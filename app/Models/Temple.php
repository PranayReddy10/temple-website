<?php

namespace App\Models;

use App\Enums\TempleStatus;
use App\Models\Concerns\HasMantra;
use App\Models\Concerns\HasTranslations;
use Carbon\CarbonInterface;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Temple extends Model
{
    use HasFactory, HasMantra, HasTranslations, SoftDeletes;

    /**
     * Fields a devotee may read in their own language.
     *
     * Names and prose only. A slug is a URL, coordinates are numbers and a
     * source URL is an address — translating any of them produces a record
     * that is broken rather than localised, so they are simply not on the
     * list and the trait refuses anything that is not.
     *
     * @var array<int, string>
     */
    protected array $translatable = [
        'name',
        'short_description',
        'history',
        'significance',
        'mantra_transliteration',
        'dress_code',
        'entry_rules',
        'queue_information',
        'photography_policy',
    ];

    protected $fillable = [
        'name', 'slug', 'deity_id',
        'state_id', 'district_id', 'city', 'address', 'pincode', 'latitude', 'longitude',
        'short_description', 'history', 'significance',
        'mantra', 'mantra_transliteration', 'mantra_media_id',
        'architecture_style', 'built_period',
        'dress_code', 'photography_policy', 'mobile_policy', 'footwear_policy',
        'entry_rules', 'queue_information',
        'official_website', 'contact_phone', 'contact_email',
        'verification_status', 'source_name', 'source_url', 'last_verified_at',
        'status', 'is_featured', 'published_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => TempleStatus::class,
            'is_featured' => 'boolean',
            'verification_status' => VerificationStatus::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'last_verified_at' => 'date',
            'published_at' => 'datetime',
        ];
    }

    // --- Relationships ---

    public function deity(): BelongsTo
    {
        return $this->belongsTo(Deity::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function categories(): BelongsToMany
    {
        // Laravel would derive "temple_temple_category" by sorting the two model
        // names; the pivot is named for readability, so state it explicitly.
        return $this->belongsToMany(TempleCategory::class, 'temple_category_temple')
            ->withTimestamps();
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TempleAlias::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TemplePhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function primaryPhoto(): HasOne
    {
        return $this->hasOne(TemplePhoto::class)->where('is_primary', true);
    }

    public function timings(): HasMany
    {
        return $this->hasMany(TempleTiming::class)->orderBy('sort_order')->orderBy('id');
    }

    public function closures(): HasMany
    {
        return $this->hasMany(TempleClosure::class)->orderBy('starts_on');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TempleEvent::class)->orderBy('starts_on');
    }

    public function pujas(): HasMany
    {
        return $this->hasMany(TemplePuja::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Sevas booked through the app, for the temple's counter to receive. */
    public function pujaBookings(): HasMany
    {
        return $this->hasMany(PujaBooking::class);
    }

    // --- What devotees add ---

    public function likes(): HasMany
    {
        return $this->hasMany(TempleLike::class);
    }

    public function follows(): HasMany
    {
        return $this->hasMany(TempleFollow::class);
    }

    /** Devotees who asked to be told about this temple. */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(Devotee::class, 'temple_follows')
            ->withPivot(['notify_festivals', 'notify_events'])
            ->withTimestamps();
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(TempleReview::class)->latest();
    }

    /** Gallery photos that came from devotees' Photo Stamps. */
    public function devoteePhotos(): HasMany
    {
        return $this->hasMany(TemplePhoto::class)->whereNotNull('visit_photo_id');
    }

    public function facilities(): BelongsToMany
    {
        return $this->belongsToMany(Facility::class)
            ->withPivot(['is_verified', 'note'])
            ->withTimestamps()
            ->orderBy('sort_order');
    }

    /** Approved temple authorities for this temple. */
    public function administrators(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(TempleUser::class)
            ->withPivot(['role', 'approved_at', 'requested_at'])
            ->wherePivotNotNull('approved_at')
            ->withTimestamps();
    }

    /**
     * This temple's own songs and chants.
     *
     * Tirumala's Suprabhatam is sung at Tirumala. A devotee standing there
     * should hear that rather than the general Vishnu aarti, so a temple
     * carries its own media and falls back to the deity's when it has none.
     */
    public function media(): MorphMany
    {
        return $this->morphMany(DevotionalMedia::class, 'mediable')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(DevoteeVisit::class);
    }

    public function visitPhotos(): HasMany
    {
        return $this->hasMany(VisitPhoto::class);
    }

    /** Devotees who saved this temple: the intent signal, before any visit. */
    public function savedByDevotees(): BelongsToMany
    {
        return $this->belongsToMany(Devotee::class, 'devotee_saved_temples')
            ->withPivot('note')
            ->withTimestamps();
    }

    public function yatraStops(): HasMany
    {
        return $this->hasMany(YatraStop::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(TempleUser::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // --- Scopes ---

    /** The only scope the public API may expose. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', TempleStatus::Published);
    }

    /** Temples editors have marked as famous. */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /**
     * Match a temple by its own name or any recorded alternate/local name, so
     * searching "Tirupati" finds Sri Venkateswara Swamy Temple.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('city', 'like', "%{$term}%")
                ->orWhereHas('aliases', fn (Builder $a) => $a->where('name', 'like', "%{$term}%"));
        });
    }

    /**
     * Bounding-box prefilter for "temples near me".
     *
     * This narrows candidates using the (latitude, longitude) index before any
     * distance maths runs. It deliberately returns a square, not a circle —
     * exact distance ranking arrives with the public API in roadmap slice 4,
     * where MySQL's ST_Distance_Sphere does the ordering over this subset.
     */
    public function scopeWithinBoundingBox(Builder $query, float $lat, float $lng, float $radiusKm): Builder
    {
        // One degree of latitude is ~111km everywhere. One degree of longitude
        // shrinks towards the poles, so scale it by cos(latitude).
        $latDelta = $radiusKm / 111.0;
        $cos = cos(deg2rad($lat));
        // Guard against a division by ~0 near the poles. India never comes close,
        // but the scope should not be a trap if it is reused.
        $lngDelta = abs($cos) < 0.000001 ? 180.0 : $radiusKm / (111.0 * abs($cos));

        return $query
            ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta]);
    }

    /**
     * Adds a `distance_km` column measured from the given point and orders by it.
     *
     * Uses the asin form of the haversine formula rather than the more common
     * acos form. The acos form feeds a value that floating-point error can push
     * just above 1.0 into acos(), which returns NaN — so searching "near" a
     * temple whose coordinates match exactly would drop that temple from its own
     * results. The asin form is stable at zero distance.
     *
     * Deliberately plain trigonometry rather than MySQL's ST_Distance_Sphere:
     * it runs identically on MySQL and SQLite, so the test suite exercises the
     * same maths production does. Accuracy is equivalent at these distances.
     *
     * Always combined with withinBoundingBox(), which narrows candidates using
     * the (latitude, longitude) index before any of this runs. Without that
     * prefilter this expression would force a full table scan.
     */
    public function scopeWithDistanceFrom(Builder $query, float $lat, float $lng): Builder
    {
        $haversine = '2 * 6371 * asin(sqrt('
            .'sin((radians(temples.latitude) - radians(?)) / 2) * sin((radians(temples.latitude) - radians(?)) / 2)'
            .' + cos(radians(?)) * cos(radians(temples.latitude))'
            .' * sin((radians(temples.longitude) - radians(?)) / 2) * sin((radians(temples.longitude) - radians(?)) / 2)'
            .'))';

        return $query
            ->addSelect(['*'])
            ->selectRaw("{$haversine} as distance_km", [$lat, $lat, $lat, $lng, $lng])
            ->whereNotNull('temples.latitude')
            ->whereNotNull('temples.longitude')
            ->orderBy('distance_km');
    }

    // --- Helpers ---

    /**
     * What to play here: this temple's media first, then its deity's.
     *
     * @return \Illuminate\Support\Collection<int, DevotionalMedia>
     */
    public function allMedia(bool $publishedOnly = true)
    {
        $own = $this->relationLoaded('media') ? $this->media : $this->media()->get();
        $deity = $this->deity?->relationLoaded('media')
            ? $this->deity->media
            : ($this->deity?->media()->get() ?? collect());

        return $own->concat($deity)
            ->when($publishedOnly, fn ($all) => $all->filter->is_published)
            ->values();
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Whether a published closure covers the given date. Devotees travel a
     * long way, so a closure must win over the regular timings.
     */
    public function isClosedOn(?CarbonInterface $date = null): bool
    {
        $date = $date ?? now();

        return $this->closures
            ->contains(fn (TempleClosure $closure): bool => $closure->is_full_day && $closure->coversDate($date));
    }

    /**
     * Timings and fees drift, so a record verified long ago should be
     * re-checked. Section 20 of the plan calls for flagging stale data.
     */
    public function isStale(int $months = 12): bool
    {
        if ($this->last_verified_at === null) {
            return true;
        }

        return $this->last_verified_at->lt(now()->subMonths($months));
    }
}
