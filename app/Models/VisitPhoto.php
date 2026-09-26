<?php

namespace App\Models;

use App\Support\MediaUrl;
use App\Enums\PhotoModerationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A devotee's photo from a visit, and the memory card generated from it.
 */
class VisitPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'devotee_id', 'temple_id', 'devotee_visit_id', 'kind',
        'disk', 'original_path', 'stamp_path', 'caption',
        'status', 'moderated_by', 'moderated_at', 'moderation_note', 'is_public',
    ];

    /** The photo in the passport, shareable once approved. */
    public const KIND_STAMP = 'stamp';

    /** One of up to three kept with a visit, seen only by the devotee. */
    public const KIND_MEMORY = 'memory';

    /** Memory photos a single visit may hold. */
    public const MEMORIES_PER_VISIT = 3;

    protected $attributes = [
        'kind' => self::KIND_STAMP,
        'status' => 'pending',
        'is_public' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => PhotoModerationStatus::class,
            'moderated_at' => 'datetime',
            'is_public' => 'boolean',
        ];
    }

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class);
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(DevoteeVisit::class, 'devotee_visit_id');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /** The gallery photo this was promoted into, once staff did so. */
    public function promotedPhoto(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TemplePhoto::class, 'visit_photo_id');
    }

    /** Approved, shared, and not a memory: fit to be offered to the temple's gallery. */
    public function canBePromoted(): bool
    {
        return $this->isVisibleToOthers() && $this->promotedPhoto === null;
    }

    // --- Scopes ---

    /**
     * Memory photos never wait on a moderator: nobody but their owner can
     * ever see them, so there is nothing to approve.
     */
    public function scopeAwaitingModeration(Builder $query): Builder
    {
        return $query->where('status', PhotoModerationStatus::Pending)->where('kind', self::KIND_STAMP);
    }

    public function scopeStamps(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_STAMP);
    }

    public function scopeMemories(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_MEMORY);
    }

    /**
     * Photos anyone else may see.
     *
     * Both conditions, always, and in one place: approval is the moderators'
     * decision and is_public is the devotee's, and either one saying no is a
     * no. A caller that checks only the status leaks a private photo.
     */
    public function scopeVisibleToOthers(Builder $query): Builder
    {
        return $query->where('status', PhotoModerationStatus::Approved)
            ->where('is_public', true)
            ->where('kind', self::KIND_STAMP);
    }

    // --- Helpers ---

    public function isVisibleToOthers(): bool
    {
        return $this->status->allowsPublication() && $this->is_public && ! $this->isMemory();
    }

    public function isMemory(): bool
    {
        return $this->kind === self::KIND_MEMORY;
    }

    public function originalUrl(): ?string
    {
        return $this->urlFor($this->original_path);
    }

    /** The generated card, falling back to the original until one exists. */
    public function stampUrl(): ?string
    {
        return $this->urlFor($this->stamp_path) ?? $this->originalUrl();
    }

    public function hasStamp(): bool
    {
        return filled($this->stamp_path);
    }

    protected function urlFor(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return MediaUrl::for($this->disk, $path);
    }
}
