<?php

namespace App\Providers;

use App\Models\DevotionalMedia;
use App\Models\Temple;
use App\Models\TempleEvent;
use App\Models\TemplePhoto;
use App\Models\TemplePuja;
use App\Models\User;
use App\Observers\DevotionalMediaObserver;
use App\Observers\TempleEventObserver;
use App\Observers\TempleObserver;
use App\Observers\TemplePhotoObserver;
use App\Observers\TemplePujaObserver;
use App\Support\LoginRecorder;
use App\Support\MediaStorage;
use App\Support\Pwa;
use App\Support\TempleTheme;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
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
        /*
         * Where uploads go is a setting, not only an environment variable.
         *
         * Folded over the config once, here, before anything resolves a disk.
         * Doing it at each call site instead would leave half the application
         * asking settings and the other half asking config, and the two would
         * drift the first time somebody added a third place that uploads.
         *
         * Safe on a fresh database: Setting::values() returns an empty array
         * when the table does not exist yet, so `migrate` on an empty schema
         * still boots.
         */
        MediaStorage::apply();

        $this->registerPanelHead();

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

    /**
     * The head both panels share: the stylesheet, and the install tags.
     *
     * Registered once, globally, rather than on each panel — and the panel is
     * resolved when the hook runs rather than closed over.
     *
     * That is not tidiness. Filament's panel-scoped hooks are resolved against
     * whichever panel booted first, so two providers each registering their
     * own hook means the second panel renders the first one's tags. Every
     * request is its own process under PHP-FPM, so both panels looked right
     * and the temple portal would have served the admin panel's manifest the
     * day anything kept the process alive between requests.
     */
    protected function registerPanelHead(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => implode("\n", [
                // Static stylesheet, not a Vite theme: shared Hostinger has no
                // Node toolchain, so deploying must never need an npm build.
                TempleTheme::stylesheetTag(),
                Pwa::headTags(Filament::getCurrentOrDefaultPanel()?->getId() ?? ''),
            ]),
        );
    }
}
