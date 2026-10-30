import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import { IDBFactory, IDBKeyRange } from 'fake-indexeddb';
import { applicationKey, backgroundPayload, keyMatches, waitForWorker, workerFor } from '../../resources/js/push-support.js';

const display = await readFile(new URL('../../public/js/push-notification-display.js', import.meta.url), 'utf8');
const sources = Object.fromEntries(await Promise.all(['app', 'parent'].map(async (name) => [name, await readFile(new URL(`../../public/${name}-sw.js`, import.meta.url), 'utf8')])));

function worker(name, indexedDB = new IDBFactory(), firebaseUnavailable = false) {
    const listeners = {};
    const shown = [];
    const opened = [];
    const imports = [];
    const scope = {
        indexedDB, location: { origin: 'https://school.test' },
        registration: { scope: name === 'parent' ? 'https://school.test/parent/' : 'https://school.test/', async showNotification(title, options) { shown.push({ title, ...options }); } },
        clients: { async matchAll() { return []; }, async openWindow(url) { opened.push(url); } },
        addEventListener(name, handler) { (listeners[name] ||= []).push(handler); },
    };
    const context = vm.createContext({ self: scope, indexedDB, IDBKeyRange, URL, Date, console });
    context.importScripts = (url) => {
        imports.push(url);
        if (url === '/js/push-notification-display.js') vm.runInContext(display, context);
        if (url === '/push/firebase-worker.js' && firebaseUnavailable) throw new Error('Firebase not configured');
    };
    vm.runInContext(sources[name], context);
    return {
        scope, shown, opened, imports,
        on(name, callback) { scope.addEventListener(name, callback); },
        async emit(name, fields) {
            const pending = [];
            let stopped = false;
            for (const listener of listeners[name] || []) {
                listener({ ...fields, stopImmediatePropagation() { stopped = true; }, waitUntil(promise) { pending.push(promise); } });
                if (stopped) break;
            }
            await Promise.all(pending);
        },
    };
}

test('reuses the current root/parent worker URLs and scopes', () => {
    assert.deepEqual(workerFor('/student/notifications'), { script: '/app-sw.js', scope: '/' });
    assert.deepEqual(workerFor('/parent/notifications'), { script: '/parent-sw.js', scope: '/parent/' });
    assert.equal(workerFor('/parentish').scope, '/');
});

test('only application data messages get custom background display; Console messages are not shown twice', () => {
    const data = { notification_id: 'id', title: 'Notice' };
    assert.equal(backgroundPayload({ data }), data);
    assert.equal(backgroundPayload({ notification: { title: 'Console notice' }, data }), null);
    assert.equal(backgroundPayload({}), null);
});

test('detects incompatible VAPID keys without replacing subscriptions', () => {
    assert.equal(keyMatches({ options: { applicationServerKey: applicationKey('AQID') } }, 'AQID'), true);
    assert.equal(keyMatches({ options: { applicationServerKey: applicationKey('AQID') } }, 'AQIE'), false);
});

test('waits for worker activation instead of using an older active worker', async () => {
    const installing = new EventTarget();
    installing.state = 'installing';
    const registration = { installing, active: { state: 'activated' } };
    const pending = waitForWorker(registration);
    installing.state = 'activated';
    installing.dispatchEvent(new Event('statechange'));
    assert.equal(await pending, registration);
});

test('rejects a failed worker installation', async () => {
    const installing = new EventTarget();
    installing.state = 'installing';
    const pending = waitForWorker({ installing });
    installing.state = 'redundant';
    installing.dispatchEvent(new Event('statechange'));
    await assert.rejects(pending, /worker-unavailable/);
});

for (const name of ['app', 'parent']) {
    test(`${name} preserves legacy VAPID delivery when Firebase is unavailable`, async () => {
        const app = worker(name, new IDBFactory(), true);
        await app.emit('push', { data: { json: () => ({ title: 'Legacy notice', body: 'Message', url: '/parent/notifications' }) } });
        assert.equal(app.shown.length, 1);
        assert.equal(app.shown[0].title, 'Legacy notice');
        assert.deepEqual(app.imports, ['/js/push-notification-display.js', '/push/firebase-worker.js']);
    });

    test(`${name} leaves Firebase envelopes to the Firebase handler`, async () => {
        const app = worker(name);
        await app.emit('push', { data: { json: () => ({ fcmMessageId: 'fcm-id', from: 'sender', data: { title: 'FCM notice' } }) } });
        assert.equal(app.shown.length, 0);
        await app.scope.schoolPush.show({ title: 'FCM notice', notification_id: 'fcm-id' });
        assert.equal(app.shown.length, 1);
    });
}

test('deduplicates simultaneous tabs and both scopes, while allowing later distinct messages', async () => {
    const indexedDB = new IDBFactory();
    const root = worker('app', indexedDB);
    const parent = worker('parent', indexedDB);
    const payload = { title: 'Notice', notification_id: 'shared-event' };
    await Promise.all([
        root.emit('message', { data: { type: 'SCHOOL_FOREGROUND_PUSH', payload } }),
        parent.scope.schoolPush.show(payload),
        root.scope.schoolPush.show(payload),
    ]);
    assert.equal(root.shown.length + parent.shown.length, 1);
    await parent.scope.schoolPush.show({ ...payload, notification_id: 'another-event' });
    assert.equal(root.shown.length + parent.shown.length, 2);
});

test('notification links remain on this origin and foreground messages use the shared display', async () => {
    const app = worker('app');
    await app.emit('message', { data: { type: 'SCHOOL_FOREGROUND_PUSH', payload: { title: '<b>Plain text</b>', url: 'https://malicious.test/' } } });
    assert.equal(app.shown[0].title, '<b>Plain text</b>');
    assert.equal(app.shown[0].data.url, 'https://school.test/notifications');
    await app.emit('notificationclick', { notification: { data: { url: 'javascript:alert(1)' }, close() {} } });
    assert.deepEqual(app.opened, ['https://school.test/notifications']);
});

test('malformed legacy push payloads do not break notification handling', async () => {
    const app = worker('parent');
    await app.emit('push', { data: { json() { throw new Error('invalid json'); } } });
    assert.equal(app.shown.length, 1);
    assert.equal(app.shown[0].data.url, 'https://school.test/parent/notifications');
});

test('both worker scopes keep valid resource destinations and replace missing or root targets with their notification lists', async () => {
    for (const name of ['app', 'parent']) {
        const app = worker(name);
        const fallback = name === 'parent' ? 'https://school.test/parent/notifications' : 'https://school.test/notifications';
        assert.equal(app.scope.schoolPush.safeUrl(''), fallback);
        assert.equal(app.scope.schoolPush.safeUrl('/'), fallback);
        assert.equal(app.scope.schoolPush.safeUrl('//evil.test/resource'), fallback);
        assert.equal(app.scope.schoolPush.safeUrl('/student/assignments/12'), 'https://school.test/student/assignments/12');
        assert.equal(app.scope.schoolPush.safeUrl('/parent/results?student=4'), 'https://school.test/parent/results?student=4');
    }
});

test('plain legacy JSON never reaches Firebase\'s generic JSON listener', async () => {
    const app = worker('app');
    let firebaseCalls = 0;
    app.on('push', () => { firebaseCalls++; });
    await app.emit('push', { data: { json: () => ({ title: 'Legacy' }) } });
    assert.equal(firebaseCalls, 0);
    assert.equal(app.shown.length, 1);
    await app.emit('push', { data: { json: () => ({ fcmMessageId: 'id', from: 'sender' }) } });
    assert.equal(firebaseCalls, 1);
});
