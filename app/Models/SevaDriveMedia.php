<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A photograph or video of a seva drive's place, before or after. */
class SevaDriveMedia extends Model
{
    public const STAGE_BEFORE = 'before';

    public const STAGE_AFTER = 'after';

    public const TYPE_PHOTO = 'photo';

    public const TYPE_VIDEO = 'video';

    /** Per stage, so a drive's page stays a page and not an album. */
    public const MAX_PER_STAGE = 8;

    protected $table = 'seva_drive_media';

    protected $fillable = ['devotee_id', 'stage', 'type', 'disk', 'path', 'video_url', 'caption'];

    protected $attributes = [
        'stage' => self::STAGE_BEFORE,
        'type' => self::TYPE_PHOTO,
        'is_hidden' => false,
    ];

    protected function casts(): array
    {
        return ['is_hidden' => 'boolean'];
    }

    protected static function booted(): void
    {
        // The file goes with the row; nothing else points at it.
        static::deleted(function (SevaDriveMedia $media): void {
            if (filled($media->path)) {
                Storage::disk($media->disk ?: config('filesystems.media'))->delete($media->path);
            }
        });
    }

    public function drive(): BelongsTo
    {
        return $this->belongsTo(SevaDrive::class, 'seva_drive_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    public function url(): ?string
    {
        return filled($this->path) ? MediaUrl::for($this->disk, $this->path) : $this->video_url;
    }
}
