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
        'claim_latitude', 'claim_longitude', 'claim_accuracy_m', 'claim_distance_m',
    ];

    /**
     * How close to the temple someone must stand to ask to manage it, in
     * metres. Generous for large complexes and GPS between tall gopurams,
     * tight enough that asking from another town is refused.
     */
    public const CLAIM_RADIUS_M = 500;

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'claim_latitude' => 'decimal:7',
            'claim_longitude' => 'decimal:7',
            'claim_accuracy_m' => 'integer',
            'claim_distance_m' => 'integer',
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

    public function hasClaimLocation(): bool
    {
        return $this->claim_latitude !== null && $this->claim_longitude !== null;
    }

    /** Where the request was made, on a map, for staff checking it. */
    public function claimMapUrl(): ?string
    {
        return $this->hasClaimLocation()
            ? 'https://www.google.com/maps/search/?api=1&query='.$this->claim_latitude.','.$this->claim_longitude
            : null;
    }

    /** "At the temple (40 m away)", "2.3 km from the temple", or why not known. */
    public function claimLocationSummary(): string
    {
        if (! $this->hasClaimLocation()) {
            return 'No location (granted by staff or asked before GPS was required)';
        }

        $accuracy = $this->claim_accuracy_m !== null ? ' · GPS ±'.$this->claim_accuracy_m.' m' : '';

        if ($this->claim_distance_m === null) {
            return 'Temple has no map pin to compare'.$accuracy;
        }

        $d = $this->claim_distance_m;
        $far = $d >= 1000 ? number_format($d / 1000, 1).' km' : $d.' m';

        return ($d <= self::CLAIM_RADIUS_M ? 'At the temple ('.$far.' away)' : $far.' from the temple').$accuracy;
    }

    /** Metres between two points (asin haversine, exact at zero). */
    public static function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return (int) round(6_371_000 * 2 * asin(min(1.0, sqrt($a))));
    }
}
