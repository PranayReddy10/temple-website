<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AppConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the app reads on every launch. Open, so a signed-out app and one
 * whose token has expired both learn about maintenance and updates; a token,
 * if sent, only decides whether ads are shown.
 */
class AppConfigController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $platform = $request->query('platform') ?? $request->header('X-Platform');
        $version = $request->query('version') ?? $request->header('X-App-Version');

        return response()->json([
            'data' => AppConfig::for(is_string($platform) ? strtolower($platform) : null, is_string($version) ? $version : null, $request->user('devotee')),
        ]);
    }
}
