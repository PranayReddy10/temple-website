<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketCategory;
use App\Enums\TicketKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SupportTicketResource;
use App\Models\Devotee;
use App\Models\SupportTicket;
use App\Models\Temple;
use App\Models\TempleEvent;
use App\Models\TemplePuja;
use App\Models\VisitPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Where a devotee tells us something is wrong.
 *
 * Reachable without an account. A report that can only be filed by somebody
 * signed in is a report most people will not file, and the listing with the
 * wrong timings goes on sending devotees to a closed gate. A signed-in
 * devotee gets their tickets remembered; everyone else gets a reference to
 * quote.
 */
class SupportController extends Controller
{
    /**
     * What a report may be filed against.
     *
     * An allow-list, not a free-text class name: without it, a caller could
     * point a ticket at any model in the application — including a User — and
     * the admin would happily render whatever came back.
     *
     * @var array<string, class-string>
     */
    protected const REPORTABLE = [
        'temple' => Temple::class,
        'event' => TempleEvent::class,
        'puja' => TemplePuja::class,
        'photo' => VisitPhoto::class,
    ];

    /** The categories and their descriptions, so the app need not hard-code them. */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => [
                'categories' => collect(TicketCategory::cases())
                    ->map(fn (TicketCategory $category): array => [
                        'value' => $category->value,
                        'label' => $category->getLabel(),
                        'description' => $category->description(),
                        'needs_subject' => $category->needsASubject(),
                    ])
                    ->values(),
                'reportable_types' => array_keys(self::REPORTABLE),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // This route sits outside the auth middleware so that anyone can
        // file, which leaves the default guard as the session one. A bare
        // user() would never see the app's bearer token, so a signed-in
        // devotee would be treated as a stranger and refused for not giving
        // a name. Ask the devotee guard directly.
        $devotee = $request->user('devotee');

        $validated = $request->validate([
            'kind' => ['nullable', Rule::enum(TicketKind::class)],
            'category' => ['nullable', Rule::enum(TicketCategory::class)],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],

            // Only needed when nobody is signed in, and then only so we can
            // write back at all.
            'name' => [Rule::requiredIf($devotee === null), 'nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],

            'about_type' => ['nullable', Rule::in(array_keys(self::REPORTABLE))],
            'about_id' => ['nullable', 'integer'],
        ]);

        $about = $this->resolveSubject($validated['about_type'] ?? null, $validated['about_id'] ?? null);

        $ticket = new SupportTicket([
            'kind' => $validated['kind'] ?? ($about !== null ? TicketKind::Report : TicketKind::Support),
            'category' => $validated['category'] ?? TicketCategory::Other,
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'reporter_name' => $devotee?->name ?? ($validated['name'] ?? null),
            'reporter_email' => $devotee?->email ?? ($validated['email'] ?? null),
            'source' => 'app',
            'app_version' => $request->header('X-App-Version'),
            'platform' => $request->header('X-Platform'),
        ]);

        if ($devotee instanceof Devotee) {
            $ticket->devotee_id = $devotee->getKey();
        }

        if ($about !== null) {
            $ticket->about()->associate($about);
        }

        $ticket->save();

        return (new SupportTicketResource($ticket))
            ->response()
            ->setStatusCode(201);
    }

    /** A signed-in devotee's own tickets, with the replies but not the notes. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tickets = $request->user()
            ->supportTickets()
            ->with(['replies.author'])
            ->latest()
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return SupportTicketResource::collection($tickets);
    }

    public function show(Request $request, string $reference): SupportTicketResource
    {
        $ticket = SupportTicket::query()
            ->where('reference', $reference)
            // Scoped to the signed-in devotee's own: a reference is short
            // enough to guess at, and somebody else's ticket is not theirs to
            // read.
            ->where('devotee_id', $request->user()->getKey())
            ->with(['replies.author'])
            ->first();

        if ($ticket === null) {
            throw new NotFoundHttpException();
        }

        return new SupportTicketResource($ticket);
    }

    /** Adds to the conversation on the devotee's own ticket. */
    public function reply(Request $request, string $reference): SupportTicketResource
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $ticket = SupportTicket::query()
            ->where('reference', $reference)
            ->where('devotee_id', $request->user()->getKey())
            ->first();

        if ($ticket === null) {
            throw new NotFoundHttpException();
        }

        $ticket->messages()->create([
            'author_type' => Devotee::class,
            'author_id' => $request->user()->getKey(),
            'body' => $validated['body'],
            // A devotee cannot write an internal note, whatever they send.
            'is_internal' => false,
        ]);

        // Their reply puts it back on us, including on one already resolved:
        // a resolution they did not accept is not a resolution.
        $ticket->update(['status' => \App\Enums\TicketStatus::Open]);

        return new SupportTicketResource($ticket->fresh()->load('replies.author'));
    }

    protected function resolveSubject(?string $type, ?int $id): ?object
    {
        if ($type === null || $id === null) {
            return null;
        }

        $model = self::REPORTABLE[$type] ?? null;

        if ($model === null) {
            return null;
        }

        // A report against something that does not exist is a report nobody
        // can act on, so it is rejected rather than filed blind.
        $record = $model::find($id);

        if ($record === null) {
            throw new NotFoundHttpException();
        }

        return $record;
    }
}
