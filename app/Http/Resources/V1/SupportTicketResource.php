<?php

namespace App\Http\Resources\V1;

use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket as the person who raised it sees it.
 *
 * Built on the `replies` relation rather than `messages`, so an internal note
 * cannot reach this response even if somebody later eager-loads the wrong
 * one — the filter is in the relation, not in a condition here that a future
 * caller might not repeat.
 *
 * @mixin \App\Models\SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // The handle somebody without an account quotes back at us.
            'reference' => $this->reference,

            'kind' => $this->kind?->value,
            'category' => [
                'value' => $this->category?->value,
                'label' => $this->category?->getLabel(),
            ],

            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->getLabel(),
                'is_open' => $this->isOpen(),
            ],

            'subject' => $this->subject,
            'body' => $this->body,

            'about' => $this->about_type === null ? null : [
                'type' => class_basename($this->about_type),
                'id' => $this->about_id,
                'label' => $this->aboutLabel(),
            ],

            // Only present once staff have written one, which is the signal
            // the app uses to say the ticket has been answered.
            'resolution' => $this->when(
                filled($this->resolution_note),
                fn (): ?string => $this->resolution_note,
            ),

            'messages' => $this->whenLoaded('replies', fn () => $this->replies
                ->map(fn (SupportTicketMessage $message): array => [
                    'id' => $message->id,
                    'body' => $message->body,
                    'from_staff' => $message->isFromStaff(),
                    'author' => $message->authorName(),
                    'created_at' => $message->created_at?->toIso8601String(),
                ])
                ->values()),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
