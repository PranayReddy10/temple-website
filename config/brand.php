<?php

/**
 * The product name is not finalised.
 *
 * Nothing in the codebase hard-codes it: admin panel, emails, API responses and
 * the Flutter app all resolve the name from here. Renaming the product later is
 * an .env change and a config cache clear, not a find-and-replace.
 */
return [
    'name' => env('BRAND_NAME', 'Temple Passport'),

    'tagline' => env('BRAND_TAGLINE', 'Your digital pilgrimage companion'),

    // Temporary domain until the real one is registered.
    'url' => env('BRAND_URL', env('APP_URL', 'http://localhost')),

    'support_email' => env('BRAND_SUPPORT_EMAIL', 'support@example.com'),

    // Email for the first admin account, used by the seeder and by
    // `php artisan db:mysql-dump --with-admin`.
    'admin_email' => env('ADMIN_EMAIL', 'admin@example.com'),

    /*
     * Temple palette. Saffron is the devotional anchor, kumkum red the accent
     * and temple gold the highlight. Filament needs these as RGB triplets so it
     * can generate its own tint scale; the hex values are kept alongside for
     * use in Blade, emails and the Flutter theme.
     */
    'colors' => [
        'saffron' => ['hex' => '#E07A1F', 'rgb' => '224, 122, 31'],
        'kumkum' => ['hex' => '#9B1B30', 'rgb' => '155, 27, 48'],
        'gold' => ['hex' => '#C9A227', 'rgb' => '201, 162, 39'],
        'sandal' => ['hex' => '#F5EBDC', 'rgb' => '245, 235, 220'],
        'deep' => ['hex' => '#3E2723', 'rgb' => '62, 39, 35'],
    ],
];
