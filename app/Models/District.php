<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    use HasFactory;

    protected $fillable = ['state_id', 'name', 'slug'];

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function temples(): HasMany
    {
        return $this->hasMany(Temple::class);
    }
}
