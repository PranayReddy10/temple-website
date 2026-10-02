<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Enums\TicketCategory;
use App\Enums\TicketKind;
use App\Enums\TicketStatus;
use App\Http\Controllers\Api\V1\Trust\Concerns\ScopesToTrustTemples;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SupportTicketResource;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Support for temple teams, from the trust app: a question or a problem goes
 * to the same queue staff work in the admin panel (Support & Reports), and
 * the answers come back here. A ticket may be about one of the account's
 * own temples; never anyone else's.
 */
class TrustSupportController extends Controller
{
    use ScopesToTrustTemples;

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'categories' => collect(TicketCategory::cases())->map(fn (TicketCategory $c): array => [
                'value' => $c->value,
                'label' => $c->getLabel(),
                'description' => $c->description(),
            ])->values(),
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return SupportTicketResource::collection(
            SupportTicket::query()
                ->where('user_id', $this->trustUser($request)->getKey())
                ->with(['replies.author'])
                ->latest()
                ->paginate(50)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->trustUser($request);
        $validated = $request->validate([
            'category' => ['nullable', Rule::enum(TicketCategory::class)],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'temple_id' => ['nullable', 'integer'],
        ]);

        $ticket = new SupportTicket([
            'kind' => TicketKind::Support,
            'category' => $validated['category'] ?? TicketCategory::Other,
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'reporter_name' => $user->name,
            'reporter_email' => $user->email,
            'source' => 'trust-app',
            'app_version' => $request->header('X-App-Version'),
            'platform' => $request->header('X-Platform'),
        ]);
        $ticket->user_id = $user->getKey();

        if (isset($validated['temple_id'])) {
            $ticket->about()->associate($this->managedTemple($request, $validated['temple_id']));
        }

        $ticket->save();

        return (new SupportTicketResource($ticket->load('replies.author')))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $reference): SupportTicketResource
    {
        return new SupportTicketResource($this->mine($request, $reference)->load('replies.author'));
    }

    public function reply(Request $request, string $reference): SupportTicketResource
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $ticket = $this->mine($request, $reference);

        $ticket->messages()->create([
            'author_type' => User::class,
            'author_id' => $this->trustUser($request)->getKey(),
            'body' => $validated['body'],
            'is_internal' => false,
        ]);
        // Their reply puts it back with staff, even if it was resolved.
        $ticket->update(['status' => TicketStatus::Open]);

        return new SupportTicketResource($ticket->fresh()->load('replies.author'));
    }

    protected function mine(Request $request, string $reference): SupportTicket
    {
        return SupportTicket::query()
            ->where('reference', $reference)
            ->where('user_id', $this->trustUser($request)->getKey())
            ->first() ?? throw new NotFoundHttpException;
    }
}
