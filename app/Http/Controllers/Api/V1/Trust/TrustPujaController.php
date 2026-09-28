<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Enums\PujaKind;
use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PujaResource;
use App\Models\TemplePuja;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Pujas, sevas and prasadam: what the temple offers, what it costs, and
 * whether devotees may book it in the app.
 *
 * The fee and booking invariants (no fee and not free means no app booking,
 * no URL means no official booking) are enforced by TemplePujaObserver on
 * every write, so this controller does not repeat them.
 */
class TrustPujaController extends Controller
{
    use ScopesToTrustTemples;

    public function index(Request $request, int $temple): JsonResponse
    {
        $rows = $this->managedTemple($request, $temple)->pujas()->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn (TemplePuja $p): array => $this->present($p, $request))->values()]);
    }

    public function store(Request $request, int $temple): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);
        $puja = new TemplePuja(['temple_id' => $record->getKey()]);

        $this->save($request, $puja, $record->getKey());

        return response()->json(['data' => $this->present($puja->refresh(), $request)], 201);
    }

    public function update(Request $request, int $temple, int $puja): JsonResponse
    {
        $record = $this->managedTemple($request, $temple);
        $row = $record->pujas()->findOrFail($puja);

        $this->save($request, $row, $record->getKey());

        return response()->json(['data' => $this->present($row->refresh(), $request)]);
    }

    public function destroy(Request $request, int $temple, int $puja): JsonResponse
    {
        $row = $this->managedTemple($request, $temple)->pujas()->findOrFail($puja);

        // Devotees hold bookings for it; hiding keeps their tickets readable.
        abort_if($row->bookings()->exists(), 422, 'Devotees have booked this seva. Unpublish it instead of deleting it.');

        $row->delete();

        return response()->json(['data' => ['message' => 'Seva removed.']]);
    }

    protected function save(Request $request, TemplePuja $puja, int $templeId): void
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::enum(PujaKind::class)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'includes' => ['nullable', 'string', 'max:2000'],
            'eligibility' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'between:1,1440'],
            'schedule_note' => ['nullable', 'string', 'max:255'],
            'is_free' => ['required', 'boolean'],
            'fee_amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'booking_url' => ['nullable', 'url:http,https', 'max:255'],
            'booking_is_official' => ['nullable', 'boolean'],
            'booking_note' => ['nullable', 'string', 'max:255'],
            'app_booking_enabled' => ['nullable', 'boolean'],
            'fee_per_person' => ['nullable', 'boolean'],
            'max_people_per_booking' => ['nullable', 'integer', 'between:1,100'],
            'booking_advance_days' => ['nullable', 'integer', 'between:0,365'],
            'booking_capacity_per_day' => ['nullable', 'integer', 'between:1,100000'],
            'booking_instructions' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'between:0,1000'],
            'is_published' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:'.UploadRules::mimesRuleFor('puja_image'), 'max:'.UploadRules::maxKbFor('puja_image')],
            'remove_image' => ['nullable', 'boolean'],
        ]);

        // Unset switches fall back to the model's own defaults rather than
        // to null, which the non-null columns would refuse.
        $data = collect($validated)->except(['image', 'remove_image'])
            ->reject(fn ($value, string $key): bool => $value === null && in_array($key, [
                'booking_is_official', 'app_booking_enabled', 'fee_per_person',
                'max_people_per_booking', 'booking_advance_days', 'sort_order', 'is_published',
            ], true))
            ->all();

        $oldImage = [$puja->image_disk, $puja->image_path];

        if ($request->hasFile('image')) {
            $disk = config('filesystems.media');
            $puja->image_disk = $disk;
            $puja->image_path = $request->file('image')->store('pujas/'.$templeId, ['disk' => $disk]);
        } elseif ($request->boolean('remove_image')) {
            $puja->image_path = null;
        }

        $puja->fill($data)->save();

        if (filled($oldImage[1]) && $oldImage[1] !== $puja->image_path) {
            Storage::disk($oldImage[0] ?? config('filesystems.media'))->delete($oldImage[1]);
        }
    }

    /** @return array<string, mixed> */
    protected function present(TemplePuja $puja, Request $request): array
    {
        return (new PujaResource($puja))->resolve($request) + [
            'is_published' => (bool) $puja->is_published,
            'sort_order' => (int) $puja->sort_order,
            'raw' => [
                'booking_is_official' => (bool) $puja->booking_is_official,
                'app_booking_enabled' => (bool) $puja->app_booking_enabled,
            ],
        ];
    }
}
