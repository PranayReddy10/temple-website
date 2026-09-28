<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Trust\TrustTempleResource;
use App\Models\Temple;
use App\Support\DevotionalClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The temples a team manages, and the part of each listing they own.
 *
 * The editable fields are exactly the temple portal's (MyTempleForm): how to
 * reach the temple, where it is, and what a visitor should expect. Name,
 * deity, classification and trust level stay with the editors.
 */
class TrustTempleController extends Controller
{
    use ScopesToTrustTemples;

    public function index(Request $request): JsonResponse
    {
        $temples = $this->trustUser($request)->temples()
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => TrustTempleResource::collection($temples)->resolve($request)]);
    }

    public function show(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple)->load(['deity:id,name', 'state:id,name', 'primaryPhoto']);

        return response()->json(['data' => (new TrustTempleResource($record))->withStats($this->stats($record))->resolve($request)]);
    }

    public function update(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);

        $validated = $request->validate([
            'short_description' => ['nullable', 'string', 'max:500'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:255'],
            'pincode' => ['nullable', 'string', 'regex:/^[1-9][0-9]{5}$/'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'official_website' => ['nullable', 'url:http,https', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'dress_code' => ['nullable', 'string', 'max:2000'],
            'photography_policy' => ['nullable', 'string', 'max:255'],
            'mobile_policy' => ['nullable', 'string', 'max:255'],
            'footwear_policy' => ['nullable', 'string', 'max:255'],
            'entry_rules' => ['nullable', 'string', 'max:5000'],
            'queue_information' => ['nullable', 'string', 'max:5000'],
        ]);

        $record->fill($validated)->save();

        return $this->show($request, $temple);
    }

    /** @return array<string, int> */
    protected function stats(Temple $temple): array
    {
        $today = DevotionalClock::now()->toDateString();
        $live = [BookingStatus::Confirmed->value, BookingStatus::Verified->value];

        return [
            'bookings_today' => $temple->pujaBookings()->whereDate('booked_for', $today)->whereIn('status', $live)->count(),
            'bookings_upcoming' => $temple->pujaBookings()->whereDate('booked_for', '>=', $today)->whereIn('status', $live)->count(),
            'received_today' => $temple->pujaBookings()->whereDate('booked_for', $today)->where('status', BookingStatus::Verified)->count(),
            'events_upcoming' => $temple->events()->upcoming()->count(),
            'events_in_review' => $temple->events()->where('status', EventStatus::PendingReview)->count(),
            'reviews_to_answer' => $temple->reviews()->approved()->whereNull('temple_reply')->count(),
            'followers' => $temple->follows()->count(),
            'likes' => $temple->likes()->count(),
            'visits' => $temple->visits()->count(),
            'photos' => $temple->photos()->count(),
            'sevas' => $temple->pujas()->count(),
        ];
    }
}
