<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Filament\Resources\TempleAccess\Schemas\TempleAccessForm;
use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Trust\TrustAccountResource;
use App\Models\Temple;
use App\Models\TempleUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "This is our temple": a team asks to manage a temple already listed.
 *
 * A claim is only a request. It grants nothing until staff approve it in the
 * admin panel (Temple access), usually after calling the temple office.
 */
class TrustClaimController extends Controller
{
    use ScopesToTrustTemples;

    /** Listed temples to claim, with whether this account already asked. */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $claims = $this->trustUser($request)->templeClaims()->get()->keyBy('temple_id');

        $temples = Temple::query()
            ->published()
            ->search($validated['q'])
            ->with(['deity:id,name', 'state:id,name'])
            ->orderBy('name')
            ->limit(25)
            ->get();

        return response()->json(['data' => $temples->map(fn (Temple $t): array => [
            'id' => $t->getKey(),
            'slug' => $t->slug,
            'name' => $t->name,
            'deity' => $t->deity?->name,
            'city' => $t->city,
            'state' => $t->state?->name,
            'claim_status' => $claims->get($t->getKey())?->status(),
        ])->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        $claims = $this->trustUser($request)->templeClaims()
            ->with('temple:id,slug,name,city')
            ->latest('id')
            ->get();

        return response()->json(['data' => $claims->map(fn (TempleUser $c): array => TrustAccountResource::claim($c))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_if($this->trustUser($request)->isSuperAdmin(), 422, 'Super admins already manage every temple. Add a temple from the admin panel.');

        $validated = $request->validate([
            'temple_id' => ['required', 'integer', Rule::exists('temples', 'id')->where('status', 'published')],
            'role' => ['required', Rule::in(array_keys(TempleAccessForm::levels()))],
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $user = $this->trustUser($request);
        $claim = TempleUser::query()
            ->where('user_id', $user->getKey())
            ->where('temple_id', $validated['temple_id'])
            ->first();

        if ($claim !== null && ! $claim->isRejected()) {
            throw ValidationException::withMessages(['temple_id' => $claim->isApproved()
                ? 'You already manage this temple.'
                : 'You have already asked to manage this temple. The team will be in touch.']);
        }

        $claim ??= new TempleUser(['user_id' => $user->getKey(), 'temple_id' => $validated['temple_id']]);

        // Asked afresh after a rejection: the old reason is cleared so the
        // claim reads as pending again, and staff see the new note.
        $claim->forceFill([
            'role' => $validated['role'],
            'claim_note' => $validated['note'],
            'requested_at' => now(),
            'approved_at' => null,
            'approved_by' => null,
            'rejection_reason' => null,
        ])->save();

        return response()->json(['data' => TrustAccountResource::claim($claim->load('temple:id,slug,name,city'))], 201);
    }

    /** Withdraws a claim still waiting. An approved one is removed by staff. */
    public function destroy(Request $request, int $claim): JsonResponse
    {
        $row = $this->trustUser($request)->templeClaims()->whereKey($claim)->firstOrFail();

        abort_if($row->isApproved(), 422, 'An approved claim is removed by the editorial team.');

        $row->delete();

        return response()->json(['data' => ['message' => 'Claim withdrawn.']]);
    }
}
