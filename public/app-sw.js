importScripts('/js/push-notification-display.js');
try { importScripts('/push/firebase-worker.js'); } catch (_) {}
const CACHE_NAME = 'afhamha-shell-v5-fcm';
const OFFLINE_URL = '/offline.html';
const APP_ASSETS = [
    OFFLINE_URL,
    '/images/nasbia-school-logo.png',
    '/school-branding/icon-180.png?v=20261029-nasbia',
    '/school-branding/icon-192.png?v=20261029-nasbia',
    '/school-branding/icon-512.png?v=20261029-nasbia',
    '/manifest.webmanifest'
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

    if (APP_ASSETS.includes(url.pathname + url.search) || APP_ASSETS.includes(url.pathname)) {
        event.respondWith(caches.match(request).then(cached => cached || fetch(request)));
    }
});
