<?php

namespace App\Http\Controllers;

use App\Models\TemplePayoutAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * A temple owner's verification document, for the staff member checking it.
 *
 * The files sit on the private disk and are never public. A link is signed,
 * short-lived and also needs a signed-in staff member who may manage
 * temple finances, so a copied link is useless to anyone else.
 */
class KycDocumentController extends Controller
{
    public function __invoke(Request $request, TemplePayoutAccount $account, string $document): Response
    {
        abort_unless(Auth::user()?->canManageUsers() ?? false, 403);
        abort_unless(isset(TemplePayoutAccount::DOCUMENTS[$document]), 404);

        // A document as it was at an earlier step of the history, or the current one.
        if ($request->filled('event')) {
            $event = $account->verificationEvents()->whereKey($request->integer('event'))->firstOrFail();
            [$diskName, $path] = [$event->documentDisk(), $event->documentPath($document)];
        } else {
            [$diskName, $path] = [$account->kycDisk(), $account->{TemplePayoutAccount::DOCUMENTS[$document][0]}];
        }

        abort_if(blank($path), 404);

        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, headers: [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public static function url(TemplePayoutAccount $account, string $document, ?int $eventId = null): string
    {
        return URL::temporarySignedRoute('kyc.document', now()->addMinutes(30), array_filter([
            'account' => $account->getKey(),
            'document' => $document,
            'event' => $eventId,
        ]));
    }
}
