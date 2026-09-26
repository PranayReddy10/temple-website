<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Support\BookingQr;
use App\Support\DevotionalClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A puja, seva or prasadam a devotee booked through the app.
 *
 * Two identifiers, neither of them the id. The reference is short and
 * readable, for the counter and the receipt; the code is long and random,
 * for the QR the counter scans. Nothing outside addresses a booking by id,
 * so nobody can walk the table.
 */
class PujaBooking extends Model
{
    protected $fillable = [
        'temple_id', 'temple_puja_id', 'devotee_id', 'payment_id',
        'booked_for', 'people',
        'devotee_name', 'devotee_phone', 'gotram', 'nakshatram', 'note',
        'amount_paise', 'currency', 'status',
    ];

    protected $attributes = [
        'people' => 1,
        'amount_paise' => 0,
        'currency' => 'INR',
        'status' => 'pending_payment',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'booked_for' => 'date',
            'people' => 'integer',
            'amount_paise' => 'integer',
            'confirmed_at' => 'datetime',
            'verified_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PujaBooking $booking): void {
            $booking->reference ??= static::newReference();
            $booking->code ??= static::newCode();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * A reference somebody can read out over a counter: eight characters
     * from an alphabet with no 0/O or 1/I to mistake for each other.
     */
    public static function newReference(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        do {
            $ref = 'SV';
            for ($i = 0; $i < 8; $i++) {
                $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::query()->where('reference', $ref)->exists());

        return $ref;
    }

    public static function newCode(): string
    {
        return Str::random(28);
    }

    /** The booking a scanned code names, in whatever form the camera hands over. */
    public static function findByCode(?string $code): ?self
    {
        $token = BookingQr::parse((string) $code);

        return $token === null ? null : static::query()->where('code', $token)->first();
    }

    // --- Relationships ---

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function puja(): BelongsTo
    {
        return $this->belongsTo(TemplePuja::class, 'temple_puja_id');
    }

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // --- Scopes ---

    /** Bookings the temple should expect somebody for. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Verified->value]);
    }

    public function scopeForDay(Builder $query, \Carbon\CarbonInterface|string $date): Builder
    {
        return $query->whereDate('booked_for', $date);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereDate('booked_for', '>=', DevotionalClock::now()->toDateString());
    }

    // --- State ---

    public function isVerified(): bool
    {
        return $this->status === BookingStatus::Verified;
    }

    public function isConfirmed(): bool
    {
        return $this->status === BookingStatus::Confirmed;
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    public function isPaid(): bool
    {
        return $this->amount_paise === 0 || $this->payment?->isPaid() === true;
    }

    /**
     * The devotee may cancel while it is still ahead of them. Refunds of a
     * paid booking are the temple's to make, in the gateway's dashboard, and
     * recorded afterwards; the app never promises money back on its own.
     */
    public function canBeCancelledByDevotee(): bool
    {
        return in_array($this->status, [BookingStatus::PendingPayment, BookingStatus::Confirmed], true)
            && $this->booked_for->toDateString() >= DevotionalClock::now()->toDateString();
    }

    public function isFree(): bool
    {
        return $this->amount_paise === 0;
    }

    public function amountLabel(): string
    {
        return $this->isFree() ? 'Free' : '₹'.number_format($this->amount_paise / 100, 2);
    }

    public function qrUrl(): string
    {
        return BookingQr::url($this);
    }

    /**
     * A description for a receipt or a gateway's page: the seva, the
     * temple, the day.
     */
    public function summary(): string
    {
        return collect([
            $this->puja?->name,
            $this->temple?->name,
            $this->booked_for?->format('d M Y'),
        ])->filter()->implode(' · ');
    }
}
