import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const clientSource = readFileSync(new URL('../../resources/js/firebase-messaging.js', import.meta.url), 'utf8')
    .replace(/^import .*;\s*$/gm, '')
    .replaceAll('import.meta.env.VITE_FIREBASE_API_KEY', "'api-key'")
    .replaceAll('import.meta.env.VITE_FIREBASE_PROJECT_ID', "'school-project'")
    .replaceAll('import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID', "'sender-id'")
    .replaceAll('import.meta.env.VITE_FIREBASE_APP_ID', "'app-id'")
    .replaceAll('import.meta.env.VITE_FIREBASE_VAPID_KEY', "'firebase-vapid'");

test('enabling notifications registers an isolated FCM worker and activates foreground handling immediately', async () => {
    const handlers = {};
    const enable = {hidden: false, disabled: false, addEventListener: (name, callback) => { handlers[name] = callback; }};
    const message = {textContent: ''};
    const banner = {hidden: true, querySelector: (selector) => selector === '[data-fcm-enable]' ? enable : message};
    const notifications = [];
    const registration = {active: {}, showNotification: (...args) => notifications.push(args)};
    const registrations = [];
    const requests = [];
    let foreground;
    const notification = {permission: 'default', requestPermission: async () => { notification.permission = 'granted'; return 'granted'; }};
    const context = {
        document: {querySelector: (selector) => selector === '[data-fcm-banner]' ? banner : {content: 'csrf'}},
        window: {Notification: notification}, Notification: notification,
        navigator: {serviceWorker: {register: async (...args) => { registrations.push(args); return registration; }}},
        fetch: async (url, options) => { requests.push([url, options]); return {ok: true, json: async () => ({configured: true, project_id: 'school-project'})}; },
        initializeApp: () => ({}), getMessaging: () => ({}), isSupported: async () => true,
        getToken: async (_messaging, options) => { assert.equal(options.serviceWorkerRegistration, registration); return 'test-token'; },
        onMessage: (_messaging, callback) => { foreground = callback; },
    };
    vm.runInNewContext(clientSource, context);
    await new Promise(setImmediate);
    assert.equal(banner.hidden, false);
    await handlers.click();
    assert.equal(registrations[0][0], '/firebase-messaging-sw.js');
    assert.equal(registrations[0][1].scope, '/firebase-cloud-messaging-push-scope/');
    assert.equal(registrations[0][1].type, 'module');
    assert.equal(registrations[0][1].updateViaCache, 'none');
    assert.equal(requests[1][0], '/fcm-devices');
    assert.equal(JSON.parse(requests[1][1].body).token, 'test-token');
    assert.equal(banner.hidden, true);
    assert.equal(typeof foreground, 'function');
    foreground({data: {title: 'Exam', body: 'Published', url: '/student/exams'}});
    assert.equal(notifications[0][0], 'Exam');
    assert.equal(notifications[0][1].data.url, '/student/exams');
});

test('FCM token retrieval waits for a newly installed custom worker to activate', async () => {
    let stateChange;
    let getTokenCalls = 0;
    const worker = {
        state: 'installing',
        addEventListener: (_name, callback) => { stateChange = callback; },
        removeEventListener: () => {},
    };
    const registration = {active: null, installing: worker, showNotification: () => {}};
    const enable = {hidden: false, disabled: false, addEventListener: () => {}};
    const banner = {hidden: true, querySelector: (selector) => selector === '[data-fcm-enable]' ? enable : {textContent: ''}};
    const notification = {permission: 'granted'};
    const context = {
        document: {querySelector: (selector) => selector === '[data-fcm-banner]' ? banner : {content: 'csrf'}},
        window: {Notification: notification}, Notification: notification,
        navigator: {serviceWorker: {register: async () => registration}},
        fetch: async () => ({ok: true, json: async () => ({configured: true, project_id: 'school-project'})}),
        initializeApp: () => ({}), getMessaging: () => ({}), isSupported: async () => true,
        getToken: async () => { getTokenCalls++; return 'test-token'; }, onMessage: () => {},
        setTimeout, clearTimeout,
    };
    vm.runInNewContext(clientSource, context);
    await new Promise(setImmediate);
    assert.equal(getTokenCalls, 0);
    worker.state = 'activated';
    stateChange();
    await new Promise(setImmediate);
    assert.equal(getTokenCalls, 1);
    assert.equal(banner.hidden, true);
});

test('parent Web Push subscribes through the parent worker even if the root worker is ready', async () => {
    const view = readFileSync(new URL('../../resources/views/parent/notifications.blade.php', import.meta.url), 'utf8');
    const script = view.match(/<script>\s*([\s\S]*?)<\/script>/)[1]
        .replace("@json($vapidPublicKey)", "'AQ'")
        .replace("@json(asset('parent-sw.js'))", "'/parent-sw.js'")
        .replace("@json(route('parent.push-subscriptions.store'))", "'/parent/push-subscriptions'");
    let click;
    const button = {addEventListener: (_name, callback) => { click = callback; }};
    const status = {textContent: ''};
    const subscriptions = [];
    const registrations = [];
    const parentRegistration = {active: {}, pushManager: {subscribe: async (options) => { subscriptions.push(options); return {toJSON: () => ({endpoint: 'https://push.example/test'})}; }}};
    const serviceWorker = {register: async (...args) => { registrations.push(args); return parentRegistration; }};
    Object.defineProperty(serviceWorker, 'ready', {get: () => { throw new Error('Must not use the root registration.'); }});
    const context = {
        document: {getElementById: (id) => id === 'enable-push' ? button : status, querySelector: () => ({content: 'csrf'})},
        window: {PushManager: function () {}, Notification: {}},
        navigator: {serviceWorker}, Notification: {requestPermission: async () => 'granted'},
        Uint8Array, atob: (value) => Buffer.from(value, 'base64').toString('binary'),
        fetch: async () => ({ok: true}),
    };
    vm.runInNewContext(script, context);
    await click();
    assert.equal(registrations[0][0], '/parent-sw.js');
    assert.equal(registrations[0][1].scope, '/parent/');
    assert.equal(subscriptions.length, 1);
    assert.equal(subscriptions[0].applicationServerKey[0], 1);
    assert.match(status.textContent, /تم تفعيل/);
});
