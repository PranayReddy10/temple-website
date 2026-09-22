<?php

namespace App\Providers;

use App\Models\DevotionalMedia;
use App\Models\Temple;
use App\Models\TemplePhoto;
use App\Models\TempleEvent;
use App\Models\TemplePuja;
use App\Observers\DevotionalMediaObserver;
use App\Observers\TempleObserver;
use App\Observers\TemplePhotoObserver;
use App\Observers\TempleEventObserver;
use App\Observers\TemplePujaObserver;
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
        Temple::observe(TempleObserver::class);
        TemplePhoto::observe(TemplePhotoObserver::class);
        TemplePuja::observe(TemplePujaObserver::class);
        DevotionalMedia::observe(DevotionalMediaObserver::class);
        TempleEvent::observe(TempleEventObserver::class);

        Event::listen(Login::class, function (Login $event): void {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        });
    }
}
