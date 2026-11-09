import { initializeApp } from 'firebase/app';
import { getMessaging, onBackgroundMessage } from 'firebase/messaging/sw';
import '../../public/js/school-push-privacy.js';

const config = {
    apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
    projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
    messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
    appId: import.meta.env.VITE_FIREBASE_APP_ID,
};

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil((async () => {
        const recipient = await SchoolPushPrivacy.currentRecipient();
        const destination = SchoolPushPrivacy.destination(event.notification.data || {}, recipient, self.location.origin);
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const existing = windows.find((client) => new URL(client.url).origin === self.location.origin);
        if (existing) {
            await existing.navigate(destination);
            await existing.focus();
        } else {
            await self.clients.openWindow(destination);
        }
    })());
});

if (config.apiKey && config.projectId && config.messagingSenderId && config.appId) {
    const app = initializeApp(config);
    onBackgroundMessage(getMessaging(app), ({ data = {} }) => SchoolPushPrivacy.show(self.registration, data).catch(() => {}));
}
