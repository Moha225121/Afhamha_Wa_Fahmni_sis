import { getMessaging, getToken, isSupported, onMessage } from 'firebase/messaging';
import { initializeApp } from 'firebase/app';

const config = {
    apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
    projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
    messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
    appId: import.meta.env.VITE_FIREBASE_APP_ID,
};
const vapidKey = import.meta.env.VITE_FIREBASE_VAPID_KEY;
const banner = document.querySelector('[data-fcm-banner]');

if (banner && config.apiKey && config.projectId && config.messagingSenderId && config.appId && vapidKey) {
    const enableButton = banner.querySelector('[data-fcm-enable]');
    const message = banner.querySelector('[data-fcm-message]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const app = initializeApp(config);
    let foregroundListenerInstalled = false;

    const request = (url, options = {}) => fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(options.headers || {}) },
    });

    const setVisible = (visible) => { banner.hidden = !visible; };

    const waitForActiveWorker = async (registration) => {
        if (registration.active) return;
        const worker = registration.installing || registration.waiting;
        if (!worker) throw new Error('Firebase service worker is unavailable.');
        await new Promise((resolve, reject) => {
            const finish = (error) => {
                clearTimeout(timer);
                worker.removeEventListener('statechange', check);
                error ? reject(error) : resolve();
            };
            const check = () => {
                if (worker.state === 'activated') finish();
                if (worker.state === 'redundant') finish(new Error('Firebase service worker failed to activate.'));
            };
            const timer = setTimeout(() => finish(new Error('Firebase service worker activation timed out.')), 15000);
            worker.addEventListener('statechange', check);
            check();
        });
    };

    const register = async (messaging) => {
        const registration = await navigator.serviceWorker.register('/firebase-messaging-sw.js', {
            scope: '/firebase-cloud-messaging-push-scope/',
            type: 'module',
            updateViaCache: 'none',
        });
        await waitForActiveWorker(registration);
        const token = await getToken(messaging, { vapidKey, serviceWorkerRegistration: registration });
        if (!token) throw new Error('Firebase did not return a registration token.');
        const response = await request('/fcm-devices', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token }),
        });
        if (!response.ok) throw new Error('Token registration failed.');
        if (!foregroundListenerInstalled) {
            onMessage(messaging, (payload) => {
                const data = payload.data || {};
                registration.showNotification(data.title || 'إشعار جديد', {
                    body: data.body || '',
                    data: { url: data.url || '/login' },
                });
            });
            foregroundListenerInstalled = true;
        }
        message.textContent = 'تم تفعيل إشعارات هذا الجهاز.';
        setVisible(false);
        return registration;
    };

    const start = async () => {
        if (!('serviceWorker' in navigator) || !('Notification' in window) || !(await isSupported())) return;
        const statusResponse = await request('/fcm-devices/status');
        if (!statusResponse.ok) throw new Error('Unable to check device registration.');
        const status = await statusResponse.json();
        if (!status.configured) return;
        if (status.project_id !== config.projectId) {
            message.textContent = 'إعدادات إشعارات المدرسة غير متطابقة. يرجى التواصل مع الإدارة.';
            setVisible(true);
            enableButton.hidden = true;
            return;
        }

        if (Notification.permission === 'granted') {
            try { await register(getMessaging(app)); } catch { setVisible(true); }
            return;
        }

        setVisible(true);
        if (Notification.permission === 'denied') {
            message.textContent = 'الإشعارات محظورة في إعدادات المتصفح. غيّر الإذن من إعدادات الموقع ثم أعد تحميل الصفحة.';
            enableButton.hidden = true;
        }
    };

    enableButton?.addEventListener('click', async () => {
        enableButton.disabled = true;
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                message.textContent = 'لم يتم السماح بالإشعارات. يمكنك تغيير الإذن لاحقًا من إعدادات المتصفح.';
                return;
            }
            await register(getMessaging(app));
        } catch {
            message.textContent = 'تعذر تسجيل هذا الجهاز. تحقق من الاتصال ثم أعد المحاولة.';
        } finally {
            enableButton.disabled = false;
        }
    });

    start().catch(() => { setVisible(true); });
}
