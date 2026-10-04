<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money given to a temple's hundi through the app.
 *
 * Paid through the platform's gateway like a seva booking and settled with
 * the temple in the same settlements. The amount is the devotee's choice
 * within limits; the receipt is the reference. A donor may give anonymously:
 * the temple then sees "A devotee", while the platform keeps who paid for
 * refunds and the payment's own records.
 */
class TempleDonation extends Model
{
    public const PENDING = 'pending_payment';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const REFUNDED = 'refunded';

    public const PURPOSES = [
        'general' => 'General hundi',
        'annadanam' => 'Annadanam (free meals)',
        'maintenance' => 'Temple maintenance',
        'gau_seva' => 'Gau seva',
        'festival' => 'Festival',
        'other' => 'Other',
    ];

    /** ₹10 to ₹5,00,000 a gift, in paise. */
    public const MIN_PAISE = 1000;

    public const MAX_PAISE = 50000000;

    protected $fillable = [
        'temple_id', 'devotee_id', 'payment_id', 'amount_paise', 'currency',
        'purpose', 'donor_name', 'is_anonymous', 'note', 'status',
    ];

    protected $attributes = [
        'currency' => 'INR',
        'purpose' => 'general',
        'is_anonymous' => false,
        'status' => self::PENDING,
    ];

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'is_anonymous' => 'boolean',
            'paid_at' => 'datetime',
            'paid_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TempleDonation $d): void {
            $d->reference ??= static::newReference();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public static function newReference(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        do {
            $ref = 'HN';
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

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(TempleSettlement::class, 'settlement_id');
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::PAID);
    }

    public function scopeSettleable(Builder $query): Builder
    {
        return $query->paid()->whereNull('settlement_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function amountLabel(): string
    {
        return '₹'.number_format($this->amount_paise / 100, 2);
    }

    public function purposeLabel(): string
    {
        return self::PURPOSES[$this->purpose] ?? ucfirst((string) $this->purpose);
    }

    /** What the temple's team sees as the giver. */
    public function displayName(): string
    {
        return $this->is_anonymous ? 'A devotee' : ($this->donor_name ?: ($this->devotee?->name ?? 'A devotee'));
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PAID => 'Received',
            self::FAILED => 'Not completed',
            self::REFUNDED => 'Refunded',
            default => 'Awaiting payment',
        };
    }

    /** As the devotee who gave sees it: their receipt. */
    public function toDevoteeArray(): array
    {
        return [
            'reference' => $this->reference,
            'status' => ['value' => $this->status, 'label' => $this->statusLabel()],
            'amount_paise' => $this->amount_paise,
            'amount' => $this->amountLabel(),
            'purpose' => ['value' => $this->purpose, 'label' => $this->purposeLabel()],
            'donor_name' => $this->donor_name,
            'is_anonymous' => $this->is_anonymous,
            'note' => $this->note,
            'temple' => $this->temple === null ? null : [
                'id' => $this->temple->getKey(),
                'slug' => $this->temple->slug,
                'name' => $this->temple->name,
                'city' => $this->temple->city,
            ],
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** As the temple's team sees it: never the donor's account, and no name if anonymous. */
    public function toTempleArray(): array
    {
        return [
            'reference' => $this->reference,
            'status' => ['value' => $this->status, 'label' => $this->statusLabel()],
            'amount_paise' => $this->amount_paise,
            'amount' => $this->amountLabel(),
            'purpose' => ['value' => $this->purpose, 'label' => $this->purposeLabel()],
            'donor' => $this->displayName(),
            'is_anonymous' => $this->is_anonymous,
            'note' => $this->note,
            'paid_on' => $this->paid_on?->toDateString(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'settled' => $this->settlement_id !== null,
        ];
    }
}
