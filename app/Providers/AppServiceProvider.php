<?php

namespace App\Providers;

use App\Models\DevotionalMedia;
use App\Models\Temple;
use App\Models\User;
use App\Models\TemplePhoto;
use App\Models\TempleEvent;
use App\Models\TemplePuja;
use App\Observers\DevotionalMediaObserver;
use App\Observers\TempleObserver;
use App\Observers\TemplePhotoObserver;
use App\Observers\TempleEventObserver;
use App\Observers\TemplePujaObserver;
use App\Support\LoginRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
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

        /*
         * Sign-ins are recorded, not just stamped.
         *
         * last_login_at stays, because "when did they last sign in" is worth
         * one indexed column rather than an aggregate over an event table.
         * But it can only ever hold the latest value, so the event goes to
         * login_events as well — that is what "how many people signed in this
         * week" reads, and it cannot be reconstructed afterwards.
         *
         * Both guards come through here: Filament's panel login raises Login
         * on the web guard, and the API's devotee login raises it explicitly.
         */
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof Model) {
                LoginRecorder::success($event->user, $event->guard);
            }

            // Staff only: devotees have no such column, and the event table
            // is where their activity is read from anyway.
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        /*
         * Failures too. A burst of them against one account is the first sign
         * of a credential-stuffing run, and it is invisible if only successes
         * are kept.
         */
        Event::listen(Failed::class, function (Failed $event): void {
            LoginRecorder::failure(
                $event->guard ?? 'web',
                $event->credentials['email'] ?? $event->credentials['identifier'] ?? null,
                $event->user === null ? 'unknown_account' : 'bad_password',
            );
        });
    }
}
