<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Profile;
use App\Support\InitialsAvatarProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $colors = config('brand.colors');

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // A real account page, reached from the user menu. isSimple:
            // false keeps the panel's navigation around it, so it reads as
            // part of the admin rather than a sign-in screen.
            ->profile(Profile::class, isSimple: false)
            // Drawn locally rather than fetched from ui-avatars.com: no
            // third-party request per page, and nothing to break when that
            // service is unreachable from the host.
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            // Resolved lazily, not at registration time, so the product name can
            // change through the settings screen, config or .env without
            // touching this provider or requiring a deploy.
            ->brandName(fn (): string => setting('brand_name', 'brand.name'))
            // Root-relative, and a real file. asset() builds this from
            // APP_URL — http://localhost until somebody changes it — and the
            // favicon.ico it pointed at was zero bytes, so the panel had no
            // icon at all and the link to it was broken twice over.
            ->favicon('/icons/favicon-32.png')
            // Saffron primary with kumkum and gold accents: the devotional
            // palette shared with the public site and the Flutter app.
            ->colors([
                'primary' => Color::hex($colors['saffron']['hex']),
                'danger' => Color::hex($colors['kumkum']['hex']),
                'warning' => Color::hex($colors['gold']['hex']),
                'success' => Color::Emerald,
                'info' => Color::Sky,
                'gray' => Color::Stone,
            ])
            // Groups are deliberately icon-free: Filament allows an icon on the
            // group or on its items, not both, and the per-resource icons are
            // the more useful of the two.
            ->navigationGroups([
                NavigationGroup::make('Temples'),
                NavigationGroup::make('Daily Devotion'),
                NavigationGroup::make('Devotees'),
                NavigationGroup::make('Master Data'),
                NavigationGroup::make('Support'),
                NavigationGroup::make('Administration'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // The running version, in the sidebar and under the sign-in form.
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn (): View => view('filament.partials.version'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): View => view('filament.partials.version'))
            ->pages([
                Dashboard::class,
            ])
            // No AccountWidget: its only function was a sign-out button, which
            // the user menu already carries, and it took a full-width card at
            // the top of the dashboard to do it. Account details moved to the
            // profile page above.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
