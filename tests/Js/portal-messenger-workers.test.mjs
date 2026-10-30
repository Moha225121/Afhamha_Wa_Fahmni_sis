import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { IDBFactory, IDBKeyRange } from 'fake-indexeddb';

const origin = 'https://school.test';
const uuid = '52f9bb08-b6c4-4710-8b63-b67311d93cb2';
const receipt = `/teacher-message-receipts/${uuid}/delivered?expires=2000000000&signature=${'b'.repeat(64)}`;
const payload = {
    notification_id: uuid, category: 'conversation_message', title: 'رسالة جديدة',
    body: 'رسالة من ولي الأمر', url: '/teacher/messages?thread=conversation_17', receipt_url: receipt,
};
const displaySource = readFileSync(new URL('../../public/js/push-notification-display.js', import.meta.url), 'utf8');
const sources = Object.fromEntries(['app', 'parent'].map(name => [
    name, readFileSync(new URL(`../../public/${name}-sw.js`, import.meta.url), 'utf8'),
]));
const deferred = () => {
    let resolve;
    const promise = new Promise(yes => { resolve = yes; });
    return { promise, resolve };
};

function worker(name, {
    indexedDB = new IDBFactory(), show = async () => {}, send = async () => ({ ok: true }),
} = {}) {
    const calls = [], shown = [], listeners = new Map();
    const scope = {
        indexedDB, location: { origin },
        registration: {
            scope: origin + (name === 'parent' ? '/parent/' : '/'),
            async showNotification(title, options) { shown.push({ title, options }); return show(); },
        },
        async fetch(url, options) { calls.push({ url, options }); return send(); },
        addEventListener(event, listener) {
            if (!listeners.has(event)) listeners.set(event, []);
            listeners.get(event).push(listener);
        },
    };
    const context = vm.createContext({ self: scope, indexedDB, IDBKeyRange, URL, Date, console });
    context.importScripts = url => {
        if (url === '/js/push-notification-display.js') vm.runInContext(displaySource, context);
        // Firebase's boundary is intentionally offline; the actual worker loads its shared display.
    };
    vm.runInContext(sources[name], context);
    return {
        scope, calls, shown,
        async push(value) {
            const pending = [];
            for (const listener of listeners.get('push') ?? []) {
                let stopped = false;
                listener({
                    data: { json: () => value },
                    stopImmediatePropagation() { stopped = true; },
                    waitUntil(promise) { pending.push(promise); },
                });
                if (stopped) break;
            }
            await Promise.all(pending);
        },
    };
}

for (const name of ['app', 'parent']) {
    test(`${name} worker acknowledges a conversation message only after its notification is displayed`, async () => {
        const started = deferred(), displayed = deferred();
        const app = worker(name, { show() { started.resolve(); return displayed.promise; } });
        const work = app.push(payload);
        await started.promise;
        assert.equal(app.shown.length, 1);
        assert.equal(app.calls.length, 0);
        displayed.resolve();
        await work;
        assert.equal(app.calls.length, 1);
        assert.equal(app.calls[0].url, origin + receipt);
        assert.equal(app.calls[0].options.method, 'POST');
        assert.equal(app.calls[0].options.credentials, 'same-origin');
        assert.doesNotMatch(app.calls[0].url, /\/viewed|\/messages\/read/);
        assert.equal(app.shown[0].options.data.url, origin + payload.url);
    });

    test(`${name} worker does not acknowledge a failed display and an acknowledgement failure cannot break delivery`, async () => {
        const failure = worker(name, { show: async () => { throw new Error('display failed'); } });
        await assert.rejects(failure.push(payload), /display failed/);
        assert.equal(failure.calls.length, 0);
        const offline = worker(name, { send: async () => { throw new Error('offline'); } });
        await assert.doesNotReject(offline.push(payload));
        assert.equal(offline.shown.length, 1);
        assert.equal(offline.calls.length, 1);
    });
}

test('both conversation worker scopes reject unrelated receipt URLs and deduplicate a shared message across scopes', async () => {
    for (const name of ['app', 'parent']) {
        const app = worker(name);
        for (const [index, url] of [
            'https://other.test' + receipt, '/parent/messages/read?thread=conversation_17',
            `/teacher-messages/${uuid}/viewed`, `/teacher-message-receipts/${uuid}/delivered?signature=unsigned`,
        ].entries()) {
            await app.push({ ...payload, notification_id: 'invalid-' + index, receipt_url: url });
        }
        assert.equal(app.shown.length, 4);
        assert.equal(app.calls.length, 0);
    }
    const indexedDB = new IDBFactory();
    const app = worker('app', { indexedDB });
    const parent = worker('parent', { indexedDB });
    await Promise.all([app.push(payload), parent.push(payload)]);
    assert.equal(app.shown.length + parent.shown.length, 1);
    assert.equal(app.calls.length + parent.calls.length, 1);
});
