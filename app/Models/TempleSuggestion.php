<?php

namespace App\Models;

use App\Enums\TempleSuggestionStatus;
use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A temple somebody added from the app, waiting to become a listing.
 */
class TempleSuggestion extends Model
{
    /** Who is suggesting it, as they describe themselves. */
    public const ROLES = [
        'devotee' => 'A devotee who visits',
        'trustee' => 'Trustee',
        'priest' => 'Priest / archaka',
        'committee' => 'Temple committee member',
        'staff' => 'Temple office staff',
        'other' => 'Someone else',
    ];

    public const MAX_PHOTOS = 8;

    protected $fillable = [
        'name', 'alternate_names', 'deity_id', 'deity_name',
        'address', 'pincode', 'city', 'district', 'state_id', 'latitude', 'longitude',
        'description', 'history', 'built_period', 'festivals',
        'opens_at', 'closes_at', 'timings_note', 'contact_phone', 'official_website',
        'submitter_role', 'submitter_name', 'submitter_phone', 'submitter_note',
    ];

    protected $attributes = [
        'status' => 'pending',
        'submitter_role' => 'devotee',
    ];

    protected function casts(): array
    {
        return [
            'status' => TempleSuggestionStatus::class,
            'reviewed_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class);
    }

    public function deity(): BelongsTo
    {
        return $this->belongsTo(Deity::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TempleSuggestionPhoto::class)->oldest('id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', TempleSuggestionStatus::Pending);
    }

    /** From the temple itself rather than a visitor: the future portal owner. */
    public function isFromTempleMember(): bool
    {
        return ! in_array($this->submitter_role, ['devotee', 'other'], true);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->submitter_role] ?? 'Someone else';
    }

    /**
     * Turns the suggestion into a draft temple, photographs included.
     *
     * A draft, not a published listing: staff still read it over in the
     * temple's own form before anybody can find it. The photographs are
     * copied, not moved, so the suggestion keeps its evidence.
     */
    public function createTemple(?int $by): Temple
    {
        return DB::transaction(function () use ($by): Temple {
            $temple = Temple::create([
                'name' => $this->name,
                'deity_id' => $this->deity_id,
                'state_id' => $this->state_id,
                'city' => $this->city,
                'address' => collect([$this->address, $this->district])->filter()->implode(', ') ?: null,
                'pincode' => $this->pincode,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'short_description' => $this->description ? Str::limit($this->description, 250) : null,
                'history' => $this->history ?: $this->description,
                'built_period' => $this->built_period,
                'official_website' => $this->official_website,
                'contact_phone' => $this->contact_phone,
                'verification_status' => VerificationStatus::Community,
                'source_name' => 'Suggested in the app by '.($this->submitter_name ?: $this->devotee?->name ?: 'a devotee').' ('.$this->roleLabel().')',
                'status' => TempleStatus::Draft,
                'created_by' => $by,
            ]);

            foreach ($this->photos as $i => $photo) {
                $disk = $photo->disk ?: config('filesystems.media');
                $target = 'temples/'.$temple->getKey().'/'.basename($photo->path);

                try {
                    Storage::disk($disk)->copy($photo->path, $target);
                } catch (\Throwable) {
                    continue;
                }

                $temple->photos()->create([
                    'disk' => $disk,
                    'path' => $target,
                    'category' => 'gallery',
                    'credit' => $this->submitter_name ?: $this->devotee?->name,
                    'is_primary' => $i === 0,
                    'is_published' => false,
                    'uploaded_by' => $by,
                ]);
            }

            $this->forceFill([
                'status' => TempleSuggestionStatus::Approved,
                'temple_id' => $temple->getKey(),
                'reviewed_by' => $by,
                'reviewed_at' => now(),
            ])->save();

            return $temple;
        });
    }

    public function review(TempleSuggestionStatus $status, ?int $by, ?string $note = null, ?int $templeId = null): void
    {
        $this->forceFill([
            'status' => $status,
            'review_note' => $note,
            'temple_id' => $templeId ?? $this->temple_id,
            'reviewed_by' => $by,
            'reviewed_at' => now(),
        ])->save();
    }
}
