<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TempleStatus;
use App\Http\Controllers\Controller;
use App\Models\TempleCategory;
use Illuminate\Http\JsonResponse;

class TempleCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = TempleCategory::query()
            ->where('is_active', true)
            ->withCount(['temples' => fn ($q) => $q->where('status', TempleStatus::Published)])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (TempleCategory $category): array => [
                'slug' => $category->slug,
                'name' => $category->name,
                'kind' => $category->kind,
                'description' => $category->description,
                'temple_count' => $category->temples_count,
                // Lets the app show "9 of 12 Jyotirlingas recorded" rather than
                // implying the circuit is complete.
                'expected_count' => $category->expected_count,
            ]);

        return response()->json(['data' => $categories]);
    }
}
