<?php

namespace App\Http\Controllers;

use App\Models\TemplePayoutAccount;
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
    public function __invoke(TemplePayoutAccount $account, string $document): Response
    {
        abort_unless(Auth::user()?->canManageUsers() ?? false, 403);

        $column = TemplePayoutAccount::DOCUMENTS[$document][0] ?? null;
        abort_if($column === null || blank($account->{$column}), 404);

        $disk = Storage::disk($account->kycDisk());
        abort_unless($disk->exists($account->{$column}), 404);

        return $disk->response($account->{$column}, headers: [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public static function url(TemplePayoutAccount $account, string $document): string
    {
        return URL::temporarySignedRoute('kyc.document', now()->addMinutes(30), ['account' => $account->getKey(), 'document' => $document]);
    }
}
