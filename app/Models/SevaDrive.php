<?php

namespace App\Models;

use App\Enums\SevaCause;
use App\Enums\SevaDriveStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A devotee-organised drive to care for a temple or heritage place.
 *
 * Raised with photographs of the place as it is, joined by volunteers, closed
 * with photographs of the place afterwards, and verified by staff. Only then
 * may it ask for donations.
 */
class SevaDrive extends Model
{
    /*
     * Status, moderation and verification are deliberately absent. They are
     * staff decisions, set by assignment where staff make them; a devotee's
     * request must never be able to approve its own drive.
     */
    protected $fillable = [
        'temple_id', 'title', 'cause', 'place_name', 'address', 'city', 'state_id',
        'latitude', 'longitude', 'meeting_point', 'problem', 'plan', 'what_to_bring',
        'starts_at', 'ends_at', 'volunteers_needed', 'contact_phone',
        'upi_id', 'upi_name', 'donation_goal', 'donation_purpose',
    ];

    protected $attributes = [
        'status' => 'pending',
        'cause' => 'cleaning',
        'donations_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'status' => SevaDriveStatus::class,
            'cause' => SevaCause::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'moderated_at' => 'datetime',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'volunteers_needed' => 'integer',
            'donation_goal' => 'integer',
            'donations_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Row by row, so each media row takes its file with it; the database
        // cascade alone would leave the files behind.
        static::deleting(function (SevaDrive $drive): void {
            $drive->media()->get()->each->delete();
        });
    }

    public function organiser(): BelongsTo
    {
        return $this->belongsTo(Devotee::class, 'devotee_id');
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function media(): HasMany
    {
        return $this->hasMany(SevaDriveMedia::class)->oldest('id');
    }

    public function volunteers(): HasMany
    {
        return $this->hasMany(SevaDriveVolunteer::class)->oldest('id');
    }

    public function volunteerDevotees(): BelongsToMany
    {
        return $this->belongsToMany(Devotee::class, 'seva_drive_volunteers')
            ->withPivot(['party_size', 'note', 'attended'])
            ->withTimestamps();
    }

    public function donations(): HasMany
    {
        return $this->hasMany(SevaDriveDonation::class)->latest('id');
    }

    // --- Scopes ---

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SevaDriveStatus::Approved, SevaDriveStatus::Completed, SevaDriveStatus::Verified,
        ]);
    }

    /** What staff need to look at: new drives, and finished ones to verify. */
    public function scopeNeedsStaff(Builder $query): Builder
    {
        return $query->whereIn('status', [SevaDriveStatus::Pending, SevaDriveStatus::Completed]);
    }

    // --- Helpers ---

    public function isOrganisedBy(?Devotee $devotee): bool
    {
        return $devotee !== null && $this->devotee_id === $devotee->getKey();
    }

    public function hasVolunteer(?Devotee $devotee): bool
    {
        if ($devotee === null) {
            return false;
        }

        return $this->volunteers()->where('devotee_id', $devotee->getKey())->exists();
    }

    /** People coming, counting each sign-up's party. */
    public function headcount(): int
    {
        return (int) $this->volunteers()->sum('party_size');
    }

    /**
     * Whether the UPI ID may be shown to anybody.
     *
     * Three conditions, in one place: staff verified the result, staff have
     * not switched donations off, and there is an ID to show. A caller that
     * checks only the status would serve an ID staff took down.
     */
    public function acceptsDonations(): bool
    {
        return $this->status?->allowsDonations() === true
            && $this->donations_enabled
            && filled($this->upi_id);
    }

    public function confirmedDonationTotal(): int
    {
        return (int) $this->donations()->whereNotNull('confirmed_at')->sum('amount');
    }

    /**
     * The upi://pay link any UPI app opens with the payee filled in.
     *
     * The amount is left for the donor to type; a note names the drive so
     * the organiser can tell what a credit was for.
     */
    public function upiLink(): ?string
    {
        if (! $this->acceptsDonations()) {
            return null;
        }

        // The @ in the payee address stays literal: some UPI apps do not
        // decode %40 there, and then cannot find the payee.
        return 'upi://pay?'.str_replace('%40', '@', http_build_query([
            'pa' => $this->upi_id,
            'pn' => $this->upi_name ?: $this->organiser?->name,
            'cu' => 'INR',
            'tn' => str('Seva: '.$this->title)->limit(60, '')->toString(),
        ], '', '&', PHP_QUERY_RFC3986));
    }
}
