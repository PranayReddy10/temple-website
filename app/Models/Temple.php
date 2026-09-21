<?php

namespace App\Models;

use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Temple extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'deity_id',
        'state_id', 'district_id', 'city', 'address', 'pincode', 'latitude', 'longitude',
        'short_description', 'history', 'significance', 'architecture_style', 'built_period',
        'official_website', 'contact_phone', 'contact_email',
        'verification_status', 'source_name', 'source_url', 'last_verified_at',
        'status', 'published_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => TempleStatus::class,
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

    // --- Helpers ---

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
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
