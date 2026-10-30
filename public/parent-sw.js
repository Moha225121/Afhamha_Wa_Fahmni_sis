importScripts('/js/push-notification-display.js');
try { importScripts('/push/firebase-worker.js'); } catch (_) {}
const CACHE_NAME = 'afhamha-parent-static-v11-nasbia';
const OFFLINE_URL = '/parent-offline.html';
const STATIC_ASSETS = [
    OFFLINE_URL,
    '/favicon.ico',
    '/icons/parent-icon.svg',
    '/icons/parent-icon-192.png',
    '/icons/parent-icon-512.png',
    '/icons/parent-maskable-512.png',
    '/school-app-icon'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
    );
});
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('afhamha-parent-static-') && key !== CACHE_NAME).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate' && url.pathname.startsWith('/parent')) {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
        return;
    }

    if (url.pathname === '/school-app-icon') {
        event.respondWith(fetch(request).then((response) => caches.open(CACHE_NAME)
            .then((cache) => cache.put(request, response.clone()))
            .then(() => response)
        ).catch(() => caches.match(request)));
        return;
    }

    if (STATIC_ASSETS.includes(url.pathname)) {
        event.respondWith(caches.match(request).then((cached) => cached || fetch(request)));
    }
});
