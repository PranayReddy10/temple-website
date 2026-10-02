<?php

namespace App\Http\Controllers;

use App\Models\EventRegistration;
use App\Models\PujaBooking;
use Illuminate\View\View;

/**
 * Where a booking's code leads when scanned with an ordinary phone camera
 * rather than the temple's scanner: what was booked, for when, and whether
 * it stands. Nothing here verifies anything; that needs the portal.
 */
class BookingPageController extends Controller
{
    public function __invoke(string $code): View
    {
        $booking = PujaBooking::findByCode($code)?->load(['puja', 'temple', 'verifier']);

        return view('booking', [
            'booking' => $booking,
            // Event tickets carry the same kind of code.
            'ticket' => $booking === null ? EventRegistration::findByCode($code)?->load(['event', 'temple', 'verifier']) : null,
        ]);
    }
}
