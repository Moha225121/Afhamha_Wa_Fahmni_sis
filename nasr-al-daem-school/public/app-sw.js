const CACHE_NAME = 'afhamha-shell-v1';
const OFFLINE_URL = '/offline.html';
const APP_ASSETS = [
    OFFLINE_URL,
    '/manifest.webmanifest',
    '/icons/app-icon-192.png',
    '/icons/app-icon-512.png',
    '/icons/app-maskable-512.png',
    '/icons/apple-touch-icon.png'
];

self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(APP_ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.filter(key => key.startsWith('afhamha-shell-') && key !== CACHE_NAME).map(key => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
        return;
    }

    if (APP_ASSETS.includes(url.pathname)) {
        event.respondWith(caches.match(request).then(cached => cached || fetch(request)));
    }
});

