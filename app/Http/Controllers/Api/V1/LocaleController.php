<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Locales;
use Illuminate\Http\JsonResponse;

/**
 * The language list, served rather than compiled into the app.
 *
 * A released build cannot be updated on demand, so a language picker with a
 * hard-coded list means every new language waits for an app store review.
 * This way enabling Kannada is a config change.
 */
class LocaleController extends Controller
{
    public function index(): JsonResponse
    {
        $launch = Locales::launch();

        return response()->json([
            'data' => [
                'current' => app()->getLocale(),
                'fallback' => Locales::fallback(),
                'languages' => collect(Locales::supported())
                    ->map(fn (array $language, string $code): array => [
                        'code' => $code,
                        'name' => $language['name'],
                        'native_name' => $language['native'],
                        'rtl' => $language['rtl'],
                        // Whether the app should offer it today, as opposed to
                        // whether the schema can hold it. A language offered
                        // and then mostly blank reads as neglect.
                        'is_available' => in_array($code, $launch, true),
                    ])
                    ->values(),
            ],
        ]);
    }
}
