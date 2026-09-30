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
        'gross_paise', 'fee_percent', 'fee_paise', 'net_paise', 'currency',
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
                ? ['id' => $this->temple->getKey(), 'name' => $this->temple->name, 'city' => $this->temple->city]
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
        ];
    }
}
