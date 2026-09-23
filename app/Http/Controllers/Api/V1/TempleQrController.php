<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TempleStatus;
use App\Http\Controllers\Controller;
use App\Support\TempleQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tells the app whether a scanned code is one we issued, before it stamps
 * anything on the strength of it.
 */
class TempleQrController extends Controller
{
    public function verify(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:500']]);

        $check = TempleQr::verify($request->input('code'));
        $temple = $check['temple'];

        // An unpublished temple's code is still ours, but there is nothing in
        // the app to open for it, so it is not offered as a check-in.
        $published = $temple !== null && $temple->status === TempleStatus::Published;

        return response()->json([
            'data' => [
                'valid' => $check['valid'] && $published,
                'reason' => $check['valid'] && ! $published ? 'This temple is not published yet.' : $check['reason'],
                'temple' => $published ? [
                    'id' => $temple->getKey(),
                    'slug' => $temple->slug,
                    'name' => $temple->name,
                    'city' => $temple->city,
                ] : null,
            ],
        ]);
    }
}
