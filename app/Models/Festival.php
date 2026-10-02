<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A festival or vrat day kept across India (or a region of it), shown in
 * every devotee's calendar. Dates come from the panchang computation and
 * an editor may correct any one.
 */
class Festival extends Model
{
    public const KINDS = ['festival' => 'Festival', 'vrat' => 'Vrat / monthly observance'];

    protected $fillable = [
        'slug', 'name', 'starts_on', 'ends_on', 'kind', 'is_major', 'deity',
        'description', 'tithi', 'is_published', 'computed_on',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'computed_on' => 'date',
            'is_major' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('starts_on', '<=', $to)
            ->where(fn (Builder $q) => $q->whereDate('starts_on', '>=', $from)->orWhereDate('ends_on', '>=', $from));
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->getKey(),
            'slug' => $this->slug,
            'name' => $this->name,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => ($this->ends_on ?? $this->starts_on)?->toDateString(),
            'kind' => $this->kind,
            'is_major' => $this->is_major,
            'deity' => $this->deity,
            'description' => $this->description,
            'tithi' => $this->tithi,
        ];
    }
}
