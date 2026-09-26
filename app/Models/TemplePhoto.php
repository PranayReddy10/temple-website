<?php

namespace App\Models;

use App\Support\MediaUrl;
use App\Enums\PhotoCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TemplePhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'temple_id', 'disk', 'path', 'medium_path', 'thumbnail_path',
        'category', 'caption', 'credit', 'source_url', 'license',
        'is_primary', 'is_published', 'sort_order',
        'width', 'height', 'size_bytes', 'mime_type', 'uploaded_by',
        'devotee_id', 'visit_photo_id', 'temple_objected_at', 'temple_objection',
    ];

    protected function casts(): array
    {
        return [
            'category' => PhotoCategory::class,
            'is_primary' => 'boolean',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'temple_objected_at' => 'datetime',
        ];
    }

    /** The devotee whose Photo Stamp this was promoted from, if any. */
    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class);
    }

    public function visitPhoto(): BelongsTo
    {
        return $this->belongsTo(VisitPhoto::class);
    }

    /** Came from a devotee rather than the editors or the temple. */
    public function isDevoteePhoto(): bool
    {
        return $this->visit_photo_id !== null || $this->devotee_id !== null;
    }

    public function templeObjected(): bool
    {
        return $this->temple_objected_at !== null;
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    // --- URLs ---

    public function url(): ?string
    {
        return $this->urlFor($this->path);
    }

    public function mediumUrl(): ?string
    {
        return $this->urlFor($this->medium_path ?? $this->path);
    }

    public function thumbnailUrl(): ?string
    {
        return $this->urlFor($this->thumbnail_path ?? $this->medium_path ?? $this->path);
    }

    /**
     * Resolves against the disk recorded on the row, not the currently
     * configured media disk, so photos uploaded before a move to Spaces keep
     * working afterwards.
     */
    protected function urlFor(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return MediaUrl::for($this->disk, $path);
    }

    /** Every stored variant, for deleting the whole set at once. */
    public function storedPaths(): array
    {
        return array_values(array_filter([
            $this->path,
            $this->medium_path,
            $this->thumbnail_path,
        ]));
    }
}
