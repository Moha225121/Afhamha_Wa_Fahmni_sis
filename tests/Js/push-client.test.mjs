import assert from 'node:assert/strict';
import test from 'node:test';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
import { boot } from '../../resources/js/push-notifications.js';
import { applicationKey } from '../../resources/js/push-support.js';

async function clientTest(options, run) {
    const restored = new Map();
    const set = (name, value) => {
        restored.set(name, Object.getOwnPropertyDescriptor(globalThis, name));
        Object.defineProperty(globalThis, name, { configurable: true, writable: true, value });
    };
    const config = { firebase: { projectId: 'test-project' }, vapidKey: 'AQID', legacyVapidKey: 'AQIE', storeUrl: '/fcm-tokens', deleteUrl: '/fcm-tokens', legacyStoreUrl: '/push-subscriptions', userId: '12', loginId: options.loginId || 'session-one', ...options.config };
    const enable = new EventTarget();
    const disable = new EventTarget();
    const status = {};
    const panel = { dataset: {}, querySelector: (selector) => ({ '[data-push-enable]': enable, '[data-push-disable]': disable, '[data-push-status]': status })[selector] };
    const document = new EventTarget();
    const reminders = [];
    document.readyState = 'loading';
    document.getElementById = () => options.missingConfig ? null : ({ textContent: JSON.stringify(config) });
    document.querySelector = (selector) => selector === '[data-push-panel]' ? (options.noPanel ? null : panel) : { content: 'csrf-test' };
    document.querySelectorAll = (selector) => selector === '[data-push-panel]' ? (options.noPanel ? [] : [panel])
        : selector === '[data-push-status]' ? [...(options.noPanel ? [] : [status]), ...reminders.map((item) => item.querySelector(selector))] : [];
    document.createElement = () => {
        const children = new Map();
        return { hidden: false, dataset: {}, setAttribute() {}, querySelector(selector) {
            if (!children.has(selector)) children.set(selector, new EventTarget());
            return children.get(selector);
        } };
    };
    document.body = { append: (item) => reminders.push(item) };
    document.visibilityState = 'visible';
    let permissionCalls = 0;
    const notification = { permission: options.permission || 'default', async requestPermission() { permissionCalls++; return (this.permission = options.grant || 'granted'); } };
    let unsubscribed = 0;
    const subscription = { endpoint: 'https://push.test/current', options: { applicationServerKey: applicationKey(options.key || 'AQID') }, async unsubscribe() { unsubscribed++; return true; }, toJSON() { return { endpoint: this.endpoint, keys: { p256dh: 'public', auth: 'auth' } }; } };
    let existing = options.existing ? subscription : null;
    const requests = [];
    const registrations = [];
    const messages = [];
    const registration = { active: { state: 'activated', scriptURL: 'https://school.test/app-sw.js', postMessage(message) { messages.push(message); } }, pushManager: { async getSubscription() { return existing; }, async subscribe() { existing = subscription; return existing; } } };
    let tokenCalls = 0;
    let firebaseLoads = 0;
    let foreground;
    let deleted = 0;
    const sdk = {
        async isSupported() { return !options.firebaseUnsupported; },
        getMessaging() { return {}; },
        onMessage(_messaging, callback) { foreground = callback; },
        async getToken(_messaging, opts) { tokenCalls++; if (options.tokenError) throw new Error('private SDK details'); assert.equal(opts.serviceWorkerRegistration, registration); existing = subscription; return options.emptyToken ? '' : 'registration-token-for-this-device'; },
        async deleteToken() { deleted++; return true; },
    };
    const values = options.storage || new Map();
    let reloads = 0;
    const timers = new Map();
    set('window', { isSecureContext: !options.unsupported, PushManager: {}, Notification: options.noNotification ? undefined : notification });
    set('Notification', options.noNotification ? undefined : notification);
    set('document', document);
    set('location', { pathname: options.path || '/student/notifications', href: 'https://school.test/student/notifications', reload() { reloads++; } });
    set('navigator', { serviceWorker: { async register(script, opts) { if (options.workerError) throw new Error('worker failed'); registrations.push({ script, ...opts }); return registration; }, async getRegistrations() { return [registration]; } } });
    set('localStorage', { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: (key) => values.delete(key) });
    set('fetch', async (url, opts) => { requests.push({ url, ...opts, payload: JSON.parse(opts.body) }); return { ok: !options.backendError, status: options.backendError || 200, async json() { return options.badResponse ? { ok: false } : { ok: true, id: 3 }; } }; });
    set('CustomEvent', class extends Event { constructor(type, options) { super(type); this.detail = options.detail; } });
    let controls;
    try {
        if (options.withUI) vm.runInNewContext(readFileSync(new URL('../../public/js/push-activation-status.js', import.meta.url), 'utf8'), {
            document, window, Notification, localStorage, location,
            setTimeout(callback) { const id = Symbol(); timers.set(id, callback); return id; }, clearTimeout(id) { timers.delete(id); },
        });
        if (!options.loadError) controls = await boot({ async loadMessaging() { firebaseLoads++; if (options.firebaseError) throw new Error('private SDK details'); return sdk; }, async loadApp() { return { initializeApp() { return {}; } }; } });
        await controls?.ready;
        document.dispatchEvent(new Event('DOMContentLoaded'));
        await run({ controls, enable, requests, registrations, messages, status, panel, reminders,
            watchdog: () => { for (const callback of [...timers.values()]) callback(); },
            recover: () => { options.backendError = null; options.tokenError = false; options.firebaseError = false; options.emptyToken = false; },
            foreground: (data) => foreground({ data }), stats: () => ({ permissionCalls, tokenCalls, firebaseLoads, unsubscribed, deleted, reloads }), values });
    } finally {
        controls?.stop();
        for (const [name, descriptor] of restored) {
            if (descriptor) Object.defineProperty(globalThis, name, descriptor);
            else delete globalThis[name];
        }
    }
}

test('permission is requested only after an explicit enable action; registration never sends a user ID', () => clientTest({}, async (client) => {
    assert.equal(client.stats().permissionCalls, 0);
    assert.equal(client.requests.length, 0);
    await client.controls.connect(true);
    assert.equal(client.stats().permissionCalls, 1);
    assert.equal(client.requests[0].payload.token, 'registration-token-for-this-device');
    assert.equal('user_id' in client.requests[0].payload, false);
    assert.equal('userId' in client.requests[0].payload, false);
    assert.equal(client.requests[0].headers['X-CSRF-TOKEN'], 'csrf-test');
    assert.equal(client.requests[0].credentials, 'same-origin');
    assert.equal(client.registrations[0].script, '/app-sw.js');
    assert.equal(client.registrations[0].updateViaCache, 'none');
    assert.equal(client.panel.hidden, true);
}));

test('previously enabled devices refresh without prompting; foreground display is scoped to the signed-in user', () => clientTest({ permission: 'granted', existing: true }, async (client) => {
    assert.equal(client.stats().permissionCalls, 0);
    assert.equal(client.stats().tokenCalls, 1);
    assert.equal(client.panel.hidden, true);
    client.foreground({ recipient_id: '99', notification_id: 'foreign' });
    assert.equal(client.messages.length, 0);
    client.foreground({ recipient_id: '12', notification_id: 'own' });
    assert.equal(client.messages[0].type, 'SCHOOL_FOREGROUND_PUSH');
    await client.controls.disconnect();
    assert.equal(client.panel.hidden, false);
    assert.equal(client.requests.at(-1).method, 'DELETE');
    assert.equal(client.stats().deleted, 1);
    assert.equal(client.values.get('school-push-opt-out'), 'true');
}));

test('denied permission creates no token, subscription, or server request', () => clientTest({ grant: 'denied' }, async (client) => {
    await client.controls.connect(true);
    assert.equal(client.stats().tokenCalls, 0);
    assert.equal(client.requests.length, 0);
    assert.equal(client.registrations.length, 0);
}));

test('a mismatched Firebase key preserves the existing legacy subscription', () => clientTest({ existing: true, permission: 'granted', key: 'AQIE' }, async (client) => {
    assert.equal(client.stats().firebaseLoads, 0);
    assert.equal(client.stats().unsubscribed, 0);
    assert.equal(client.requests[0].url, '/push-subscriptions');
    assert.equal(client.requests[0].payload.endpoint, 'https://push.test/current');
    assert.equal(client.panel.hidden, false);
    assert.match(client.status.textContent, /Firebase غير مكتمل/);
}));

test('the actual button listener requests permission and registers the device', () => clientTest({}, async (client) => {
    client.enable.dispatchEvent(new Event('click'));
    assert.equal(client.stats().permissionCalls, 1);
    for (let i = 0; i < 30 && !client.panel.hidden; i++) await new Promise(setImmediate);
    assert.equal(client.requests[0].url, '/fcm-tokens');
    assert.equal(client.panel.hidden, true);
}));

for (const [name, options, message] of [
    ['blocked permission', { permission: 'denied' }, /محظورة/],
    ['worker registration', { workerError: true }, /خدمة إشعارات/],
    ['Firebase module loading', { firebaseError: true }, /تهيئة Firebase/],
    ['token creation', { tokenError: true }, /إنشاء رمز/],
    ['empty token', { emptyToken: true }, /إنشاء رمز/],
    ['backend failure', { backendError: 500 }, /لم يكتمل التفعيل/],
    ['expired session', { backendError: 419 }, /سجّل الدخول/],
    ['unsuccessful JSON', { badResponse: true }, /لم يكتمل التفعيل/],
    ['missing configuration', { config: { firebase: null, legacyVapidKey: null } }, /غير مكتملة/],
    ['missing Firebase VAPID key', { config: { vapidKey: null } }, /غير مكتملة/],
]) test(`${name} gives Arabic feedback and never reports activation`, () => clientTest(options, async (client) => {
    await client.controls.connect(true);
    assert.match(client.status.textContent, message);
    assert.notEqual(client.panel.hidden, true);
    assert.equal(client.stats().unsubscribed, 0);
    if (options.permission === 'denied') assert.equal(client.stats().permissionCalls, 0);
}));

test('unsupported browsers show a status and disable activation', () => clientTest({ unsupported: true }, async (client) => {
    assert.equal(client.enable.disabled, true);
    assert.match(client.status.textContent, /لا يدعم/);
    assert.equal(client.requests.length, 0);
}));

test('unsupported Firebase messaging keeps the legacy compatibility path', () => clientTest({ firebaseUnsupported: true }, async (client) => {
    await client.controls.connect(true);
    assert.equal(client.requests[0].url, '/push-subscriptions');
    assert.equal(client.stats().tokenCalls, 0);
    assert.equal(client.stats().unsubscribed, 0);
    assert.equal(client.panel.hidden, false);
    assert.match(client.status.textContent, /Firebase غير مكتمل/);
}));

test('a granted device is not considered enabled until Firebase and the authenticated backend both succeed', () => clientTest({ withUI: true, noPanel: true, permission: 'granted', existing: true }, async (client) => {
    assert.equal(client.reminders.length, 0);
    assert.equal(client.stats().permissionCalls, 0);
    assert.equal(client.stats().tokenCalls, 1);
    assert.equal(client.requests[0].url, '/fcm-tokens');
}));

test('disabled notifications prompt once during a login, never request native permission on page load, and can be dismissed', () => clientTest({ withUI: true }, async (client) => {
    assert.equal(client.reminders.length, 1);
    assert.match(client.reminders[0].querySelector('[data-push-status]').textContent, /الواجبات والاختبارات والنتائج/);
    assert.equal(client.stats().permissionCalls, 0);
    client.reminders[0].querySelector('[data-push-dismiss]').dispatchEvent(new Event('click'));
    assert.equal(client.reminders[0].hidden, true);
    window.schoolPushActivation.failure('another failure');
    assert.equal(client.reminders[0].hidden, true);
}));

test('reloading and navigating during the same login do not repeat the reminder', async () => {
    const storage = new Map();
    await clientTest({ withUI: true, storage }, async (client) => assert.equal(client.reminders.length, 1));
    await clientTest({ withUI: true, storage }, async (client) => assert.equal(client.reminders.length, 0));
});

test('a fresh server login nonce and a different user each get their own reminder', async () => {
    const storage = new Map();
    for (const options of [{}, { loginId: 'session-two' }, { config: { userId: '34' } }]) {
        await clientTest({ withUI: true, storage, ...options }, async (client) => assert.equal(client.reminders.length, 1));
    }
});

test('granted permission without a browser subscription offers registration instead of silently claiming activation', () => clientTest({ withUI: true, noPanel: true, permission: 'granted' }, async (client) => {
    assert.equal(client.reminders.length, 1);
    assert.match(client.reminders[0].querySelector('[data-push-status]').textContent, /غير مسجل/);
    assert.equal(client.stats().tokenCalls, 0);
    client.reminders[0].querySelector('[data-push-reminder-enable]').dispatchEvent(new Event('click'));
    for (let i = 0; i < 30 && !client.reminders[0].hidden; i++) await new Promise(setImmediate);
    assert.equal(client.reminders[0].hidden, true);
    assert.equal(client.requests[0].url, '/fcm-tokens');
    assert.equal(client.stats().permissionCalls, 0);
}));

for (const [name, options, message] of [
    ['SDK loading', { firebaseError: true }, /تهيئة Firebase/],
    ['token creation', { tokenError: true }, /إنشاء رمز/],
    ['empty token', { emptyToken: true }, /إنشاء رمز/],
    ['backend registration', { backendError: 500 }, /لم يكتمل التفعيل/],
]) test(`login reminder exposes ${name} failure and its own retry button recovers`, () => clientTest({ withUI: true, noPanel: true, permission: 'granted', existing: true, ...options }, async (client) => {
    assert.equal(client.reminders.length, 1);
    assert.match(client.reminders[0].querySelector('[data-push-status]').textContent, message);
    assert.equal(client.reminders[0].hidden, false);
    client.recover();
    client.reminders[0].querySelector('[data-push-reminder-enable]').dispatchEvent(new Event('click'));
    for (let i = 0; i < 30 && !client.reminders[0].hidden; i++) await new Promise(setImmediate);
    assert.equal(client.reminders[0].hidden, true);
    assert.equal(client.stats().permissionCalls, 0);
}));

test('blocked notification permission explains site settings and never creates a token', () => clientTest({ withUI: true, noPanel: true, permission: 'denied' }, async (client) => {
    assert.match(client.reminders[0].querySelector('[data-push-status]').textContent, /إعدادات الموقع/);
    client.reminders[0].querySelector('[data-push-reminder-enable]').dispatchEvent(new Event('click'));
    assert.equal(client.stats().permissionCalls, 0);
    assert.equal(client.stats().tokenCalls, 0);
}));

test('an empty or failed Vite entry is detected without executing the module and has a working reload button', () => clientTest({ withUI: true, loadError: true }, async (client) => {
    client.enable.dispatchEvent(new Event('click'));
    assert.match(client.status.textContent, /جارٍ تحميل/);
    assert.doesNotMatch(client.status.textContent, /تعذر تحميل/);
    client.watchdog();
    assert.match(client.status.textContent, /تعذر تحميل/);
    assert.equal(client.stats().permissionCalls, 0);
    client.reminders[0].querySelector('[data-push-reminder-enable]').dispatchEvent(new Event('click'));
    assert.equal(client.stats().reloads, 1);
}));

test('a missing configuration still gives actionable independent feedback', () => clientTest({ withUI: true, missingConfig: true, loadError: true }, async (client) => {
    client.enable.dispatchEvent(new Event('click'));
    assert.match(client.status.textContent, /إعدادات الإشعارات/);
    client.enable.dispatchEvent(new Event('click'));
    assert.equal(client.stats().reloads, 1);
}));

test('granted permission does not suppress load failure when the activation client never starts', () => clientTest({ withUI: true, noPanel: true, permission: 'granted', existing: true, loadError: true }, async (client) => {
    assert.equal(client.reminders.length, 0);
    client.watchdog();
    assert.equal(client.reminders.length, 1);
    assert.match(client.reminders[0].querySelector('[data-push-status]').textContent, /تعذر تحميل/);
    assert.equal(client.requests.length, 0);
}));

test('a browser with an absent Notification API gets guidance rather than an initialization exception', () => clientTest({ withUI: true, noPanel: true, noNotification: true }, async (client) => {
    assert.match(client.reminders[0].querySelector('[data-push-status]').textContent, /لا يدعم/);
    assert.equal(client.requests.length, 0);
}));
