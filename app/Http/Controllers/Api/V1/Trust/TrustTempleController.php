<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\TempleStatus;
use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Trust\TrustTempleResource;
use App\Models\District;
use App\Models\Temple;
use App\Models\TempleDonation;
use App\Support\DevotionalClock;
use App\Support\Finance\Settlements;
use App\Support\TempleQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
        $user = $this->trustUser($request);

        if ($user->isSuperAdmin()) {
            $validated = $request->validate([
                'q' => ['nullable', 'string', 'max:100'],
                'status' => ['nullable', Rule::enum(TempleStatus::class)],
            ]);

            $page = Temple::query()
                ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
                ->when($validated['q'] ?? null, fn ($q, string $term) => $q->search($term))
                ->when($validated['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
                ->orderBy('name')
                ->paginate(30);

            return TrustTempleResource::collection($page)->response();
        }

        $temples = $user->temples()
            ->with(['deity:id,name', 'state:id,name', 'primaryPhoto'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => TrustTempleResource::collection($temples)->resolve($request)]);
    }

    public function show(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple)->load(['deity:id,name', 'state:id,name', 'district:id,name', 'primaryPhoto']);

        return response()->json(['data' => (new TrustTempleResource($record))->withStats($this->stats($record))->resolve($request)]);
    }

    public function update(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);

        $validated = $request->validate([
            'short_description' => ['nullable', 'string', 'max:500'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:255'],
            'state_id' => ['nullable', 'integer', Rule::exists('states', 'id')],
            'district' => ['nullable', 'string', 'min:2', 'max:100'],
            'pincode' => ['nullable', 'string', 'regex:/^[1-9][0-9]{5}$/'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            // A new position only from the phone, at the temple (see
            // TrustTempleRegistrationController::LOCATION_ACCURACY_M).
            'location_accuracy' => ['nullable', 'required_with:latitude', 'numeric', 'min:0', 'max:'.TrustTempleRegistrationController::LOCATION_ACCURACY_M],
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

        $fill = collect($validated)->except(['location_accuracy', 'district'])->all();

        // The district is typed; it is matched to the state's list by name,
        // and added to it the first time a temple there names it.
        if ($request->has('district') || $request->has('state_id')) {
            $stateId = array_key_exists('state_id', $validated) ? $validated['state_id'] : $record->state_id;
            $name = trim((string) ($validated['district'] ?? ''));
            $fill['district_id'] = ($stateId && $name !== '')
                ? District::query()->firstOrCreate(
                    ['state_id' => $stateId, 'slug' => Str::slug($name)],
                    ['name' => Str::title($name)],
                )->getKey()
                : null;
        }

        $record->fill($fill)->save();

        return $this->show($request, $temple);
    }

    /** @return array<string, int> */
    /**
     * The temple's check-in code, to show at the gate or print: the signed
     * URL a devotee's app scans, the code as SVG, and the printable poster
     * on the web (which asks the portal sign-in in the browser).
     */
    public function qr(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);

        return response()->json(['data' => [
            'url' => TempleQr::url($record),
            'svg' => TempleQr::svg($record),
            'print_url' => route('temples.qr.print', $record),
            'download_url' => route('temples.qr.download', $record),
            'is_published' => $record->status === TempleStatus::Published,
        ]]);
    }

    protected function stats(Temple $temple): array
    {
        $today = DevotionalClock::now()->toDateString();
        $live = [BookingStatus::Confirmed->value, BookingStatus::Verified->value];

        $now = DevotionalClock::now();
        $hundi = fn () => $temple->donations()->where('status', TempleDonation::PAID);

        return [
            // Online hundi: gifts devotees made in the app.
            'hundi_today_paise' => (int) $hundi()->whereDate('paid_on', $today)->sum('amount_paise'),
            'hundi_today_count' => $hundi()->whereDate('paid_on', $today)->count(),
            'hundi_month_paise' => (int) $hundi()->whereDate('paid_on', '>=', $now->copy()->startOfMonth()->toDateString())->sum('amount_paise'),
            'hundi_enabled' => (bool) $temple->accepts_donations,
            // missing · pending · approved · rejected: may devotees pay here?
            'payments' => $temple->payoutAccount?->kycStatus() ?? 'missing',
            // Why staff refused it, shown on the home screen until fixed.
            'payments_rejection_reason' => $temple->payoutAccount?->kycStatus() === 'rejected' ? $temple->payoutAccount->rejection_reason : null,
            // What the platform keeps, so the team sees its share up front.
            'fee_percent' => app(Settlements::class)->feePercentFor($temple),
            'donation_fee_percent' => app(Settlements::class)->donationFeePercent(),
            'bookings_today' => $temple->pujaBookings()->whereDate('booked_for', $today)->whereIn('status', $live)->count(),
            'bookings_upcoming' => $temple->pujaBookings()->whereDate('booked_for', '>=', $today)->whereIn('status', $live)->count(),
            'received_today' => $temple->pujaBookings()->whereDate('booked_for', $today)->where('status', BookingStatus::Verified)->count(),
            // Paid for today's sevas, in paise; the finance screen has the rest.
            'amount_today_paise' => (int) $temple->pujaBookings()->whereDate('booked_for', $today)->whereIn('status', $live)->sum('amount_paise'),
            'people_today' => (int) $temple->pujaBookings()->whereDate('booked_for', $today)->whereIn('status', $live)->sum('people'),
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
