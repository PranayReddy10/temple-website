<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A mantra as the app renders it: the verse, how to say it, and how to hear
 * it.
 *
 * Built from the owner — a deity, a temple or a weekday — rather than from a
 * table, because each field falls back independently. A temple with its own
 * verse but no recording shows its verse and plays its deity's chant; falling
 * back wholesale because one field was missing would show the wrong verse.
 *
 * The flags say where each half came from, because the app displays them
 * differently: "the mantra of this temple" and "the mantra of its deity" are
 * not the same claim, and a screen that blurs them is telling a devotee
 * something untrue about the place they are standing in.
 *
 * @mixin \App\Models\Deity|\App\Models\Temple|\App\Models\DevotionalDay
 */
class MantraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $recording = $this->resource->mantraRecording();

        return [
            'text' => $this->resource->mantraText(),
            'transliteration' => $this->resource->mantraTransliteration(),
            'meaning' => $this->resource->mantraMeaning(),

            // Whether the verse shown is this record's own or inherited.
            'is_own' => $this->resource->mantraIsOwn(),

            // Null rather than an empty object: a mantra with no recording is
            // the normal case, and the app should show the text alone rather
            // than a player with nothing in it.
            'audio' => $recording === null ? null : new DevotionalMediaResource($recording),
        ];
    }

    /** Nothing to say when there is no mantra at all. */
    public static function forOwner(object $owner): ?self
    {
        return $owner->hasMantra() ? new self($owner) : null;
    }
}
