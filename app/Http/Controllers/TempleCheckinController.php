<?php

namespace App\Http\Controllers;

use App\Support\TempleQr;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Where a temple code leads when scanned with an ordinary phone camera.
 *
 * Says plainly whether the code is genuine, so a temple, a devotee or an
 * editor can check a printed code without the app. The app reads the same
 * URL and never opens this page.
 */
class TempleCheckinController extends Controller
{
    public function __invoke(Request $request, string $slug): View
    {
        $check = TempleQr::verify($request->fullUrl());

        return view('checkin', [
            'valid' => $check['valid'] && $check['temple']?->slug === $slug,
            'temple' => $check['temple'],
            'reason' => $check['reason'],
        ]);
    }
}
