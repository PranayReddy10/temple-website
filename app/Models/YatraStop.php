<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One temple on a planned pilgrimage.
 *
 * The link to a visit is what closes the loop between planning and the
 * Passport: a stop is done when the check-in it was planning for exists.
 */
class YatraStop extends Model
{
    use HasFactory;

    protected $fillable = [
        'yatra_id', 'temple_id', 'day_number', 'sort_order',
        'planned_on', 'note', 'devotee_visit_id',
    ];

    protected $attributes = [
        'day_number' => 1,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'planned_on' => 'date',
            'day_number' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function yatra(): BelongsTo
    {
        return $this->belongsTo(Yatra::class);
    }

    public function temple(): BelongsTo
    {
        return $this->belongsTo(Temple::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(DevoteeVisit::class, 'devotee_visit_id');
    }

    public function isVisited(): bool
    {
        return $this->devotee_visit_id !== null;
    }
}
