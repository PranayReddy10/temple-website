<?php

namespace App\Http\Controllers;

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
        return view('booking', [
            'booking' => PujaBooking::findByCode($code)?->load(['puja', 'temple', 'verifier']),
        ]);
    }
}
