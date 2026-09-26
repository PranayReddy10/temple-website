<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TempleSuggestionPhoto extends Model
{
    protected $fillable = ['disk', 'path'];

    protected static function booted(): void
    {
        static::deleted(function (TempleSuggestionPhoto $photo): void {
            Storage::disk($photo->disk ?: config('filesystems.media'))->delete($photo->path);
        });
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(TempleSuggestion::class, 'temple_suggestion_id');
    }

    public function url(): ?string
    {
        return MediaUrl::for($this->disk, $this->path);
    }
}
