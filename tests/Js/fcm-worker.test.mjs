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

function loadWorker(clients = []) {
    const listeners = new Map();
    let backgroundHandler;
    const shown = [];
    const context = vm.createContext({
        URL,
        initializeApp: () => ({}),
        getMessaging: () => ({}),
        onBackgroundMessage: (_messaging, handler) => { backgroundHandler = handler; },
        self: {
            location: {origin: 'https://school.example'},
            registration: {showNotification: (...args) => shown.push(args)},
            clients: {
                matchAll: async () => clients,
                openWindow: async (url) => { context.opened = url; },
            },
            addEventListener: (name, handler) => listeners.set(name, handler),
        },
    });
    vm.runInContext(source, context);
    return {context, listeners, backgroundHandler, shown};
}

test('background message renders its data payload and role-local destination', () => {
    const worker = loadWorker();
    worker.backgroundHandler({data: {title: 'Exam', body: 'Published', url: '/student/exams'}});

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
    const event = {notification: {data: {url: '/student/exams'}, close() {}}, waitUntil: (promise) => { event.pending = promise; }};
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
