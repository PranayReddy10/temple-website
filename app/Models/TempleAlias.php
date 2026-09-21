<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TempleAlias extends Model
{
    use HasFactory;

    protected $table = 'temple_aliases';

    protected $fillable = ['temple_id', 'name', 'locale'];

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }
}
