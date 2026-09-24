<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message from the team to devotees: pushed to phones when push is set up,
 * and always kept in the app's notification inbox.
 */
class AppNotification extends Model
{
    public const AUDIENCES = [
        'all' => 'Everyone',
        'platform' => 'One platform',
        'temple' => 'Devotees who saved a temple',
        'state' => 'Devotees from a home state',
        'devotee' => 'One devotee',
    ];

    public const LINKS = [
        'none' => 'Just open the app',
        'temple' => 'A temple page (slug)',
        'day' => 'A weekday page (0 = Sunday … 6)',
        'screen' => 'An app screen',
        'url' => 'A web link',
    ];

    public const SCREENS = [
        'passport' => 'Passport', 'yatra' => 'Yatra planner', 'calendar' => 'Festival calendar',
        'premium' => 'Premium plans', 'notifications' => 'Notifications',
    ];

    protected $fillable = [
        'title', 'body', 'image_url', 'link_type', 'link_value',
        'audience', 'audience_id', 'platform', 'status', 'scheduled_at', 'created_by',
    ];

    protected $attributes = [
        'link_type' => 'none',
        'audience' => 'all',
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AppNotificationRead::class);
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    /** Scheduled ones whose time has come. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', 'scheduled')->where('scheduled_at', '<=', now());
    }

    /**
     * What this person's inbox holds: sent notifications addressed to
     * everyone, their platform, a temple they saved, their home state, or
     * them. A guest sees only the first two.
     */
    public function scopeVisibleTo(Builder $query, ?Devotee $devotee, ?string $platform): Builder
    {
        return $query->sent()->where(function (Builder $q) use ($devotee, $platform): void {
            $q->where('audience', 'all');

            if ($platform !== null) {
                $q->orWhere(fn (Builder $p) => $p->where('audience', 'platform')->where('platform', $platform));
            }

            if ($devotee !== null) {
                $q->orWhere(fn (Builder $p) => $p->where('audience', 'devotee')->where('audience_id', $devotee->getKey()));

                if ($devotee->home_state_id !== null) {
                    $q->orWhere(fn (Builder $p) => $p->where('audience', 'state')->where('audience_id', $devotee->home_state_id));
                }

                $q->orWhere(fn (Builder $p) => $p->where('audience', 'temple')
                    ->whereIn('audience_id', $devotee->savedTemples()->select('temples.id')));
            }
        });
    }
}
