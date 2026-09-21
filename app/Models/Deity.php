<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deity extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'alternate_names', 'description', 'sort_order', 'is_active',
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
}
