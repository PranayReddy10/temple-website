<?php

namespace App\Models;

use App\Models\Concerns\HasMantra;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Storage;

class Deity extends Model
{
    use HasFactory, HasMantra, HasTranslations;

    /** @var array<int, string> */
    protected array $translatable = [
        'name',
        'description',
        'mantra_transliteration',
        'mantra_meaning',
    ];

    protected $fillable = [
        'name', 'slug', 'alternate_names', 'description',
        'image_disk', 'image_path', 'image_credit',
        'mantra', 'mantra_transliteration', 'mantra_meaning', 'mantra_media_id', 'accent_color',
        'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function temples(): HasMany
    {
        return $this->hasMany(Temple::class);
    }

    public function devotionalDays(): HasMany
    {
        return $this->hasMany(DevotionalDay::class);
    }

    /**
     * Songs, chants and videos that belong to this deity rather than to one
     * weekday. The same aarti is sung wherever this deity is worshipped.
     */
    public function media(): MorphMany
    {
        return $this->morphMany(DevotionalMedia::class, 'mediable')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** A deity is the end of the chain: there is nothing above it to inherit from. */
    protected function mantraFallback(): ?object
    {
        return null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function imageUrl(): ?string
    {
        if (blank($this->image_path)) {
            return null;
        }

        return Storage::disk($this->image_disk ?? config('filesystems.media'))
            ->url($this->image_path);
    }

    /**
     * The colour this deity's day is tinted with.
     *
     * Saffron rather than nothing, so a deity added without one still renders
     * a themed screen instead of an unstyled gap.
     */
    public function accentColor(): string
    {
        return $this->accent_color ?: config('brand.colors.saffron.hex');
    }
}
