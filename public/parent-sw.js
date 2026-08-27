const CACHE_NAME = 'afhamha-parent-static-v7';
const OFFLINE_URL = '/parent-offline.html';
const STATIC_ASSETS = [
    OFFLINE_URL,
    '/favicon.ico',
    '/icons/parent-icon.svg',
    '/icons/parent-icon-192.png',
    '/icons/parent-icon-512.png',
    '/icons/parent-maskable-512.png',
    '/parent-manifest.webmanifest'
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
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
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

    if (STATIC_ASSETS.includes(url.pathname)) {
        event.respondWith(caches.match(request).then((cached) => cached || fetch(request)));
    }
});

self.addEventListener('push', (event) => {
    const payload = event.data ? event.data.json() : {};
    event.waitUntil(self.registration.showNotification(payload.title || 'افهمها وفهمني', {
        body: payload.body || 'لديك إشعار جديد.',
        icon: '/icons/parent-icon-192.png',
        badge: '/icons/parent-maskable-512.png',
        data: { url: payload.url || '/parent/notifications' },
        dir: 'rtl',
        lang: 'ar',
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(clients.openWindow(event.notification.data.url));
});
