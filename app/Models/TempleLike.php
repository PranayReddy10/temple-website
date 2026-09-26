<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The lightest signal of interest: one tap, one row, nothing else implied. */
class TempleLike extends Model
{
    protected $fillable = ['devotee_id', 'temple_id'];

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class);
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }
}
