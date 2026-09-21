<?php

namespace App\Providers;

use App\Models\Temple;
use App\Observers\TempleObserver;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Static stylesheet, not a Vite theme: shared Hostinger has no Node
        // toolchain, so deploying the admin panel must not require an npm build.
        FilamentAsset::register([
            Css::make('temple-admin', asset('css/temple-admin.css')),
        ]);

        Temple::observe(TempleObserver::class);

        Event::listen(Login::class, function (Login $event): void {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        });
    }
}
