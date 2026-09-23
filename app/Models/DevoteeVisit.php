<?php

namespace App\Models;

use App\Enums\CheckInMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recorded visit to a temple — the raw material of the Passport.
 *
 * A stamp is not a row in another table; it is the existence of a verified
 * visit here. Keeping it derived means a revoked verification revokes the
 * stamp with it, rather than leaving an orphan in a collection.
 */
class DevoteeVisit extends Model
{
    use HasFactory;

    protected $fillable = [
        'devotee_id', 'temple_id', 'method',
        'visited_on', 'visited_at',
        'latitude', 'longitude', 'distance_metres',
        'note', 'is_verified', 'verified_at', 'is_public',
    ];

    /**
     * Defaults on the model, not only in the database.
     *
     * A database default never reaches the instance that was just created, so
     * an enum-cast column read back as null and every match() on it fell
     * through. Same reasoning as User::$attributes.
     */
    protected $attributes = [
        'method' => 'manual',
        'is_verified' => false,
        'is_public' => true,
    ];

    protected function casts(): array
    {
        return [
            'method' => CheckInMethod::class,
            'visited_on' => 'date',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_verified' => 'boolean',
            'is_public' => 'boolean',
            'verified_at' => 'datetime',
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

    public function photos(): HasMany
    {
        return $this->hasMany(VisitPhoto::class);
    }

    public function memories(): HasMany
    {
        return $this->hasMany(DevoteeMemory::class);
    }

    // --- Scopes ---

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('is_verified', true);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /** Visits recorded on or after the start of the day $days ago. */
    public function scopeSince(Builder $query, int $days): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days - 1)->startOfDay());
    }

    // --- Helpers ---

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * How far the check-in was from the temple, in metres.
     *
     * Uses the asin form of the haversine formula. The acos form returns NaN
     * for a distance of zero through floating-point rounding, and standing
     * inside the temple is precisely the case this has to get right.
     */
    public function distanceFromTemple(): ?int
    {
        if (! $this->hasCoordinates() || ! $this->temple?->hasCoordinates()) {
            return null;
        }

        $earthRadius = 6_371_000;

        $lat1 = deg2rad((float) $this->latitude);
        $lat2 = deg2rad((float) $this->temple->latitude);
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad((float) $this->temple->longitude - (float) $this->longitude);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return (int) round($earthRadius * 2 * asin(min(1.0, sqrt($a))));
    }

    /**
     * Whether a GPS check-in was close enough to count as being there.
     *
     * The radius is generous on purpose. Large temple complexes cover
     * hundreds of metres, phone GPS is poor between tall gopurams, and the
     * failure that matters is telling a devotee standing in the queue that
     * they are not at the temple.
     */
    public function isWithinCheckInRadius(): bool
    {
        $distance = $this->distance_metres ?? $this->distanceFromTemple();

        if ($distance === null) {
            return false;
        }

        return $distance <= (int) setting('check_in_radius_metres', null, 500);
    }

    public function dateLabel(): string
    {
        return $this->visited_on->format('d M Y');
    }
}
