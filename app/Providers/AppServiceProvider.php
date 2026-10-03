<?php

namespace App\Providers;

use App\Http\Controllers\MediaPreviewController;
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
use App\Support\BrandName;
use App\Support\LoginRecorder;
use App\Support\MailSettings;
use App\Support\MediaStorage;
use App\Support\Pwa;
use App\Support\TempleTheme;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        self::previewUploadsFromThisHost();

        // Photo and link URLs the API hands out must be https when the site
        // is: Android refuses plain-http images, so a proxy or CDN that
        // forwards requests over http would otherwise blank every photo in
        // the app while the admin panel (relative URLs) looks fine.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

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

        // Outgoing mail (password reset codes) is configured in the admin
        // panel; folded over config the same way, and as safe on a fresh
        // database.
        MailSettings::apply();

        // One name everywhere: the Brand name from Settings over .env.
        BrandName::apply();

        // The API-wide limit: 60 requests a minute per devotee, or per address
        // before sign-in.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

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

    /**
     * Upload fields read saved files back through this host.
     *
     * Filament previews a saved file by downloading it with fetch(); from
     * Spaces that is a cross-origin request the browser blocks without CORS
     * on the bucket, and the field hangs at "Waiting for size". A signed,
     * relative URL to MediaPreviewController makes it same-origin, whichever
     * disk the file is on. The size and type are read the way Filament reads
     * them.
     */
    protected static function previewUploadsFromThisHost(): void
    {
        FileUpload::configureUsing(function (FileUpload $upload): void {
            // Opening a form asked the Space whether each saved file exists,
            // and its size and type, before the page could render: a round
            // trip per file, and a hang when the Space is slow to answer or
            // the key is refused. The preview shows the file either way.
            $upload->fetchFileInformation(fn (FileUpload $component): bool => $component->getDiskName() !== MediaStorage::SPACES_DISK);

            $upload->getUploadedFileUsing(function (FileUpload $component, string $file, string|array|null $storedFileNames): ?array {
                $disk = $component->getDiskName();
                $storage = $component->getDisk();
                $size = 0;
                $type = null;

                if ($component->shouldFetchFileInformation()) {
                    try {
                        $size = $storage->size($file);
                        $type = $storage->mimeType($file);
                    } catch (\Throwable) {
                        return null;
                    }
                }

                return [
                    'name' => ($component->isMultiple() ? ($storedFileNames[$file] ?? null) : $storedFileNames) ?? basename($file),
                    'size' => $size,
                    'type' => $type,
                    'url' => in_array($disk, MediaPreviewController::DISKS, true)
                        ? URL::temporarySignedRoute('media.preview', now()->addHours(2), ['disk' => $disk, 'path' => $file], absolute: false)
                        : $storage->url($file),
                ];
            });
        });
    }
}
