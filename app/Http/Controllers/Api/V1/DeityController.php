<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TempleStatus;
use App\Http\Controllers\Controller;
use App\Models\Deity;
use Illuminate\Http\JsonResponse;

class DeityController extends Controller
{
    public function index(): JsonResponse
    {
        $deities = Deity::query()
            ->where('is_active', true)
            // Counts only published temples, so the number matches what a
            // devotee actually sees after tapping through.
            ->withCount(['temples' => fn ($q) => $q->where('status', TempleStatus::Published)])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Deity $deity): array => [
                'slug' => $deity->slug,
                'name' => $deity->name,
                'alternate_names' => $deity->alternate_names,
                'description' => $deity->description,
                'temple_count' => $deity->temples_count,
                // Uploaded in the admin, or a public-domain painting fetched
                // by deities:fetch-images. The app draws the deity's emblem
                // when this is null.
                'image_url' => $deity->imageUrl(),
                'image_credit' => $deity->image_credit,
            ]);

        return response()->json(['data' => $deities]);
    }
}
