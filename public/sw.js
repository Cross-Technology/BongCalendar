/*
 * BongCalendar service worker.
 *
 * The app is server-rendered, authenticated and multi-tenant, so the guiding
 * rule here is: never serve one person's HTML to another. Pages are fetched
 * from the network every time and are never written to a cache — the only
 * thing offline gets is a fallback page. What *is* cached is the immutable,
 * public stuff: Vite's content-hashed bundles, fonts and icons.
 *
 * Bump VERSION to retire every previously cached response.
 */
const VERSION = 'v1';
const SHELL_CACHE = `bong-shell-${VERSION}`;
const ASSET_CACHE = `bong-assets-${VERSION}`;
const OFFLINE_URL = '/offline.html';

/** Requests that must always hit the network, untouched. */
const NEVER_INTERCEPT = ['/livewire', '/api', '/broadcasting', '/login', '/logout', '/register'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(SHELL_CACHE)
            .then((cache) => cache.addAll([OFFLINE_URL, '/manifest.webmanifest']))
            // A missing icon must not block the whole install.
            .catch(() => undefined)
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key.startsWith('bong-') && key !== SHELL_CACHE && key !== ASSET_CACHE)
                        .map((key) => caches.delete(key))
                )
            )
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Anything that changes state — Livewire updates, form posts — goes
    // straight to the network. Replaying or caching these would be a bug.
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (NEVER_INTERCEPT.some((path) => url.pathname === path || url.pathname.startsWith(path + '/'))) {
        return;
    }

    // Pages: always network, never stored. Offline falls back to one static
    // page rather than a stale dashboard belonging to whoever logged in last.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() =>
                caches.match(OFFLINE_URL, { cacheName: SHELL_CACHE }).then(
                    (cached) =>
                        cached ||
                        new Response('<h1>Offline</h1>', {
                            status: 503,
                            headers: { 'Content-Type': 'text/html; charset=utf-8' },
                        })
                )
            )
        );

        return;
    }

    // Vite output is content-hashed, so a hit is always correct: serve from
    // cache and skip the network entirely.
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request, ASSET_CACHE));

        return;
    }

    // Icons and the manifest change rarely: serve what we have, refresh behind.
    if (url.pathname.startsWith('/icons/') || url.pathname === '/manifest.webmanifest' || url.pathname === '/favicon.ico') {
        event.respondWith(staleWhileRevalidate(request, SHELL_CACHE));
    }
});

async function cacheFirst(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response && response.ok && response.type === 'basic') {
        cache.put(request, response.clone());
    }

    return response;
}

async function staleWhileRevalidate(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    const network = fetch(request)
        .then((response) => {
            if (response && response.ok && response.type === 'basic') {
                cache.put(request, response.clone());
            }

            return response;
        })
        .catch(() => cached);

    return cached || network;
}
