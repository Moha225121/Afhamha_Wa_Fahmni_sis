import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/js/firebase-messaging-sw.js', import.meta.url), 'utf8')
    .replace(/^import .*;\s*$/gm, '')
    .replaceAll('import.meta.env.VITE_FIREBASE_API_KEY', "'api-key'")
    .replaceAll('import.meta.env.VITE_FIREBASE_PROJECT_ID', "'project-id'")
    .replaceAll('import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID', "'sender-id'")
    .replaceAll('import.meta.env.VITE_FIREBASE_APP_ID', "'app-id'");

function loadWorker(clients = [], currentUserId = '42') {
    const listeners = new Map();
    let backgroundHandler;
    const shown = [];
    const context = vm.createContext({
        URL,
        fetch: async () => ({ok: true, json: async () => ({user_id: currentUserId, role: 'student'})}),
        initializeApp: () => ({}),
        getMessaging: () => ({}),
        onBackgroundMessage: (_messaging, handler) => { backgroundHandler = handler; },
        self: {
            location: {origin: 'https://school.example'},
            registration: {getNotifications: async () => [], showNotification: (...args) => shown.push(args)},
            clients: {
                matchAll: async () => clients,
                openWindow: async (url) => { context.opened = url; },
            },
            addEventListener: (name, handler) => listeners.set(name, handler),
        },
    });
    vm.runInContext(readFileSync(new URL('../../public/js/school-push-privacy.js', import.meta.url), 'utf8'), context);
    vm.runInContext(source, context);
    return {context, listeners, backgroundHandler, shown};
}

test('background push for the previous account is never displayed after account switching', async () => {
    const worker = loadWorker([], '99');
    await worker.backgroundHandler({data: {title: 'Private old account', body: 'Private previous content', url: '/student/exams', recipient_user_id: '42'}});
    assert.equal(worker.shown.length, 0);
});

test('background message renders its data payload and role-local destination', async () => {
    const worker = loadWorker();
    await worker.backgroundHandler({data: {title: 'Exam', body: 'Published', url: '/student/exams', recipient_user_id: '42'}});

    assert.equal(worker.shown[0][0], 'Exam');
    assert.equal(worker.shown[0][1].body, 'Published');
    assert.equal(worker.shown[0][1].data.url, '/student/exams');
});

test('notification clicks navigate an existing same-origin tab to its destination', async () => {
    let navigated;
    let focused = false;
    const client = {
        url: 'https://school.example/student/dashboard',
        navigate: async (url) => { navigated = url; },
        focus: async () => { focused = true; },
    };
    const worker = loadWorker([client]);
    const event = {notification: {data: {url: '/student/exams', recipient_user_id: '42'}, close() {}}, waitUntil: (promise) => { event.pending = promise; }};
    worker.listeners.get('notificationclick')(event);
    await event.pending;

    assert.equal(navigated, 'https://school.example/student/exams');
    assert.equal(focused, true);
});

test('notification clicks reject external destinations and use the login fallback', async () => {
    const worker = loadWorker();
    const event = {notification: {data: {url: 'https://attacker.example/path'}, close() {}}, waitUntil: (promise) => { event.pending = promise; }};
    worker.listeners.get('notificationclick')(event);
    await event.pending;

    assert.equal(worker.context.opened, 'https://school.example/login');
});

test('missing Firebase settings do not initialize messaging', () => {
    const unconfigured = readFileSync(new URL('../../resources/js/firebase-messaging-sw.js', import.meta.url), 'utf8')
        .replace(/^import .*;\s*$/gm, '')
        .replace(/import\.meta\.env\.VITE_FIREBASE_[A-Z_]+/g, 'undefined');
    const listeners = new Map();
    vm.runInNewContext(unconfigured, {
        URL,
        initializeApp: () => { throw new Error('Unexpected Firebase initialization'); },
        self: {addEventListener: (name, handler) => listeners.set(name, handler)},
    });
    assert.equal(typeof listeners.get('notificationclick'), 'function');
});
