<?php

namespace App\Models\Concerns;

use App\Models\DevotionalMedia;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mantra: its text, its transliteration, and the recording of it.
 *
 * Three models carry one — a deity, a temple and a weekday — and all three
 * fall back the same way, so the rules live here rather than being written
 * out three times and drifting.
 *
 * The chain is most-specific-first and it applies field by field, not
 * all-or-nothing. A temple that has its own verse but no recording should
 * show its verse and play its deity's chant, not fall back wholesale to the
 * deity because one of the three was missing.
 */
trait HasMantra
{
    public function mantraRecordingMedia(): BelongsTo
    {
        return $this->belongsTo(DevotionalMedia::class, 'mantra_media_id');
    }

    /**
     * Where to look next when this record has nothing.
     *
     * A temple and a weekday both defer to their deity; a deity is the end of
     * the line. Overridable, because the chain is about the model and not
     * about this trait.
     */
    protected function mantraFallback(): ?object
    {
        return property_exists($this, 'deity') || method_exists($this, 'deity')
            ? $this->deity
            : null;
    }

    public function mantraText(): ?string
    {
        return filled($this->mantra)
            ? $this->mantra
            : $this->mantraFallback()?->mantra;
    }

    public function mantraTransliteration(): ?string
    {
        return filled($this->mantra_transliteration)
            ? $this->mantra_transliteration
            : $this->mantraFallback()?->mantra_transliteration;
    }

    public function mantraMeaning(): ?string
    {
        $own = $this->mantra_meaning ?? null;

        return filled($own) ? $own : ($this->mantraFallback()->mantra_meaning ?? null);
    }

    /**
     * The recording to play, or nothing.
     *
     * Unpublished media is never returned: it is either unfinished or waiting
     * on rights we have not confirmed, and the mantra pointer is not a way
     * around the rule the rest of the media goes through.
     */
    public function mantraRecording(): ?DevotionalMedia
    {
        $own = $this->relationLoaded('mantraRecordingMedia')
            ? $this->mantraRecordingMedia
            : $this->mantraRecordingMedia()->first();

        if ($own !== null && $own->is_published) {
            return $own;
        }

        $fallback = $this->mantraFallback();

        if ($fallback === null || ! method_exists($fallback, 'mantraRecording')) {
            return null;
        }

        return $fallback->mantraRecording();
    }

    public function hasMantra(): bool
    {
        return filled($this->mantraText());
    }

    public function hasMantraRecording(): bool
    {
        return $this->mantraRecording() !== null;
    }

    /** Whether what is shown here is this record's own rather than inherited. */
    public function mantraIsOwn(): bool
    {
        return filled($this->mantra);
    }
}
