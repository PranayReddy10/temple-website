<?php

namespace App\Models;

use App\Models\Concerns\MarksTempleChanged;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TempleAlias extends Model
{
    use HasFactory, MarksTempleChanged;

    protected $table = 'temple_aliases';

    protected $fillable = ['temple_id', 'name', 'locale'];

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }
}
