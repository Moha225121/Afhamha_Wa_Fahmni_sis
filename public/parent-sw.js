const CACHE_NAME = 'afhamha-parent-static-v9';
importScripts('/js/school-push-privacy.js?v=20261109');
const OFFLINE_URL = '/parent-offline.html';
const STATIC_ASSETS = [
    OFFLINE_URL,
    '/favicon.ico',
    '/icons/nasbia-logo.jpg',
    '/school-branding/icon-192.png',
    '/school-branding/icon-512.png',
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

self.addEventListener('push', (event) => {
    const payload = event.data ? event.data.json() : {};
    event.waitUntil(SchoolPushPrivacy.show(self.registration, payload, {
        icon: '/school-app-icon',
        badge: '/school-branding/icon-192.png',
        dir: 'rtl',
        lang: 'ar',
    }).catch(() => {}));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil((async () => {
        const recipient = await SchoolPushPrivacy.currentRecipient();
        await clients.openWindow(SchoolPushPrivacy.destination(event.notification.data || {}, recipient, self.location.origin));
    })());
});
