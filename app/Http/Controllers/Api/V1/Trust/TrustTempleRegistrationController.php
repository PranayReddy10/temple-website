<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Http\Controllers\Api\V1\TempleSuggestionController;
use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Trust\TrustAccountResource;
use App\Models\TempleSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * "Our temple is not listed": a temple's own team registers it.
 *
 * The same review queue as a devotee's suggestion — Admin → Temple
 * suggestions — so nothing here publishes. What differs is who sent it: the
 * suggestion carries this account, and when staff approve it (or match it to
 * a temple already listed) the account gets a claim on that temple, which
 * staff then approve the same way as any other.
 */
class TrustTempleRegistrationController extends Controller
{
    use ScopesToTrustTemples;

    /** A temple's own people, not a passing devotee. */
    public const ROLES = ['trustee', 'priest', 'committee', 'staff'];

    public function index(Request $request): JsonResponse
    {
        $rows = TempleSuggestion::query()
            ->where('user_id', $this->trustUser($request)->getKey())
            ->with('state:id,name')
            ->latest('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (TempleSuggestion $s): array => TrustAccountResource::registration($s))->values()]);
    }

    /** Multipart: the temple's fields plus `photos[]`. */
    public function store(Request $request): JsonResponse
    {
        $user = $this->trustUser($request);

        // The account's own phone stands in when the form leaves it out.
        $request->mergeIfMissing(['submitter_phone' => $user->phone]);

        $rules = TempleSuggestionController::rules($request);
        $rules['submitter_role'] = ['required', Rule::in(self::ROLES)];

        $validated = $request->validate($rules);

        $suggestion = DB::transaction(function () use ($request, $validated, $user): TempleSuggestion {
            $suggestion = new TempleSuggestion(collect($validated)->except('photos')->all());
            $suggestion->user_id = $user->getKey();
            $suggestion->submitter_name ??= $user->name;
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

        return response()->json(['data' => TrustAccountResource::registration($suggestion->load('state:id,name'))], 201);
    }
}
