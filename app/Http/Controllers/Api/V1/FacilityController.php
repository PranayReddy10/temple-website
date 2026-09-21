<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use Illuminate\Http\JsonResponse;

class FacilityController extends Controller
{
    public function index(): JsonResponse
    {
        $facilities = Facility::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Facility $facility): array => [
                'slug' => $facility->slug,
                'name' => $facility->name,
                'group' => $facility->group,
                'icon' => $facility->icon,
            ]);

        return response()->json(['data' => $facilities]);
    }
}
