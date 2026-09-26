<?php

namespace App\Http\Resources\V1;

use App\Models\TempleReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A devotee's account of a visit, as others see it, or as its author does
 * (who also sees where it stands with the moderators).
 *
 * @mixin TempleReview
 */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user('devotee');
        $isAuthor = $viewer !== null && $viewer->getKey() === $this->devotee_id;

        return [
            'id' => $this->id,
            'temple' => $this->whenLoaded('temple', fn () => [
                'slug' => $this->temple->slug,
                'name' => $this->temple->name,
                'city' => $this->temple->city,
            ]),
            'devotee' => [
                // A first name and an initial: enough to be a person, not enough to be found.
                'name' => self::shortName($this->devotee?->name),
                'avatar_url' => $this->devotee?->avatarUrl(),
                'home_state' => $this->devotee?->homeState?->name,
            ],
            'visited_on' => $this->visited_on?->toDateString(),
            'ratings' => collect(TempleReview::DIMENSIONS)->map(fn (array $meta, string $key): array => [
                'key' => $key,
                'label' => $meta['label'],
                'value' => $this->{$key},
            ])->values()->all(),
            'wait_minutes' => $this->wait_minutes,
            'body' => $this->body,
            'temple_reply' => $this->temple_reply,
            'temple_replied_at' => $this->temple_replied_at?->toIso8601String(),
            'is_mine' => $isAuthor,
            // Moderation state is the author's business, and silence reads as failure.
            'status' => $this->when($isAuthor, fn () => [
                'value' => $this->status->value,
                'label' => $this->status->getLabel(),
            ]),
            'moderation_note' => $this->when($isAuthor, fn () => $this->moderation_note),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public static function shortName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $first = $parts[0] ?? 'A devotee';
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1).'.' : null;

        return $first === '' ? 'A devotee' : trim($first.' '.$last);
    }
}
