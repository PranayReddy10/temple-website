<?php

namespace App\Http\Controllers;

use App\Http\Resources\V1\PublicPassportResource;
use App\Models\Devotee;
use Illuminate\View\View;

/**
 * Where a devotee's passport code leads when scanned with an ordinary phone
 * camera: the same public view the app shows, readable without the app.
 */
class PassportPageController extends Controller
{
    public function __invoke(string $code): View
    {
        $devotee = Devotee::findByPassportCode($code);

        return view('passport', [
            'passport' => $devotee === null
                ? null
                : (new PublicPassportResource($devotee->load('homeState:id,name')))->resolve(request()),
        ]);
    }
}
