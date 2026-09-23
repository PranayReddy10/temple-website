<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\AppNotificationRead;
use App\Models\DevoteeDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The app's notification inbox, and registering a device for push.
 *
 * Both work signed out: a guest gets notices addressed to everyone. The
 * devotee guard is asked directly because these routes sit outside the auth
 * middleware.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $devotee = $request->user('devotee');
        $platform = in_array($request->query('platform'), ['android', 'ios', 'web'], true) ? $request->query('platform') : null;

        $page = AppNotification::query()
            ->visibleTo($devotee, $platform)
            ->when($devotee !== null, fn ($q) => $q->where('sent_at', '>=', $devotee->created_at->copy()->subDays(30)))
            ->orderByDesc('sent_at')
            ->paginate(30);

        $read = $devotee === null ? [] : AppNotificationRead::query()
            ->where('devotee_id', $devotee->getKey())
            ->whereIn('app_notification_id', $page->getCollection()->modelKeys())
            ->pluck('app_notification_id')
            ->all();

        return response()->json([
            'data' => $page->getCollection()->map(fn (AppNotification $n): array => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'image_url' => $n->image_url,
                'link' => ['type' => $n->link_type, 'value' => $n->link_value],
                'sent_at' => $n->sent_at?->toIso8601String(),
                // Null for a guest: read state is kept on the device then.
                'is_read' => $devotee === null ? null : in_array($n->id, $read, true),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        $devotee = $request->user();

        // Only one addressed to this devotee (any platform's counts: the
        // request does not say which phone it came from).
        $visible = $notification->status === 'sent' && (
            $notification->audience === 'platform'
            || AppNotification::query()->whereKey($notification->getKey())->visibleTo($devotee, null)->exists()
        );
        abort_unless($visible, 404);

        AppNotificationRead::query()->firstOrCreate(
            ['app_notification_id' => $notification->getKey(), 'devotee_id' => $devotee->getKey()],
            ['read_at' => now()],
        );

        return response()->json(['data' => ['message' => 'Marked as read.']]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $devotee = $request->user();
        $platform = in_array($request->input('platform'), ['android', 'ios', 'web'], true) ? $request->input('platform') : null;

        $ids = AppNotification::query()->visibleTo($devotee, $platform)
            ->whereNotIn('id', AppNotificationRead::query()->where('devotee_id', $devotee->getKey())->select('app_notification_id'))
            ->pluck('id');

        AppNotificationRead::query()->insert($ids->map(fn (int $id): array => [
            'app_notification_id' => $id, 'devotee_id' => $devotee->getKey(), 'read_at' => now(),
        ])->all());

        return response()->json(['data' => ['marked' => $ids->count()]]);
    }

    /** Registers (or refreshes) this install's push token. */
    public function registerDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
            'app_version' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:10'],
        ]);

        $device = DevoteeDevice::query()->updateOrCreate(
            ['token_hash' => DevoteeDevice::hashToken($validated['token'])],
            [
                'token' => $validated['token'],
                'platform' => $validated['platform'],
                'app_version' => $validated['app_version'] ?? null,
                'locale' => $validated['locale'] ?? null,
                // Signing in claims the device; signing out does not release
                // it until the app says so with DELETE.
                'devotee_id' => $request->user('devotee')?->getKey(),
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['data' => ['id' => $device->id]], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function forgetDevice(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:512']]);

        DevoteeDevice::query()->where('token_hash', DevoteeDevice::hashToken($request->input('token')))->delete();

        return response()->json(['data' => ['message' => 'Device removed.']]);
    }
}
