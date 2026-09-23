<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * An app user.
 *
 * Kept entirely separate from the staff `users` table. See the devotees
 * migration for why; the short version is that mixing them would make
 * "a devotee account acquires a staff role" a live possibility instead of an
 * impossible one.
 *
 * This model has no role, no panel access and no relationship to any admin
 * concept. It cannot reach either Filament panel, because neither knows it
 * exists.
 */
class Devotee extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'phone', 'password',
        'avatar_path', 'avatar_disk', 'locale', 'home_state_id', 'date_of_birth', 'gender',
    ];

    protected $hidden = ['password', 'remember_token', 'passport_code'];

    protected $attributes = [
        'locale' => 'en',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'date_of_birth' => 'date',
            'gender' => \App\Enums\Gender::class,
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Every account has a passport code from the start, so the app can
        // show one without a second request to mint it.
        static::creating(function (Devotee $devotee): void {
            $devotee->passport_code ??= static::newPassportCode();
        });
    }

    public static function newPassportCode(): string
    {
        return Str::random(20);
    }

    /**
     * The devotee whose passport a scanned code shows, if it is still live.
     *
     * Accepts the code alone or any URL carrying it, which is what a camera
     * hands over. A reset code matches nobody.
     */
    public static function findByPassportCode(?string $code): ?self
    {
        $code = \App\Support\PassportQr::parse((string) $code);

        if ($code === null) {
            return null;
        }

        return static::query()->where('passport_code', $code)->where('is_active', true)->first();
    }

    /** The code, minted now for an account that predates codes. */
    public function passportCode(): string
    {
        if (blank($this->passport_code)) {
            $this->forceFill(['passport_code' => static::newPassportCode()])->saveQuietly();
        }

        return $this->passport_code;
    }

    /** Retires the old code: anything printed or photographed stops working. */
    public function resetPassportCode(): string
    {
        $this->forceFill(['passport_code' => static::newPassportCode()])->saveQuietly();

        return $this->passport_code;
    }

    public function homeState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'home_state_id');
    }

    public function savedTemples(): BelongsToMany
    {
        return $this->belongsToMany(Temple::class, 'devotee_saved_temples')
            ->withPivot('note')
            ->withTimestamps();
    }

    // --- Passport, trips and writing ---

    public function visits(): HasMany
    {
        return $this->hasMany(DevoteeVisit::class)->latest('visited_on');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(VisitPhoto::class)->latest();
    }

    public function memories(): HasMany
    {
        return $this->hasMany(DevoteeMemory::class)->latest('created_at');
    }

    public function yatras(): HasMany
    {
        return $this->hasMany(Yatra::class)->latest();
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function loginEvents(): MorphMany
    {
        return $this->morphMany(LoginEvent::class, 'authenticatable')->latest('occurred_at');
    }

    // --- Passport figures ---

    /**
     * Temples this devotee has a verified visit to.
     *
     * The stamp count, and the only definition of it. Distinct because
     * returning to a temple is a second visit, not a second stamp.
     */
    public function stampCount(): int
    {
        return $this->visits()->verified()->distinct()->count('temple_id');
    }

    public function templesVisitedCount(): int
    {
        return $this->visits()->distinct()->count('temple_id');
    }

    public function hasVisited(Temple|int $temple): bool
    {
        $id = $temple instanceof Temple ? $temple->getKey() : $temple;

        return $this->visits()->where('temple_id', $id)->exists();
    }

    /** Sign-ins are what "active" is measured from, not record edits. */
    public function lastLoginAt(): ?\Carbon\CarbonInterface
    {
        return $this->loginEvents()->succeeded()->value('occurred_at');
    }

    public function avatarUrl(): ?string
    {
        if (blank($this->avatar_path)) {
            return null;
        }

        return MediaUrl::for($this->avatar_disk, $this->avatar_path);
    }

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null || $this->phone_verified_at !== null;
    }
}
