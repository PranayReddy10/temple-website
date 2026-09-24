<?php

namespace App\Support\Push;

use App\Models\AppNotification;
use App\Models\DevoteeDevice;
use Throwable;

/**
 * Sends an app notification: marks it sent, which puts it in every matching
 * inbox, and pushes it to phones if push is set up.
 *
 * Broad audiences go to Firebase topics the app subscribes to — "all", its
 * platform, "temple-{id}" for each saved temple and "state-{id}" for its home
 * state — so one request reaches millions. One devotee's goes to each of
 * their devices' tokens.
 */
class NotificationSender
{
    public function __construct(protected FcmClient $fcm) {}

    public function send(AppNotification $notification): AppNotification
    {
        $pushed = 0;
        $error = null;

        if ($this->fcm->isConfigured()) {
            try {
                $pushed = $this->push($notification);
            } catch (Throwable $e) {
                // The inbox copy still goes out; the failure is recorded for
                // the person who pressed Send.
                $error = $e->getMessage();
            }
        }

        $notification->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'push_count' => $pushed,
            'last_error' => $error,
        ])->save();

        return $notification;
    }

    protected function push(AppNotification $n): int
    {
        $data = array_filter([
            'notification_id' => (string) $n->getKey(),
            'link_type' => $n->link_type,
            'link_value' => (string) $n->link_value,
        ], fn ($v) => $v !== '');

        if ($n->audience === 'devotee') {
            $sent = 0;

            DevoteeDevice::query()->where('devotee_id', $n->audience_id)->each(function (DevoteeDevice $device) use ($n, $data, &$sent): void {
                if ($this->fcm->send(['token' => $device->token], $n->title, $n->body, $n->image_url, $data)) {
                    $sent++;
                } else {
                    $device->delete(); // uninstalled or signed out
                }
            });

            return $sent;
        }

        $target = match ($n->audience) {
            'platform' => ['topic' => in_array($n->platform, ['android', 'ios'], true) ? $n->platform : 'all'],
            'temple' => ['topic' => 'temple-'.(int) $n->audience_id],
            'state' => ['topic' => 'state-'.(int) $n->audience_id],
            default => ['topic' => 'all'],
        };

        $this->fcm->send($target, $n->title, $n->body, $n->image_url, $data);

        return 1;
    }
}
