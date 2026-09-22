<?php

namespace App\Observers;

use App\Models\TemplePuja;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps the fee and booking fields internally consistent.
 *
 * Section 13 of the project plan is explicit that an unofficial booking route
 * must never be presented as official, and section 20 that an unknown price
 * must never read as free. The form already guides an editor towards both, but
 * the form is only the user interface — these invariants are enforced on every
 * write, including seeders, imports and tinker.
 */
class TemplePujaObserver
{
    public function creating(TemplePuja $puja): void
    {
        $puja->image_disk ??= config('filesystems.media');
    }

    /** Deleting the row deletes its image, so object storage stays tidy. */
    public function deleted(TemplePuja $puja): void
    {
        if (filled($puja->image_path)) {
            Storage::disk($puja->image_disk ?? config('filesystems.media'))
                ->delete($puja->image_path);
        }
    }

    public function saving(TemplePuja $puja): void
    {
        // "Official booking" is a claim about a URL. With no URL there is no
        // claim to make, so the flag cannot stand on its own.
        if (blank($puja->booking_url)) {
            $puja->booking_is_official = false;
        }

        // A free puja has no fee amount; keeping one would render as both.
        if ($puja->is_free) {
            $puja->fee_amount = null;
        }
    }
}
