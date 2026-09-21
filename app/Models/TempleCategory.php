<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TempleCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'kind', 'description', 'expected_count', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expected_count' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function temples(): BelongsToMany
    {
        return $this->belongsToMany(Temple::class, 'temple_category_temple')
            ->withTimestamps();
    }
}
