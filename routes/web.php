<?php

use App\Http\Controllers\MediaFileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Locally stored media, as a fallback
|--------------------------------------------------------------------------
|
| In a healthy install this route is never reached: public/storage is a
| symlink, so the web server finds the file and answers before PHP is
| involved. It exists for when that link is missing — some shared hosts
| disable symlink() altogether, and a redeploy that unpacks over the top can
| lose it. The symptom is every uploaded image going blank at once with
| nothing in the logs, because the requests never reached the application.
|
| Declared here rather than through the filesystem config's own `serve`
| option, which Laravel skips whenever routes are cached — which is exactly
| what `app:deploy` does. That version would work locally and be absent in
| production, which is the one combination worth avoiding.
|
| The pattern excludes nothing: `where('path', '.*')` is what lets a nested
| path like temples/12/photo.jpg match a single parameter.
|
*/
Route::get('/storage/{path}', MediaFileController::class)
    ->where('path', '.*')
    ->name('media.file');
