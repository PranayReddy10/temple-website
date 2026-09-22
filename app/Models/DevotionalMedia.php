<?php

namespace App\Models;

use App\Enums\DevotionalMediaType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DevotionalMedia extends Model
{
    use HasFactory;

    protected $table = 'devotional_media';

    protected $fillable = [
        'devotional_day_id', 'type', 'title', 'description',
        'source_type', 'disk', 'path', 'external_url', 'thumbnail_path',
        'artist', 'credit', 'license', 'license_url',
        'duration_seconds', 'sort_order', 'is_published',
    ];

    protected $attributes = [
        'type' => 'song',
        'source_type' => 'external',
        // Unpublished by default: a recording should not reach devotees until
        // someone has confirmed we may publish it.
        'is_published' => false,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => DevotionalMediaType::class,
            'duration_seconds' => 'integer',
            'sort_order' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(DevotionalDay::class, 'devotional_day_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    // --- Rights ---

    /**
     * Whether this may be published.
     *
     * A song or video needs a recorded licence; without one we have no basis
     * to put it in front of devotees, whatever the composition's age. This is
     * the same rule as the puja booking route: the claim must be explicit.
     */
    public function mayBePublished(): bool
    {
        if (! $this->type?->requiresLicense()) {
            return true;
        }

        return filled($this->license);
    }

    public function licenseSummary(): string
    {
        if (! $this->type?->requiresLicense()) {
            return $this->license ?: 'No licence recorded';
        }

        return $this->license ?: 'Missing — cannot be published';
    }

    // --- Playback ---

    public function url(): ?string
    {
        if ($this->source_type === 'external') {
            return $this->external_url;
        }

        if (blank($this->path)) {
            return null;
        }

        return Storage::disk($this->disk ?? config('filesystems.media'))->url($this->path);
    }

    public function thumbnailUrl(): ?string
    {
        if (blank($this->thumbnail_path)) {
            return null;
        }

        return Storage::disk($this->disk ?? config('filesystems.media'))->url($this->thumbnail_path);
    }

    public function durationLabel(): ?string
    {
        if ($this->duration_seconds === null) {
            return null;
        }

        $minutes = intdiv($this->duration_seconds, 60);
        $seconds = $this->duration_seconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }
}
