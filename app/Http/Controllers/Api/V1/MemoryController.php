<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MemoryResource;
use App\Models\DevoteeMemory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A devotee's own writing about their pilgrimages.
 *
 * Private is the default and stays the default through updates: an endpoint
 * that publishes a memory because the field was omitted from a PATCH is an
 * endpoint that publishes someone's prayers by accident.
 */
class MemoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $memories = $request->user()
            ->memories()
            ->with('temple:id,slug,name')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return MemoryResource::collection($memories);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules($request));

        $memory = new DevoteeMemory($validated);
        $memory->devotee_id = $request->user()->getKey();
        $memory->is_private = $request->boolean('is_private', true);
        $memory->save();

        return (new MemoryResource($memory->load('temple:id,slug,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, DevoteeMemory $memory): MemoryResource
    {
        $this->assertOwned($request, $memory);

        $validated = $request->validate($this->rules($request, updating: true));

        $memory->fill($validated);

        // Only when the field was actually sent. Omitting it must leave the
        // visibility alone, never quietly make a private memory public.
        if ($request->has('is_private')) {
            $memory->is_private = $request->boolean('is_private');
        }

        $memory->save();

        return new MemoryResource($memory->load('temple:id,slug,name'));
    }

    public function destroy(Request $request, DevoteeMemory $memory): JsonResponse
    {
        $this->assertOwned($request, $memory);

        $memory->delete();

        return response()->json(['data' => ['message' => 'Memory removed.']]);
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(Request $request, bool $updating = false): array
    {
        $devoteeId = $request->user()->getKey();

        return [
            'title' => ['nullable', 'string', 'max:160'],
            'body' => [$updating ? 'sometimes' : 'required', 'string', 'max:20000'],
            'happened_on' => ['nullable', 'date', 'before_or_equal:today'],

            // Scoped to this devotee's own records: naming someone else's
            // visit would attach a memory to a pilgrimage that is not theirs.
            'temple_id' => ['nullable', 'integer', Rule::exists('temples', 'id')->whereNull('deleted_at')],
            'devotee_visit_id' => [
                'nullable', 'integer',
                Rule::exists('devotee_visits', 'id')->where('devotee_id', $devoteeId),
            ],

            'is_private' => ['nullable', 'boolean'],
        ];
    }

    protected function assertOwned(Request $request, DevoteeMemory $memory): void
    {
        if ($memory->devotee_id !== $request->user()?->getKey()) {
            throw new NotFoundHttpException();
        }
    }
}
