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
 * A devotee's place at one date of a temple event: "I'll join" for a free
 * bhajan gathering, or tickets for a paid event.
 *
 * Shaped like a seva booking on purpose: the same statuses, a readable
 * reference for the counter and a random code for the QR, verified once at
 * the gate, paid through the same gateways and settled with the temple.
 */
class EventRegistration extends Model
{
    protected $fillable = [
        'temple_event_id', 'temple_id', 'devotee_id', 'payment_id',
        'occurs_on', 'people', 'devotee_name', 'devotee_phone',
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
            'occurs_on' => 'date',
            'people' => 'integer',
            'amount_paise' => 'integer',
            'confirmed_at' => 'datetime',
            'verified_at' => 'datetime',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EventRegistration $r): void {
            $r->reference ??= static::newReference();
            $r->code ??= Str::random(28);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /** "EV" and eight characters with no 0/O or 1/I to mistake. */
    public static function newReference(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        do {
            $ref = 'EV';
            for ($i = 0; $i < 8; $i++) {
                $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::query()->where('reference', $ref)->exists());

        return $ref;
    }

    public static function findByCode(?string $code): ?self
    {
        $token = BookingQr::parse((string) $code);

        return $token === null ? null : static::query()->where('code', $token)->first();
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(TempleEvent::class, 'temple_event_id');
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
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

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(TempleSettlement::class, 'settlement_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Verified->value]);
    }

    /** Paid tickets the platform holds for the temple, in no settlement yet. */
    public function scopeSettleable(Builder $query): Builder
    {
        return $query->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Verified->value, BookingStatus::Expired->value])
            ->where('amount_paise', '>', 0)
            ->whereNull('settlement_id')
            ->whereHas('payment', fn (Builder $q) => $q->where('status', Payment::PAID));
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    public function isConfirmed(): bool
    {
        return $this->status === BookingStatus::Confirmed;
    }

    public function isVerified(): bool
    {
        return $this->status === BookingStatus::Verified;
    }

    public function isFree(): bool
    {
        return $this->amount_paise === 0;
    }

    public function amountLabel(): string
    {
        return $this->isFree() ? 'Free' : '₹'.number_format($this->amount_paise / 100, 2);
    }

    public function canBeCancelledByDevotee(): bool
    {
        return in_array($this->status, [BookingStatus::PendingPayment, BookingStatus::Confirmed], true)
            && $this->settlement_id === null
            && $this->occurs_on->toDateString() >= DevotionalClock::now()->toDateString();
    }

    public function canBePaidFor(): bool
    {
        return $this->status === BookingStatus::PendingPayment
            && $this->amount_paise > 0
            && $this->occurs_on->toDateString() >= DevotionalClock::now()->toDateString();
    }

    public function qrUrl(): string
    {
        return BookingQr::url($this);
    }

    public function summary(): string
    {
        return collect([$this->event?->title, $this->temple?->name, $this->occurs_on?->format('d M Y')])->filter()->implode(' · ');
    }
}
