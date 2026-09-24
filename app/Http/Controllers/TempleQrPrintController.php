<?php

namespace App\Http\Controllers;

use App\Models\Temple;
use App\Support\TempleQr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A temple's check-in code as a poster, ready to print for its gate.
 *
 * Reached from either panel. Staff may print any temple's; a temple admin
 * only the temples their claim was approved for — anything else is a 404, so
 * the page does not confirm which temples exist.
 */
class TempleQrPrintController extends Controller
{
    public function show(Request $request, Temple $temple): View|RedirectResponse
    {
        if ($request->user() === null) {
            // Back here once signed in: Filament's login honours the
            // intended URL. Temple teams print these far more than staff.
            return redirect()->guest('/temple/login');
        }

        $this->authorizeTemple($request, $temple);

        return view('temple-qr-print', [
            'temple' => $temple->loadMissing('deity:id,name'),
            'svg' => TempleQr::svg($temple),
            'url' => TempleQr::url($temple),
        ]);
    }

    public function download(Request $request, Temple $temple): Response
    {
        $this->authorizeTemple($request, $temple);

        return response(TempleQr::svg($temple), 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="'.$temple->slug.'-checkin-qr.svg"',
        ]);
    }

    protected function authorizeTemple(Request $request, Temple $temple): void
    {
        $user = $request->user();

        if ($user === null || ! (bool) $user->is_active) {
            throw new NotFoundHttpException();
        }

        if (! ($user->role?->isStaff() ?? false) && ! $user->administersTemple($temple)) {
            throw new NotFoundHttpException();
        }
    }
}
