<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A devotee who has said they are coming to a seva drive. */
class SevaDriveVolunteer extends Model
{
    protected $fillable = ['devotee_id', 'party_size', 'note', 'attended'];

    protected function casts(): array
    {
        return [
            'party_size' => 'integer',
            'attended' => 'boolean',
        ];
    }

    public function drive(): BelongsTo
    {
        return $this->belongsTo(SevaDrive::class, 'seva_drive_id');
    }

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class);
    }
}
