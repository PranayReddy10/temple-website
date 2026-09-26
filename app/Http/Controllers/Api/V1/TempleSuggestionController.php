<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TempleSuggestionResource;
use App\Models\TempleSuggestion;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * "My temple is not listed": a devotee, or somebody from the temple itself,
 * adds it from the app. It waits for the editors; nothing here publishes.
 */
class TempleSuggestionController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'roles' => collect(TempleSuggestion::ROLES)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values(),
            'max_photos' => TempleSuggestion::MAX_PHOTOS,
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return TempleSuggestionResource::collection(
            TempleSuggestion::query()
                ->where('devotee_id', $request->user()->getKey())
                ->with(['state:id,name', 'temple:id,slug,name,status', 'photos'])
                ->latest()
                ->paginate(50)
        );
    }

    /** Multipart: the fields below plus `photos[]`. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:191'],
            'alternate_names' => ['nullable', 'string', 'max:255'],
            'deity_id' => ['nullable', 'integer', 'exists:deities,id'],
            'deity_name' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'pincode' => ['nullable', 'string', 'regex:/^[1-9][0-9]{5}$/'],
            'city' => ['required', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'history' => ['nullable', 'string', 'max:5000'],
            'built_period' => ['nullable', 'string', 'max:120'],
            'festivals' => ['nullable', 'string', 'max:2000'],
            'opens_at' => ['nullable', 'date_format:H:i'],
            'closes_at' => ['nullable', 'date_format:H:i'],
            'timings_note' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+ ()-]{6,20}$/'],
            'official_website' => ['nullable', 'url:http,https', 'max:255'],
            'submitter_role' => ['required', Rule::in(array_keys(TempleSuggestion::ROLES))],
            'submitter_name' => ['nullable', 'string', 'max:120'],
            // Somebody from the temple is who the portal is later handed to;
            // without a number there is nobody to hand it to.
            'submitter_phone' => [Rule::requiredIf(fn () => ! in_array($request->input('submitter_role'), ['devotee', 'other'], true)), 'nullable', 'string', 'max:20', 'regex:/^[0-9+ ()-]{6,20}$/'],
            'submitter_note' => ['nullable', 'string', 'max:2000'],
            'photos' => ['required', 'array', 'min:1', 'max:'.TempleSuggestion::MAX_PHOTOS],
            'photos.*' => ['image', 'mimes:'.UploadRules::mimesRuleFor('temple_photo'), 'max:'.UploadRules::maxKbFor('temple_photo')],
        ]);

        $suggestion = DB::transaction(function () use ($request, $validated): TempleSuggestion {
            $suggestion = new TempleSuggestion(collect($validated)->except('photos')->all());
            $suggestion->devotee_id = $request->user()->getKey();
            $suggestion->submitter_name ??= $request->user()->name;
            $suggestion->save();

            $disk = config('filesystems.media');

            foreach ($request->file('photos', []) as $photo) {
                $suggestion->photos()->create([
                    'disk' => $disk,
                    'path' => $photo->store('temple-suggestions/'.$suggestion->getKey(), ['disk' => $disk]),
                ]);
            }

            return $suggestion;
        });

        return (new TempleSuggestionResource($suggestion->load(['state:id,name', 'photos'])))
            ->response()
            ->setStatusCode(201);
    }
}
