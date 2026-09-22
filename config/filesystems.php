<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Media Disk
    |--------------------------------------------------------------------------
    |
    | Where temple photos are written and served from. Production sets this to
    | 'spaces'. It deliberately defaults to the local 'public' disk so that a
    | fresh clone, the test suite and CI all work with no cloud credentials at
    | all — nobody needs a DigitalOcean account to run the project.
    |
    */

    'media' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        /*
         * Served from this same host, so the URL is root-relative.
         *
         * Laravel's default builds it from APP_URL, which is http://localhost
         * until someone remembers to change it — and on a shared host behind
         * Cloudflare nobody remembers until every photo in the admin panel is
         * a broken image pointing at the visitor's own machine. It is also
         * the wrong scheme the moment the site is served over https with
         * APP_URL still http, which browsers block as mixed content.
         *
         * '/storage' needs none of that: it resolves against whatever domain
         * and scheme the page was actually served from. The same reasoning as
         * App\Support\TempleTheme, which had exactly this bug.
         *
         * Spaces keeps an absolute URL below, because that really is another
         * host.
         */
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => '/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * DigitalOcean Spaces. S3-compatible, so Laravel's own s3 driver works
         * with a custom endpoint — no DigitalOcean-specific package needed.
         *
         * Keeping temple photos here rather than on the web host matters: a
         * shared Hostinger plan has a modest disk quota and no CDN, and a
         * gallery of thousands of temples would exhaust both. Spaces also means
         * the app server can stay small for much longer.
         *
         * 'url' should be the CDN endpoint (…​.cdn.digitaloceanspaces.com), not
         * the origin, so delivered images come off the edge cache.
         */
        'spaces' => [
            'driver' => 's3',
            'key' => env('DO_SPACES_KEY'),
            'secret' => env('DO_SPACES_SECRET'),
            'region' => env('DO_SPACES_REGION', 'blr1'),
            'bucket' => env('DO_SPACES_BUCKET'),
            'endpoint' => env('DO_SPACES_ENDPOINT'),
            'url' => env('DO_SPACES_CDN_ENDPOINT', env('DO_SPACES_ENDPOINT')),
            // Spaces uses virtual-host style addressing, like S3 itself.
            'use_path_style_endpoint' => false,
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
