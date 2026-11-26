const CACHE_VERSION = 'oatcake-pwa-v3';
const STATIC_CACHE = 'oatcake-static-v3';
const PAGES_CACHE = 'oatcake-pages-v3';
const DATA_CACHE = 'oatcake-data-v3';
const ACTIVE_CACHES = [STATIC_CACHE, PAGES_CACHE, DATA_CACHE];

const PRECACHE_URLS = [
    '/offline',
    '/manifest.webmanifest',
    '/icons/icon-192x192.png',
    '/icons/icon-512x512.png',
    '/icons/icon-maskable-512x512.png',
    '/icons/apple-touch-icon.png',
];

const NETWORK_ONLY_PREFIXES = [
    '/login',
    '/logout',
    '/register',
    '/forgot-password',
    '/reset-password',
    '/verify-email',
    '/confirm-password',
    '/auth/',
    '/leave/ledger/',
    '/notifications',
    '/api',
    '/sanctum',
    '/audit-logs',
    '/attendance/verifications',
    '/settings',
    '/company/settings',
    '/admin/',
    '/profile',
];

const NETWORK_ONLY_PATTERNS = [
    /^\/attendance\/[^/]+\/proof(?:\/|$)/,
    /^\/leave\/[^/]+\/attachment(?:\/|$)/,
    /^\/leave\/[^/]+\/smart-analysis(?:\/|$)/,
];

function isSensitiveOrPrivateRequest(url) {
    const pathname = url.pathname;

    if (NETWORK_ONLY_PREFIXES.some((prefix) => pathname === prefix || pathname.startsWith(prefix))) {
        return true;
    }

    return NETWORK_ONLY_PATTERNS.some((pattern) => pattern.test(pathname));
}

function isStaticAssetRequest(request, url) {
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || url.pathname === '/manifest.webmanifest') {
        return true;
    }

    return ['style', 'script', 'font'].includes(request.destination);
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(async (cache) => {
            await Promise.allSettled(
                PRECACHE_URLS.map(async (url) => {
                    const response = await fetch(url, { cache: 'reload' });
                    if (response && response.ok) {
                        await cache.put(url, response);
                    }
                })
            );
        }).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) =>
            Promise.all(
                cacheNames
                    .filter((cacheName) => !ACTIVE_CACHES.includes(cacheName))
                    .map((cacheName) => caches.delete(cacheName))
            )
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('message', (event) => {
    if (!event.data || typeof event.data !== 'object') {
        return;
    }

    if (event.data.type === 'CLEAR_USER_CACHE') {
        event.waitUntil(
            Promise.all([
                caches.delete(PAGES_CACHE),
                caches.delete(DATA_CACHE),
            ]).finally(() => {
                if (event.ports && event.ports[0]) {
                    event.ports[0].postMessage({ cleared: true });
                }
            })
        );
    }
});

self.addEventListener('push', (event) => {
    let payload = {
        title: 'OATCAKE',
        body: '',
        icon: '/icons/icon-192x192.png',
        badge: '/icons/icon-192x192.png',
        tag: 'oatcake-notification',
        data: { url: '/dashboard' },
    };

    if (event.data) {
        try {
            const parsed = event.data.json();
            if (parsed && typeof parsed === 'object') {
                payload = {
                    ...payload,
                    ...parsed,
                    data: parsed.data && typeof parsed.data === 'object' ? parsed.data : { url: '/dashboard' },
                };
            }
        } catch (e) {
            payload.body = event.data.text();
        }
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || 'OATCAKE', {
            body: payload.body || '',
            icon: payload.icon || '/icons/icon-192x192.png',
            badge: payload.badge || '/icons/icon-192x192.png',
            tag: payload.tag || 'oatcake-notification',
            data: payload.data || { url: '/dashboard' },
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetPath = (event.notification.data && event.notification.data.url) || '/dashboard';
    const targetUrl = new URL(targetPath, self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            for (const client of clientList) {
                if (client.url.startsWith(self.location.origin) && 'focus' in client) {
                    return client.focus().then((focused) => {
                        if (focused && 'navigate' in focused) {
                            return focused.navigate(targetUrl);
                        }
                        return focused;
                    });
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl);
            }
            return undefined;
        })
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // 1. Strict Network-Only for all mutations (POST, PUT, PATCH, DELETE, etc.)
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Ignore cross-origin requests (e.g. external CDN fonts/tiles)
    if (url.origin !== self.location.origin) {
        return;
    }

    // 2. Strict Network-Only for auth routes and private media/proof endpoints
    if (isSensitiveOrPrivateRequest(url)) {
        return;
    }

    // 3. Network-First for the read-only offline snapshot JSON endpoint
    if (url.pathname === '/pwa/offline-snapshot') {
        event.respondWith(
            fetch(request)
                .then(async (networkResponse) => {
                    if (
                        networkResponse &&
                        networkResponse.status === 200 &&
                        networkResponse.type === 'basic' &&
                        !new URL(networkResponse.url).pathname.startsWith('/login')
                    ) {
                        const cache = await caches.open(DATA_CACHE);
                        await cache.put(request, networkResponse.clone());
                    }
                    return networkResponse;
                })
                .catch(async () => {
                    const cache = await caches.open(DATA_CACHE);
                    const cached = await cache.match(request);
                    if (cached) {
                        return cached;
                    }
                    return new Response(
                        JSON.stringify({ offline: true, error: 'Snapshot not cached yet' }),
                        { status: 503, headers: { 'Content-Type': 'application/json' } }
                    );
                })
        );
        return;
    }

    // 4. Cache-First (with background revalidation) for static assets
    if (isStaticAssetRequest(request, url)) {
        event.respondWith(
            caches.open(STATIC_CACHE).then(async (cache) => {
                const cachedResponse = await cache.match(request);

                const networkPromise = fetch(request)
                    .then(async (networkResponse) => {
                        if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
                            await cache.put(request, networkResponse.clone());
                        }
                        return networkResponse;
                    })
                    .catch(() => null);

                if (cachedResponse) {
                    event.waitUntil(networkPromise);
                    return cachedResponse;
                }

                const networkResponse = await networkPromise;
                return networkResponse || new Response('', { status: 504, statusText: 'Gateway Timeout' });
            })
        );
        return;
    }

    // 5. Network-First with PAGES_CACHE and /offline fallback for page navigations
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then(async (networkResponse) => {
                    const finalUrl = networkResponse.url ? new URL(networkResponse.url) : url;
                    const finalPath = finalUrl.pathname;
                    if (
                        networkResponse &&
                        networkResponse.status === 200 &&
                        networkResponse.type === 'basic' &&
                        finalPath !== '/login' &&
                        finalPath !== '/offline' &&
                        !isSensitiveOrPrivateRequest(finalUrl)
                    ) {
                        const cache = await caches.open(PAGES_CACHE);
                        await cache.put(request, networkResponse.clone());
                    }
                    return networkResponse;
                })
                .catch(async () => {
                    const pagesCache = await caches.open(PAGES_CACHE);
                    const cachedPage = await pagesCache.match(request);
                    if (cachedPage) {
                        return cachedPage;
                    }

                    const staticCache = await caches.open(STATIC_CACHE);
                    const offlinePage = await staticCache.match('/offline');
                    return offlinePage || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
                })
        );
    }
});
