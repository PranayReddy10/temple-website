<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payout to a temple for the paid seva bookings it covers.
 *
 * Pending while the transfer is being made; paid once it has been, with the
 * bank's reference; cancelled to release its bookings into the next one.
 */
class TempleSettlement extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::PENDING => 'To be paid',
        self::PAID => 'Paid',
        self::CANCELLED => 'Cancelled',
    ];

    public const METHODS = [
        'bank' => 'Bank transfer (NEFT / IMPS / RTGS)',
        'upi' => 'UPI',
        'cheque' => 'Cheque',
        'cash' => 'Cash',
    ];

    protected $fillable = [
        'temple_id', 'period_from', 'period_to', 'bookings_count',
        'bookings_paise', 'tickets_count', 'tickets_paise', 'donations_count', 'donations_paise',
        'gross_paise', 'fee_percent', 'donation_fee_percent', 'fee_paise', 'net_paise', 'currency',
        'status', 'payout_to', 'method', 'transaction_ref', 'paid_at', 'note',
        'cancel_reason', 'created_by', 'paid_by',
    ];

    protected $attributes = [
        'status' => self::PENDING,
        'currency' => 'INR',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'bookings_count' => 'integer',
            'bookings_paise' => 'integer',
            'tickets_count' => 'integer',
            'tickets_paise' => 'integer',
            'donations_count' => 'integer',
            'donations_paise' => 'integer',
            'donation_fee_percent' => 'decimal:2',
            'gross_paise' => 'integer',
            'fee_percent' => 'decimal:2',
            'fee_paise' => 'integer',
            'net_paise' => 'integer',
            'payout_to' => 'encrypted',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TempleSettlement $settlement): void {
            $settlement->reference ??= static::newReference();
        });
    }

    public static function newReference(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        do {
            $ref = 'ST';
            for ($i = 0; $i < 8; $i++) {
                $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::query()->where('reference', $ref)->exists());

        return $ref;
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(PujaBooking::class, 'settlement_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(EventRegistration::class, 'settlement_id');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(TempleDonation::class, 'settlement_id');
    }

    /** "12 bookings · 3 tickets · 5 hundi gifts", leaving out what is not there. */
    public function itemsLabel(): string
    {
        return collect([
            $this->bookings_count > 0 ? $this->bookings_count.' '.($this->bookings_count === 1 ? 'seva booking' : 'seva bookings') : null,
            $this->tickets_count > 0 ? $this->tickets_count.' '.($this->tickets_count === 1 ? 'event ticket' : 'event tickets') : null,
            $this->donations_count > 0 ? $this->donations_count.' '.($this->donations_count === 1 ? 'hundi gift' : 'hundi gifts') : null,
        ])->filter()->implode(' · ') ?: 'Nothing';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::PAID);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function periodLabel(): string
    {
        $to = $this->period_to?->format('d M Y');

        return $this->period_from === null || $this->period_from->isSameDay($this->period_to)
            ? (string) $to
            : $this->period_from->format('d M Y').' – '.$to;
    }

    public static function rupees(int $paise): string
    {
        return '₹'.number_format($paise / 100, 2);
    }

    /** As the temple's team sees it. */
    public function toPublicArray(bool $withBookings = false): array
    {
        return [
            'id' => $this->getKey(),
            'reference' => $this->reference,
            'status' => ['value' => $this->status, 'label' => $this->statusLabel()],
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'period' => $this->periodLabel(),
            'bookings_count' => $this->bookings_count,
            'items' => $this->itemsLabel(),
            'breakdown' => [
                'bookings' => ['count' => $this->bookings_count, 'amount_paise' => $this->bookings_paise, 'amount' => self::rupees($this->bookings_paise)],
                'tickets' => ['count' => $this->tickets_count, 'amount_paise' => $this->tickets_paise, 'amount' => self::rupees($this->tickets_paise)],
                'donations' => ['count' => $this->donations_count, 'amount_paise' => $this->donations_paise, 'amount' => self::rupees($this->donations_paise)],
            ],
            'donation_fee_percent' => (float) $this->donation_fee_percent,
            'gross_paise' => $this->gross_paise,
            'gross' => self::rupees($this->gross_paise),
            'fee_percent' => (float) $this->fee_percent,
            'fee_paise' => $this->fee_paise,
            'fee' => self::rupees($this->fee_paise),
            'net_paise' => $this->net_paise,
            'net' => self::rupees($this->net_paise),
            'method' => $this->method,
            'method_label' => $this->method === null ? null : (self::METHODS[$this->method] ?? $this->method),
            'transaction_ref' => $this->transaction_ref,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'note' => $this->note,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'temple' => $this->relationLoaded('temple') && $this->temple !== null
                ? ['id' => $this->temple->getKey(), 'name' => $this->temple->localName(), 'city' => $this->temple->city]
                : null,
            'bookings' => $withBookings
                ? $this->bookings->map(fn (PujaBooking $b): array => [
                    'reference' => $b->reference,
                    'booked_for' => $b->booked_for?->toDateString(),
                    'seva' => $b->puja?->name,
                    'devotee_name' => $b->devotee_name,
                    'people' => $b->people,
                    'amount_paise' => $b->amount_paise,
                    'amount' => $b->amountLabel(),
                    'status' => ['value' => $b->status->value, 'label' => $b->status->getLabel()],
                ])->values()->all()
                : null,
            'tickets' => $withBookings
                ? $this->tickets->map(fn (EventRegistration $r): array => [
                    'reference' => $r->reference,
                    'occurs_on' => $r->occurs_on?->toDateString(),
                    'event' => $r->event?->title,
                    'devotee_name' => $r->devotee_name,
                    'people' => $r->people,
                    'amount_paise' => $r->amount_paise,
                    'amount' => $r->amountLabel(),
                ])->values()->all()
                : null,
            'donations' => $withBookings
                ? $this->donations->map(fn (TempleDonation $d): array => $d->toTempleArray())->values()->all()
                : null,
        ];
    }
}
