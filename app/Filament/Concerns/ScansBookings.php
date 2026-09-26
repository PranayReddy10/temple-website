<?php

namespace App\Filament\Concerns;

use App\Models\PujaBooking;
use App\Support\Bookings\PujaBookings;
use App\Support\BookingQr;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Scan a seva booking's code at the counter and receive the devotee.
 *
 * Only the code is kept on the page, never the booking's id: Livewire state
 * comes back from the browser on every request, and an id there could be
 * edited into another booking; a code has to have been shown to you. The
 * booking is looked up inside the temples this account manages (or all of
 * them, for staff), so a code from another temple reads as unknown.
 */
trait ScansBookings
{
    public string $code = '';

    #[Locked]
    public ?string $scannedCode = null;

    public ?string $error = null;

    /** verified | already_verified | null, after the button is pressed. */
    public ?string $outcome = null;

    public function scan(?string $scanned = null): void
    {
        if ($scanned !== null) {
            $this->code = $scanned;
        }

        $this->error = null;
        $this->outcome = null;
        $this->scannedCode = null;

        if (trim($this->code) === '') {
            $this->error = 'Scan a booking code, or type the reference from the devotee\'s screen.';

            return;
        }

        $token = BookingQr::parse($this->code);
        $booking = $token === null
            // A reference typed by hand, for a phone with a cracked screen.
            ? $this->bookingsQuery()->where('reference', strtoupper(trim($this->code)))->first()
            : $this->bookingsQuery()->where('code', $token)->first();

        if ($booking === null) {
            $this->error = $token === null && ! preg_match('/^[A-Za-z0-9]{6,16}$/', trim($this->code))
                ? 'That is not a seva booking code. Passport codes are scanned under Scan passport.'
                : 'No booking of yours matches this code. It may be for another temple, or it was cancelled and re-booked.';

            return;
        }

        $this->scannedCode = $booking->code;
        $this->code = '';

        // A code already used says so at once, before anyone presses anything.
        if ($booking->isVerified()) {
            $this->outcome = PujaBookings::ALREADY_VERIFIED;
        }
    }

    public function verify(): void
    {
        $booking = $this->scannedBooking();

        if ($booking === null) {
            $this->error = 'Scan the code again.';

            return;
        }

        try {
            $result = app(PujaBookings::class)->verify($booking, Auth::user());
        } catch (AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();

            return;
        }

        $this->outcome = $result['outcome'];
    }

    public function clearScan(): void
    {
        $this->scannedCode = null;
        $this->error = null;
        $this->outcome = null;
        $this->code = '';
    }

    protected function scannedBooking(): ?PujaBooking
    {
        return $this->scannedCode === null
            ? null
            : $this->bookingsQuery()->where('code', $this->scannedCode)->first();
    }

    /** Bookings this account may receive: its temples', or every temple's for staff. */
    protected function bookingsQuery(): Builder
    {
        $user = Auth::user();
        $query = PujaBooking::query()->with(['puja', 'temple', 'devotee', 'payment', 'verifier']);

        if ($user?->role?->isStaff() ?? false) {
            return $query;
        }

        return $query->whereIn('temple_id', $user?->approvedTempleIds() ?? []);
    }

    protected function getViewData(): array
    {
        return [
            'booking' => $this->scannedBooking(),
        ];
    }
}
