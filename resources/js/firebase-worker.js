import { initializeApp } from 'firebase/app';
import { getMessaging, onBackgroundMessage } from 'firebase/messaging/sw';
import { backgroundPayload } from './push-support.js';

// Imported by BOTH existing workers. Never registered as a new worker.
const messaging = getMessaging(initializeApp(self.schoolFirebaseConfig));
onBackgroundMessage(messaging, (message) => {
    const payload = backgroundPayload(message);
    if (payload) return self.schoolPush.show(payload);
});
