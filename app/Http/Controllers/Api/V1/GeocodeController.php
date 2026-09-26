<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Geocoder;
use App\Support\GeocoderUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The address under a dropped pin, so a devotee standing at a temple can
 * give its location and have the PIN code, village, district and state
 * filled in for them.
 */
class GeocodeController extends Controller
{
    public function reverse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        try {
            $found = Geocoder::reverse((float) $validated['lat'], (float) $validated['lng']);
        } catch (GeocoderUnavailable) {
            return response()->json(['message' => 'The map could not be reached. Fill in the address yourself for now.'], 503);
        }

        if ($found === null) {
            return response()->json(['message' => 'Nothing is on the map at that spot.'], 404);
        }

        return response()->json(['data' => $found]);
    }
}
