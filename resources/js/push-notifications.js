import { applicationKey, keyMatches, waitForWorker, workerFor } from './push-support.js';

export async function boot({ loadMessaging = () => import('firebase/messaging'), loadApp = () => import('firebase/app') } = {}) {
    const element = document.getElementById('school-push-config');
    if (!element) return;
    const ui = window.schoolPushActivation;
    ui?.loaded();
    let config;
    try { config = JSON.parse(element.textContent); }
    catch {
        const status = document.querySelector('[data-push-status]');
        if (status) status.textContent = 'إعدادات الإشعارات غير صالحة. يرجى مراجعة إدارة المدرسة.';
        ui?.failure('إعدادات الإشعارات غير صالحة. يرجى مراجعة إدارة المدرسة.', true);
        return;
    }
    const supported = window.isSecureContext && typeof navigator.serviceWorker?.register === 'function'
        && typeof window.PushManager !== 'undefined' && typeof window.Notification?.requestPermission === 'function';
    let panel = document.querySelector('[data-push-panel]');
    if (!panel && !ui && config.firebase && supported) {
        panel = document.createElement('section');
        panel.className = 'device-push-settings';
        panel.dataset.pushPanel = '';
        panel.innerHTML = '<button type="button" data-push-enable>تفعيل إشعارات الجهاز</button> <button type="button" data-push-disable hidden>إيقاف إشعارات الجهاز</button><p data-push-status role="status" aria-live="polite"></p>';
        document.body.append(panel);
    }
    const enable = panel?.querySelector('[data-push-enable]');
    const disable = panel?.querySelector('[data-push-disable]');
    const status = panel?.querySelector('[data-push-status]');
    const say = (message) => { if (status) status.textContent = message; ui?.progress(message); };
    const fail = (message) => { say(message); ui?.failure(message); };
    if (!supported) {
        if (enable) enable.disabled = true;
        ui?.bind(() => location.reload());
        fail('هذا المتصفح لا يدعم الإشعارات، أو أن الاتصال غير آمن. استخدم متصفحًا يدعم الإشعارات عبر HTTPS.');
        return;
    }

    let deviceId;
    try {
        deviceId = localStorage.getItem('school-push-device') || crypto.randomUUID();
        localStorage.setItem('school-push-device', deviceId);
    } catch {
        enable?.addEventListener('click', () => location.reload());
        if (panel) panel.dataset.pushInitialized = 'true';
        ui?.bind(() => location.reload());
        fail('يلزم السماح بالتخزين المحلي لتفعيل إشعارات هذا الجهاز. اسمح به من إعدادات الموقع ثم أعد المحاولة.');
        return;
    }
    let messaging;
    let sdk;
    let currentToken;
    let legacyId;
    let currentRegistration;
    let busy = false;
    let lastRefresh = 0;
    const optedOut = () => localStorage.getItem('school-push-opt-out') === 'true';
    const request = async (url, method, body) => {
        const response = await fetch(url, {
            method, credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify(body),
        });
        if (!response.ok) throw new Error([401, 403, 419].includes(response.status) ? 'session-expired' : 'registration-failed');
        const result = await response.json();
        if (url === config.storeUrl && result.ok !== true) throw new Error('registration-failed');
        return result;
    };
    const registration = async () => {
        const target = workerFor(location.pathname);
        return waitForWorker(await navigator.serviceWorker.register(target.script, { scope: target.scope, updateViaCache: 'none' }));
    };
    const legacyEndpoints = async () => {
        const registrations = await navigator.serviceWorker.getRegistrations();
        const subscriptions = await Promise.all(registrations.filter((item) =>
            ['/app-sw.js', '/parent-sw.js'].includes(new URL(item.active?.scriptURL || location.href).pathname)
        ).map((item) => item.pushManager.getSubscription()));
        return subscriptions.filter(Boolean).map((item) => item.endpoint).slice(0, 5);
    };
    const legacy = async (existing) => {
        if (!config.legacyVapidKey || (existing && !keyMatches(existing, config.legacyVapidKey))) throw new Error('vapid-key-mismatch');
        const subscription = existing || await currentRegistration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: applicationKey(config.legacyVapidKey) });
        const result = await request(config.legacyStoreUrl, 'POST', { ...subscription.toJSON(), device_id: deviceId });
        legacyId = result.id;
        fail('تم تسجيل اشتراك المتصفح بالنظام السابق. تفعيل Firebase غير مكتمل.');
    };
    const connect = async (interactive = false) => {
        if (busy) return;
        if (!interactive && optedOut()) {
            fail('إشعارات هذا الجهاز غير مفعّلة. فعّل الإشعارات لتصلك تنبيهات الواجبات والاختبارات والنتائج.');
            return;
        }
        busy = true;
        if (enable) enable.disabled = true;
        let stage = 'permission';
        let fcmRegistered = false;
        say('جارٍ التحقق من إذن الإشعارات…');
        try {
            // Only a deliberate button click may trigger the permission prompt.
            if (interactive && Notification.permission === 'default') await Notification.requestPermission();
            if (Notification.permission !== 'granted') {
                fail(Notification.permission === 'denied'
                    ? 'إشعارات هذا الموقع محظورة. افتح إعدادات الموقع في المتصفح، واسمح بالإشعارات، ثم اضغط إعادة المحاولة.'
                    : 'لم يتم منح إذن الإشعارات. اضغط زر التفعيل للمحاولة مجددًا.');
                return;
            }
            if (!config.firebase && !config.legacyVapidKey) throw new Error('configuration-missing');
            if (config.firebase && !config.vapidKey) throw new Error('configuration-missing');
            stage = 'worker';
            say('جارٍ تجهيز خدمة إشعارات الجهاز…');
            currentRegistration = await registration();
            stage = 'subscription';
            const existing = await currentRegistration.pushManager.getSubscription();
            // A page load refreshes existing devices; it must not silently subscribe new ones.
            if (!interactive && !existing) {
                fail('إذن الإشعارات ممنوح، لكن الجهاز غير مسجل لاستقبال الإشعارات. اضغط إعادة المحاولة لتسجيله.');
                return;
            }
            if (config.firebase && (!existing || keyMatches(existing, config.vapidKey))) {
                stage = 'firebase';
                sdk ||= await loadMessaging();
                if (await sdk.isSupported()) {
                    if (!messaging) {
                        const { initializeApp } = await loadApp();
                        messaging = sdk.getMessaging(initializeApp(config.firebase, 'school-push'));
                        sdk.onMessage(messaging, (message) => {
                            if (message.data?.recipient_id !== config.userId) return;
                            // The worker's atomic receipt store handles simultaneous focused tabs.
                            currentRegistration.active?.postMessage({ type: 'SCHOOL_FOREGROUND_PUSH', payload: message.data });
                            document.dispatchEvent(new CustomEvent('school:notification', { detail: message.data }));
                        });
                    }
                    stage = 'token';
                    say('جارٍ إنشاء رمز إشعارات الجهاز…');
                    currentToken = await sdk.getToken(messaging, { vapidKey: config.vapidKey, serviceWorkerRegistration: currentRegistration });
                    if (!currentToken) throw new Error('token-unavailable');
                    const subscription = await currentRegistration.pushManager.getSubscription();
                    if (!subscription) throw new Error('subscription-unavailable');
                    stage = 'backend';
                    say('جارٍ تسجيل الجهاز في حسابك…');
                    await request(config.storeUrl, 'POST', { token: currentToken, device_id: deviceId, endpoint: subscription.endpoint, legacy_endpoints: await legacyEndpoints() });
                    fcmRegistered = true;
                    say('تم تفعيل إشعارات هذا الجهاز.');
                    ui?.registered();
                } else {
                    stage = 'legacy';
                    await legacy(existing);
                }
            } else {
                // Never unsubscribe/replace an existing VAPID subscription to force a key change.
                stage = 'legacy';
                await legacy(existing);
            }
            lastRefresh = Date.now();
            localStorage.removeItem('school-push-opt-out');
            // Only the authenticated FCM acknowledgement completes FCM activation.
            // Keep legacy-only registration feedback visible.
            if (panel) panel.hidden = fcmRegistered;
        } catch (error) {
            const messages = {
                permission: 'تعذر طلب إذن الإشعارات. راجع أذونات الموقع في المتصفح.',
                worker: 'تعذر تشغيل خدمة إشعارات الجهاز. أعد تحميل الصفحة ثم حاول مجددًا.',
                subscription: 'تعذر قراءة اشتراك إشعارات المتصفح. أعد المحاولة.',
                firebase: 'تعذر تحميل أو تهيئة Firebase. يرجى مراجعة إدارة المدرسة.',
                token: 'تعذر إنشاء رمز إشعارات الجهاز. يرجى مراجعة إدارة المدرسة وإعادة المحاولة.',
                backend: 'تعذر تسجيل الجهاز في حسابك. لم يكتمل التفعيل؛ أعد المحاولة لاحقًا.',
                legacy: 'تعذر تسجيل اشتراك المتصفح بالنظام السابق. لم يكتمل التفعيل.',
            };
            fail(error?.message === 'configuration-missing'
                ? 'إعدادات الإشعارات غير مكتملة. يرجى مراجعة إدارة المدرسة.'
                : error?.message === 'session-expired'
                    ? 'تعذر توثيق تسجيل الجهاز. أعد تحميل الصفحة وسجّل الدخول ثم حاول مجددًا.'
                    : messages[stage]);
        } finally {
            busy = false;
            if (enable) enable.disabled = false;
        }
    };
    enable?.addEventListener('click', () => { void connect(true); });
    if (panel) panel.dataset.pushInitialized = 'true';
    ui?.bind(connect);
    const disconnect = async () => {
        if (busy) return;
        busy = true;
        disable.disabled = true;
        try {
            if (currentToken) {
                await request(config.deleteUrl, 'DELETE', { token: currentToken });
                await sdk.deleteToken(messaging);
                currentToken = undefined;
            } else if (legacyId) {
                await request(`${config.legacyStoreUrl}/${legacyId}`, 'DELETE', {});
                await (await currentRegistration.pushManager.getSubscription())?.unsubscribe();
            }
            localStorage.setItem('school-push-opt-out', 'true');
            say('تم إيقاف إشعارات هذا الجهاز.');
            if (panel) panel.hidden = false;
            disable.hidden = true;
            if (enable) enable.hidden = false;
        } catch { say('تعذر إيقاف الإشعارات الآن. أعد المحاولة.'); }
        finally { busy = false; disable.disabled = false; }
    };
    disable?.addEventListener('click', disconnect);
    const refresh = () => {
        if (document.visibilityState === 'visible' && Notification.permission === 'granted' && Date.now() - lastRefresh > 15 * 60 * 1000) void connect();
    };
    document.addEventListener('visibilitychange', refresh);
    const interval = setInterval(refresh, 60 * 60 * 1000);
    const ready = Notification.permission === 'granted' ? connect() : Promise.resolve();
    return { connect, disconnect, ready, stop: () => clearInterval(interval) };
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => { void boot(); });
    else void boot();
}
