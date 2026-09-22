<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One field of one record in one language.
 */
class Translation extends Model
{
    use HasFactory;

    protected $fillable = [
        'translatable_type', 'translatable_id',
        'locale', 'field', 'value', 'is_reviewed', 'reviewed_by',
    ];

    protected $attributes = [
        'is_reviewed' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_reviewed' => 'boolean',
        ];
    }

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeReviewed(Builder $query): Builder
    {
        return $query->where('is_reviewed', true);
    }

    public function scopeForLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', $locale);
    }

    /** A blank translation is not a translation; it is a row someone left. */
    public function isUsable(): bool
    {
        return filled($this->value);
    }

    public function localeName(): string
    {
        return config("locales.supported.{$this->locale}.native")
            ?? config("locales.supported.{$this->locale}.name")
            ?? $this->locale;
    }
}
