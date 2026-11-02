import { initializeApp } from 'firebase/app';
import { getMessaging, onBackgroundMessage } from 'firebase/messaging/sw';

const config = {
    apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
    projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
    messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
    appId: import.meta.env.VITE_FIREBASE_APP_ID,
};

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const raw = event.notification.data?.url || '/login';
    let destination;
    try {
        destination = new URL(raw, self.location.origin);
    } catch {
        destination = new URL('/login', self.location.origin);
    }
    if (destination.origin !== self.location.origin || !/^\/(admin|teacher|student|parent)(\/|$)/.test(destination.pathname)) {
        destination = new URL('/login', self.location.origin);
    }

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const existing = windows.find((client) => new URL(client.url).origin === self.location.origin);
        if (existing) {
            await existing.navigate(destination.href);
            await existing.focus();
        } else {
            await self.clients.openWindow(destination.href);
        }
    })());
});

if (config.apiKey && config.projectId && config.messagingSenderId && config.appId) {
    const app = initializeApp(config);
    onBackgroundMessage(getMessaging(app), ({ data = {} }) => self.registration.showNotification(data.title || 'إشعار جديد', {
        body: data.body || '',
        data: { url: data.url || '/login' },
    }));
}
