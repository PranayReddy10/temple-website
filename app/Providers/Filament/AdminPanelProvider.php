<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
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
            // Resolved lazily, not at registration time, so the product name can
            // change through the settings screen, config or .env without
            // touching this provider or requiring a deploy.
            ->brandName(fn (): string => setting('brand_name', 'brand.name'))
            ->favicon(asset('favicon.ico'))
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
                NavigationGroup::make('Master Data'),
                NavigationGroup::make('Administration'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
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
