<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use App\Support\TempleTheme;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The portal a temple's own team signs into.
 *
 * Kept entirely separate from the editorial panel: its own path, its own
 * resource namespace, and a role that cannot sign into the other one. A
 * temple admin sees only the temples their claim has been approved for, and
 * that scoping is applied in the resource queries rather than by hiding
 * navigation links.
 */
class TemplePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $colors = config('brand.colors');

        return $panel
            ->id('temple')
            ->path('temple')
            ->login()
            ->brandName(fn (): string => setting('brand_name', 'brand.name').' — Temple Portal')
            // Kumkum-led rather than saffron, so it is obvious at a glance
            // which panel you are looking at.
            ->colors([
                'primary' => Color::hex($colors['kumkum']['hex']),
                'danger' => Color::hex($colors['kumkum']['hex']),
                'warning' => Color::hex($colors['gold']['hex']),
                'success' => Color::Emerald,
                'info' => Color::Sky,
                'gray' => Color::Stone,
            ])
            ->discoverResources(in: app_path('Filament/Temple/Resources'), for: 'App\Filament\Temple\Resources')
            ->discoverPages(in: app_path('Filament/Temple/Pages'), for: 'App\Filament\Temple\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Temple/Widgets'), for: 'App\Filament\Temple\Widgets')
            // Static stylesheet, not a Vite theme: shared Hostinger has no Node
            // toolchain, so deploying must never require an npm build.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => TempleTheme::stylesheetTag(),
            )
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
