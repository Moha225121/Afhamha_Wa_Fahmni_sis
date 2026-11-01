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

    const request = (url, options = {}) => fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(options.headers || {}) },
    });

    const setVisible = (visible) => { banner.hidden = !visible; };

    const register = async (messaging) => {
        const registration = await navigator.serviceWorker.register('/app-sw.js', { scope: '/', type: 'module' });
        const token = await getToken(messaging, { vapidKey, serviceWorkerRegistration: registration });
        if (!token) throw new Error('Firebase did not return a registration token.');
        const response = await request('/fcm-devices', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token }),
        });
        if (!response.ok) throw new Error('Token registration failed.');
        message.textContent = 'تم تفعيل إشعارات هذا الجهاز.';
        setVisible(false);
        return registration;
    };

    const start = async () => {
        if (!('serviceWorker' in navigator) || !('Notification' in window) || !(await isSupported())) return;
        const statusResponse = await request('/fcm-devices/status');
        if (!statusResponse.ok) return;
        const status = await statusResponse.json();
        if (!status.configured) return;
        if (status.project_id !== config.projectId) {
            message.textContent = 'إعدادات إشعارات المدرسة غير متطابقة. يرجى التواصل مع الإدارة.';
            setVisible(true);
            enableButton.hidden = true;
            return;
        }

        if (Notification.permission === 'granted') {
            let registration = null;
            try { registration = await register(getMessaging(app)); } catch { setVisible(true); }
            if (registration) {
                onMessage(getMessaging(app), (payload) => {
                    const data = payload.data || {};
                    registration.showNotification(data.title || 'إشعار جديد', {
                        body: data.body || '',
                        data: { url: data.url || '/login' },
                    });
                });
            }
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

    start().catch(() => {});
}
