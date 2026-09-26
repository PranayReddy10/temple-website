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
        $found = PincodeLookup::find($pincode);

        if ($found === null) {
            return response()->json(['message' => 'No post office has that PIN code.'], 404);
        }

        return response()->json(['data' => $found]);
    }
}
