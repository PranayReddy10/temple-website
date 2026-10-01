<?php

namespace App\Http\Controllers;

use App\Enums\TicketCategory;
use App\Enums\TicketKind;
use App\Enums\TicketPriority;
use App\Models\Devotee;
use App\Models\Page;
use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The website's policy and information pages, at darshansaathi.com/{slug}.
 *
 * Reached through the router's fallback, so a page never shadows the admin
 * panel or any other address, whatever slug it is given.
 */
class PageController extends Controller
{
    public function show(string $path = ''): View
    {
        $slug = trim($path, '/');

        $page = preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)
            ? Page::query()->published()->where('slug', $slug)->first()
            : null;

        if ($page === null) {
            throw new NotFoundHttpException;
        }

        return view('site.page', [
            'page' => $page,
            'title' => $page->renderedTitle(),
            'description' => $page->renderedSummary(),
            'canonical' => $page->url(),
        ]);
    }

    /**
     * "Delete my account" without the app, as Google Play asks for: a
     * request to support, who confirm with the owner before deleting. The
     * answer is the same whether or not an account matched, so the form
     * cannot be used to find out who has one.
     */
    public function requestDeletion(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'contact' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:2000'],
            // Bots fill every field; people never see this one.
            'website' => ['prohibited'],
        ]);

        $contact = trim($data['contact']);
        $devotee = Devotee::query()
            ->where(fn ($q) => $q->where('email', $contact)->orWhere('phone', $contact))
            ->first();

        $ticket = new SupportTicket([
            'kind' => TicketKind::Support,
            'category' => TicketCategory::Account,
            'priority' => TicketPriority::High,
            'subject' => 'Account deletion request',
            'body' => "Delete the account of: {$contact}\n"
                .($devotee ? "Matches devotee #{$devotee->getKey()}.\n" : "No account matched this email or phone.\n")
                ."Confirm with the account's own email or phone before deleting (Devotees → the devotee → Delete account).\n\n"
                .'Reason given: '.($data['reason'] ?? '—'),
            'reporter_name' => $data['name'] ?? ($devotee?->name ?? 'Website visitor'),
            'reporter_email' => filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : $devotee?->email,
            'source' => 'website',
        ]);
        $ticket->devotee_id = $devotee?->getKey();
        $ticket->save();

        return redirect()->to(url('account-deletion').'#request')
            ->with('deletion_requested', $ticket->reference);
    }
}
