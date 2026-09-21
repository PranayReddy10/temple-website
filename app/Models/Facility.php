<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Facility extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'group', 'icon', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function temples(): BelongsToMany
    {
        return $this->belongsToMany(Temple::class)
            ->withPivot(['is_verified', 'note'])
            ->withTimestamps();
    }

    public function isAccessibility(): bool
    {
        return $this->group === 'accessibility';
    }
}
