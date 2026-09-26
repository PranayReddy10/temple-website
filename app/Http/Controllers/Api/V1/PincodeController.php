<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\PincodeLookup;
use Illuminate\Http\JsonResponse;

/** The state, district and towns for a PIN code, to fill in an address. */
class PincodeController extends Controller
{
    public function show(string $pincode): JsonResponse
    {
        $lookup = PincodeLookup::resolve($pincode);

        if ($lookup['found'] === null) {
            // A directory we could not reach is not a verdict on the code.
            // The app says so, and offers the pin on the map instead.
            if (! $lookup['reachable']) {
                return response()->json(['message' => 'The PIN code directory could not be reached. Drop a pin where you are, or fill in the address yourself.'], 503);
            }

            return response()->json(['message' => 'No post office has that PIN code.'], 404);
        }

        return response()->json(['data' => $lookup['found']]);
    }
}
