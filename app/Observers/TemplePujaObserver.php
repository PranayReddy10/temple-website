<?php

namespace App\Observers;

use App\Models\TemplePayoutAccount;
use App\Models\TemplePuja;
use App\Support\ActingStaff;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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

    /**
     * Bookings are money records: a seva devotees booked is unpublished,
     * never deleted with their bookings. Same rule as the trust app and
     * temple events, enforced here so every path (admin, portal, bulk) obeys.
     */
    public function deleting(TemplePuja $puja): void
    {
        if ($puja->bookings()->exists()) {
            throw ValidationException::withMessages(['puja' => 'Devotees have booked this seva. Unpublish it instead of deleting it.']);
        }
    }

    /** Deleting the row deletes its image, so object storage stays tidy. */
    public function deleted(TemplePuja $puja): void
    {
        // Only the seva's own temple's image folder: a path naming another
        // temple's file is left alone.
        if (filled($puja->image_path)
            && str_starts_with($puja->image_path, 'pujas/'.$puja->temple_id.'/')
            && ! str_contains($puja->image_path, '..')) {
            Storage::disk($puja->image_disk ?? config('filesystems.media'))
                ->delete($puja->image_path);
        }
    }

    public function saving(TemplePuja $puja): void
    {
        // Paid booking in the app takes money: a temple team may switch it
        // on only once the owner and the bank details are approved. The
        // trust app checks this too; here the portal (and anything else a
        // team uses) obeys the same rule.
        if (ActingStaff::user()?->isTempleAdmin()
            && $puja->isDirty(['app_booking_enabled', 'is_free', 'fee_amount'])
            && $puja->app_booking_enabled && ! $puja->is_free && $puja->fee_amount !== null
            && ! ($puja->temple?->canCollectPayments() ?? false)) {
            throw ValidationException::withMessages(['app_booking_enabled' => TemplePayoutAccount::NOT_APPROVED_MESSAGE]);
        }

        // "Official booking" is a claim about a URL. With no URL there is no
        // claim to make, so the flag cannot stand on its own.
        if (blank($puja->booking_url)) {
            $puja->booking_is_official = false;
        }

        // A free puja has no fee amount; keeping one would render as both.
        if ($puja->is_free) {
            $puja->fee_amount = null;
        }

        // Booking in the app charges the published fee. With no published
        // fee and no "free", there is nothing to charge and nothing to
        // confirm, so the switch cannot stay on.
        if ($puja->app_booking_enabled && ! $puja->is_free && $puja->fee_amount === null) {
            $puja->app_booking_enabled = false;
        }

        if ($puja->booking_capacity_per_day !== null && (int) $puja->booking_capacity_per_day < 1) {
            $puja->booking_capacity_per_day = null;
        }
    }
}
