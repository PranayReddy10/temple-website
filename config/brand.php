<?php

/**
 * The product name is not finalised.
 *
 * Nothing in the codebase hard-codes it: admin panel, emails, API responses and
 * the Flutter app all resolve the name from here. Renaming the product later is
 * an .env change and a config cache clear, not a find-and-replace.
 */
return [
    'name' => env('BRAND_NAME', 'Darshan Saathi'),

    'tagline' => env('BRAND_TAGLINE', 'Your digital pilgrimage companion'),

    // Where the QR codes point (passport, booking and temple check-in
    // pages), so it must be this server: temple.darshansaathi.com, which
    // also serves the admin panel and the API. darshansaathi.com itself is
    // the devotees' website, rendered by this same application.
    'url' => env('BRAND_URL', env('APP_URL', 'http://localhost')),

    // The devotees' website, linked from this server's home page.
    'website' => env('BRAND_WEBSITE', 'https://darshansaathi.com'),

    // The calendar the devotional day follows. The server clock stays UTC;
    // "today's deity" is decided in this zone. See App\Support\DevotionalClock.
    'timezone' => env('DEVOTIONAL_TIMEZONE', 'Asia/Kolkata'),

    'support_email' => env('BRAND_SUPPORT_EMAIL', 'support@darshansaathi.com'),

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
        // The panel's own page background, named here so the installed app's
        // splash screen matches what opens behind it rather than flashing white.
        'surface' => ['hex' => '#FFFDF9', 'rgb' => '255, 253, 249'],
    ],
];
