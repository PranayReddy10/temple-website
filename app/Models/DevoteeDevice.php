<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An install that can receive a push: its Firebase token, platform and
 * version. Keyed by a hash of the token so the unique index stays short
 * whatever length the token is.
 */
class DevoteeDevice extends Model
{
    protected $fillable = ['devotee_id', 'token', 'token_hash', 'platform', 'app_version', 'locale', 'last_seen_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class);
    }
}
