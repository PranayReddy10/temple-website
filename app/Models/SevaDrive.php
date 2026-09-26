<?php

namespace App\Models;

use App\Enums\SevaCause;
use App\Enums\SevaDriveStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

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
        'temple_id', 'title', 'cause', 'place_name', 'address', 'pincode', 'city', 'district', 'state_id',
        'latitude', 'longitude', 'meeting_point', 'problem', 'plan', 'what_to_bring',
        'starts_at', 'ends_at', 'volunteers_needed', 'contact_phone',
        'upi_id', 'upi_name', 'donation_goal', 'donation_purpose',
    ];

    protected $attributes = [
        'status' => 'pending',
        'cause' => 'cleaning',
        'donations_enabled' => true,
        'is_misleading' => false,
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
            'verification_requested_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'volunteers_needed' => 'integer',
            'donation_goal' => 'integer',
            'donations_enabled' => 'boolean',
            'blocked_at' => 'datetime',
            'is_misleading' => 'boolean',
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

    /** Reports people filed about this drive, through Support & Reports. */
    public function reports(): MorphMany
    {
        return $this->morphMany(SupportTicket::class, 'about');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(SevaDriveDonation::class)->latest('id');
    }

    // --- Scopes ---

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [SevaDriveStatus::Approved, SevaDriveStatus::Completed]);
    }

    /** What staff need to look at: drives waiting for approval, and requests to verify. */
    public function scopeNeedsStaff(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('status', SevaDriveStatus::Pending)
            ->orWhere(fn (Builder $r) => $r->verificationRequested()));
    }

    /** The organiser asked, staff have not yet verified, and it is still up. */
    public function scopeVerificationRequested(Builder $query): Builder
    {
        return $query->whereNotNull('verification_requested_at')
            ->whereNull('verified_at')
            ->whereIn('status', [SevaDriveStatus::Approved, SevaDriveStatus::Completed]);
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at');
    }

    /**
     * Over: marked done, or open with its last day behind it. A drive with no
     * end time runs to the end of the day it starts.
     */
    public function scopeFinished(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('status', SevaDriveStatus::Completed)
            ->orWhere(fn (Builder $open) => $open
                ->where('status', SevaDriveStatus::Approved)
                ->where(fn (Builder $ended) => $ended
                    ->where(fn (Builder $e) => $e->whereNotNull('ends_at')->where('ends_at', '<', now()))
                    ->orWhere(fn (Builder $e) => $e->whereNull('ends_at')->where('starts_at', '<', now()->startOfDay())))));
    }

    /** Open and not yet over: what can still be joined. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', SevaDriveStatus::Approved)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $e) => $e->whereNotNull('ends_at')->where('ends_at', '>=', now()))
                ->orWhere(fn (Builder $e) => $e->whereNull('ends_at')->where('starts_at', '>=', now()->startOfDay())));
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
        return $this->isVerified()
            && $this->status?->isPublic() === true
            && $this->donations_enabled
            && ! $this->is_misleading
            && filled($this->upi_id);
    }

    /** Open, not over, and not flagged: nobody should sign up for a misleading drive. */
    public function acceptsVolunteers(): bool
    {
        return $this->status?->acceptsVolunteers() === true && ! $this->hasEnded() && ! $this->is_misleading;
    }

    // --- Verification: a badge, not a stage ---

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function verificationPending(): bool
    {
        return $this->verification_requested_at !== null && ! $this->isVerified();
    }

    public function requestVerification(?string $note): void
    {
        $this->verification_requested_at = now();
        $this->verification_note = $note;
        $this->save();
    }

    public function verify(?int $by): void
    {
        $this->verified_at = now();
        $this->verified_by = $by;
        $this->save();
    }

    /** Take the badge away; a declined request carries the reason. */
    public function unverify(?string $reason = null): void
    {
        $this->verified_at = null;
        $this->verified_by = null;
        $this->verification_requested_at = null;
        if ($reason !== null) {
            $this->moderation_note = $reason;
        }
        $this->save();
    }

    // --- When it is over ---

    /** The moment it ends: its end time, or the end of the day it starts. */
    public function endsAtOrEndOfDay(): ?\Carbon\CarbonInterface
    {
        return $this->ends_at ?? $this->starts_at?->copy()->endOfDay();
    }

    public function hasEnded(): bool
    {
        return $this->endsAtOrEndOfDay()?->isPast() === true;
    }

    /**
     * The status people should see. An open drive whose last day is behind
     * it is completed, whether or not the organiser came back to say so.
     */
    public function effectiveStatus(): ?SevaDriveStatus
    {
        return $this->status === SevaDriveStatus::Approved && $this->hasEnded()
            ? SevaDriveStatus::Completed
            : $this->status;
    }

    /** Who is shown as running it: the name staff gave, else the devotee's. */
    public function organiserName(): string
    {
        return $this->organiser_name
            ?: $this->organiser?->name
            ?: config('brand.name', config('app.name')).' team';
    }

    /** Runs over more than one calendar day. */
    public function isMultiDay(): bool
    {
        return $this->ends_at !== null && ! $this->ends_at->isSameDay($this->starts_at);
    }

    /** Calendar days it spans, counting both ends. */
    public function dayCount(): int
    {
        if ($this->starts_at === null || $this->ends_at === null) {
            return 1;
        }

        return (int) $this->starts_at->copy()->startOfDay()->diffInDays($this->ends_at->copy()->startOfDay()) + 1;
    }

    /** "Sat 12 Oct 2026, 7:00 am – 11:00 am" or "12 Oct – 14 Oct 2026". */
    public function dateLabel(): string
    {
        $start = $this->starts_at;

        if ($start === null) {
            return '';
        }

        if ($this->ends_at === null) {
            return $start->format('D j M Y, g:i a');
        }

        return $this->isMultiDay()
            ? $start->format('D j M').' – '.$this->ends_at->format('D j M Y')
            : $start->format('D j M Y, g:i a').' – '.$this->ends_at->format('g:i a');
    }

    // --- Staff moderation after the fact ---

    public function block(string $reason): void
    {
        if ($this->status !== SevaDriveStatus::Blocked) {
            $this->status_before_block = $this->status?->value;
        }

        $this->status = SevaDriveStatus::Blocked;
        $this->blocked_at = now();
        // The staff guard by name: under an API request the default guard
        // is the devotee's, and a devotee id is not a user.
        $this->blocked_by = Auth::guard('web')->id();
        $this->block_reason = $reason;
        $this->save();
    }

    /** Back to where it was before it was blocked. */
    public function unblock(): void
    {
        $this->status = SevaDriveStatus::tryFrom((string) $this->status_before_block) ?? SevaDriveStatus::Pending;
        $this->status_before_block = null;
        $this->blocked_at = null;
        $this->blocked_by = null;
        $this->block_reason = null;
        $this->save();
    }

    public function markMisleading(?string $note): void
    {
        $this->is_misleading = $note !== null;
        $this->misleading_note = $note;
        $this->save();
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
            'pn' => $this->upi_name ?: $this->organiserName(),
            'cu' => 'INR',
            'tn' => str('Seva: '.$this->title)->limit(60, '')->toString(),
        ], '', '&', PHP_QUERY_RFC3986));
    }
}
