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
        'temple' => 'Followers of a temple',
        'temple_festival' => 'Followers of a temple who asked for festival reminders',
        'temple_event' => 'Followers of a temple who asked for event reminders',
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
        'audience', 'audience_id', 'platform', 'status', 'scheduled_at', 'created_by', 'source_key',
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

    /** The audiences a reminder uses, and the follow column each honours. */
    public const REMINDER_AUDIENCES = [
        'temple_festival' => 'notify_festivals',
        'temple_event' => 'notify_events',
    ];

    public function isReminder(): bool
    {
        return array_key_exists($this->audience, self::REMINDER_AUDIENCES);
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

                // Following is what asks to be told; saving is a bookmark.
                $q->orWhere(fn (Builder $p) => $p->where('audience', 'temple')
                    ->whereIn('audience_id', $devotee->follows()->select('temple_id')));

                // Reminders reach only followers who asked for that kind.
                $q->orWhere(fn (Builder $p) => $p->where('audience', 'temple_festival')
                    ->whereIn('audience_id', $devotee->follows()->where('notify_festivals', true)->select('temple_id')));
                $q->orWhere(fn (Builder $p) => $p->where('audience', 'temple_event')
                    ->whereIn('audience_id', $devotee->follows()->where('notify_events', true)->select('temple_id')));
            }
        });
    }
}
