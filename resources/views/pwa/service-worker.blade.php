{{--
    The service worker.

    Written defensively, because the failure mode of a service worker on an
    admin panel is not "no offline support" — it is somebody seeing a cached
    page from before a deploy, saving a form against a CSRF token that expired
    three days ago, and not being able to clear it by refreshing.

    So it caches almost nothing:

      - **Never a document.** Every page is fetched from the network. Filament
        pages carry a CSRF token, a Livewire snapshot and whatever the signed-in
        account is allowed to see; a cached one is wrong the moment it is
        stored, and wrong in ways that look like a bug in the panel rather than
        a bug in the cache.
      - **Never anything but GET.** Livewire's updates, every form and every
        upload are POSTs. Nothing here touches them.
      - **Only static assets, by path.** Stylesheets, scripts, fonts and icons,
        which are versioned in their URLs and so are safe to keep.

    What that buys is the thing that actually matters on a phone: the panel
    opens instantly on a slow connection instead of waiting on a megabyte of
    Filament's CSS, and a dropped connection shows a page that says so rather
    than the browser's dinosaur.
--}}
const VERSION = '{{ $version }}';
const ASSET_CACHE = 'temple-assets-' + VERSION;
const SHELL_CACHE = 'temple-shell-' + VERSION;
const OFFLINE_URL = '/offline';

/* Prefixes whose contents are safe to keep: versioned in their URLs, and the
   same for every visitor whether signed in or not. */
const CACHEABLE_PREFIXES = [
    '/css/',
    '/js/',
    '/fonts/',
    '/icons/',
    '/livewire/livewire.js',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE)
            .then((cache) => cache.addAll([OFFLINE_URL, '/icons/icon-192.png']))
            // An install that cannot reach the network must not fail: the
            // worker is still worth having for the next visit.
            .catch(() => {})
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(
                names
                    .filter((name) => name.startsWith('temple-') && !name.endsWith(VERSION))
                    .map((name) => caches.delete(name)),
            ))
            // Takes over open pages straight away. Safe only because no
            // document is ever served from the cache — the new worker cannot
            // hand anybody an old page.
            .then(() => self.clients.claim()),
    );
});

function isCacheableAsset(url) {
    return CACHEABLE_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Another origin's business. Uploaded media may live on a CDN, and
    // caching somebody else's bucket here helps nobody.
    if (url.origin !== self.location.origin) {
        return;
    }

    /* A page. Always from the network; the offline page only when there is no
       network at all. */
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL).then(
                (cached) => cached || new Response(
                    'Offline, and the offline page was never cached.',
                    { status: 503, headers: { 'Content-Type': 'text/plain' } },
                ),
            )),
        );

        return;
    }

    if (!isCacheableAsset(url)) {
        return;
    }

    /* Stale while revalidate: answer from the cache if it is there, and
       replace it in the background. These URLs carry a version, so a stale
       answer is a stale answer to an old question — the new deploy asks for a
       different URL. */
    event.respondWith(
        caches.open(ASSET_CACHE).then((cache) => cache.match(request).then((cached) => {
            const network = fetch(request).then((response) => {
                // Only a complete, successful answer. Caching a 404 or a
                // partial response is how an asset stays broken after the
                // deploy that fixed it.
                if (response.ok && response.status === 200 && response.type === 'basic') {
                    cache.put(request, response.clone());
                }

                return response;
            }).catch(() => cached);

            return cached || network;
        })),
    );
});
