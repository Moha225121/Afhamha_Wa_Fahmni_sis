import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { IDBFactory, IDBKeyRange } from 'fake-indexeddb';
import { bootTeacherMessageReceipts, bootTeacherMessageView, safeReceiptUrl, safeViewedUrl } from '../../public/js/teacher-message-receipts.js';

const workerSource = readFileSync(new URL('../../public/js/push-notification-display.js', import.meta.url), 'utf8');
const origin = 'https://school.test';
const uuid = '52f9bb08-b6c4-4710-8b63-b67311d93cb2';
const receipt = `/teacher-message-receipts/${uuid}/delivered?expires=2000000000&signature=${'a'.repeat(64)}`;
const viewedUrl = `/teacher-messages/${uuid}/viewed`;
const flush = () => new Promise(resolve => setImmediate(resolve));
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((accept, fail) => { resolve = accept; reject = fail; });
    return { promise, resolve, reject };
};

function pageHarness(urls, { visibilityState = 'visible', fetchResult = async () => ({ ok: true, status: 204 }) } = {}) {
    const calls = [];
    const listeners = {};
    const nodes = urls.map(url => ({
        dataset: { teacherReceiptUrl: url },
        getAttribute: name => name === 'data-teacher-receipt-url' ? url : null,
    }));
    const addEventListener = (name, listener) => { (listeners[name] ||= []).push(listener); };
    const location = { origin, href: origin + '/student/notifications', protocol: 'https:' };
    const document = {
        readyState: 'complete', visibilityState, hidden: visibilityState === 'hidden',
        querySelectorAll: selector => selector === '[data-teacher-receipt-url]' ? nodes : [],
        addEventListener,
    };
    const fetch = async (url, options) => {
        calls.push({ url: String(url), options });
        return fetchResult(calls.length, url, options);
    };
    const window = { document, location, fetch, addEventListener };
    document.defaultView = window;
    const api = bootTeacherMessageReceipts(document, { origin, send: fetch });
    return {
        document, nodes, calls, api,
        async emit(name) {
            for (const listener of listeners[name] ?? []) listener({ type: name });
            await flush();
        },
    };
}

function workerHarness({ name = 'app', indexedDB = new IDBFactory(), showResult = async () => {}, fetchResult = async () => ({ ok: true, status: 204 }) } = {}) {
    const calls = [];
    const shown = [];
    const opened = [];
    const listeners = {};
    const fetch = async (url, options) => {
        calls.push({ url: String(url), options });
        return fetchResult(calls.length, url, options);
    };
    const scope = {
        indexedDB, location: { origin }, fetch,
        registration: {
            scope: origin + (name === 'parent' ? '/parent/' : '/'),
            async showNotification(title, options) { shown.push({ title, options }); return showResult(); },
        },
        clients: { async matchAll() { return []; }, async openWindow(url) { opened.push(url); } },
        addEventListener(name, listener) { (listeners[name] ||= []).push(listener); },
    };
    vm.runInNewContext(workerSource, { self: scope, indexedDB, IDBKeyRange, URL, Date, fetch, console });
    return {
        scope, calls, shown, opened,
        async emit(name, fields) {
            const pending = [];
            for (const listener of listeners[name] ?? []) {
                listener({ ...fields, stopImmediatePropagation() {}, waitUntil(promise) { pending.push(promise); } });
            }
            await Promise.all(pending);
        },
    };
}

function assertReceiptPost(call, expected = receipt) {
    assert.equal(new URL(call.url, origin).href, new URL(expected, origin).href);
    assert.equal(call.options.method, 'POST');
    assert.doesNotMatch(call.url, /\/read(?:-all)?(?:\?|$)|\/viewed(?:\?|$)/);
}

test('page acknowledges delivered URLs already in the DOM once per URL without marking messages viewed', async () => {
    const page = pageHarness([receipt, receipt]);
    await flush();
    assert.equal(page.calls.length, 1);
    assertReceiptPost(page.calls[0]);
    await page.emit('online');
    await page.emit('visibilitychange');
    assert.equal(page.calls.length, 1);
});

test('a hidden inbox may acknowledge received data without making a viewed request', async () => {
    const page = pageHarness([receipt], { visibilityState: 'hidden' });
    await flush();
    assert.equal(page.calls.length, 1);
    assertReceiptPost(page.calls[0]);
});

test('page coalesces pending acknowledgements across duplicate elements and repeated browser events', async () => {
    const response = deferred();
    const page = pageHarness([receipt, receipt], { fetchResult: () => response.promise });
    await flush();
    await page.emit('online');
    await page.emit('visibilitychange');
    assert.equal(page.calls.length, 1);
    response.resolve({ ok: true, status: 204 });
    await flush();
    await page.emit('online');
    assert.equal(page.calls.length, 1);
});

test('page retries failed acknowledgements on online or visible events but remembers successful responses', async () => {
    const page = pageHarness([receipt], {
        fetchResult: async attempt => {
            if (attempt === 1) throw new Error('offline');
            return { ok: attempt >= 3, status: attempt >= 3 ? 204 : 503 };
        },
    });
    await flush();
    assert.equal(page.calls.length, 1);
    page.document.visibilityState = 'hidden';
    page.document.hidden = true;
    await page.emit('visibilitychange');
    assert.equal(page.calls.length, 1);
    page.document.visibilityState = 'visible';
    page.document.hidden = false;
    await page.emit('online');
    assert.equal(page.calls.length, 2);
    await page.emit('visibilitychange');
    assert.equal(page.calls.length, 3);
    await page.emit('online');
    assert.equal(page.calls.length, 3);
    page.calls.forEach(call => assertReceiptPost(call));
});

const unsafeReceipts = [
    'https://evil.test' + receipt,
    '//evil.test' + receipt,
    'javascript:alert(1)',
    '/logout',
    '/student/notifications/read-all',
    `/teacher-messages/${uuid}`,
    `/teacher-message-receipts/${uuid}/viewed?signature=${'a'.repeat(64)}`,
    `/teacher-message-receipts/not-a-uuid/delivered?signature=${'a'.repeat(64)}`,
    `/teacher-message-receipts/${uuid}/delivered`,
    `/teacher-message-receipts/${uuid}/delivered?signature=unsigned`,
];

test('receipt URL validation accepts only same-origin signed delivery endpoints', () => {
    assert.equal(safeReceiptUrl(receipt, origin), origin + receipt);
    assert.equal(safeReceiptUrl(origin + receipt, origin), origin + receipt);
    for (const url of unsafeReceipts) assert.equal(safeReceiptUrl(url, origin), null, url);
});

test('receipt page scripts are deferred modules so the notification markup exists when they boot', () => {
    for (const file of ['student/notifications', 'parent/notifications']) {
        const view = readFileSync(new URL(`../../resources/views/${file}.blade.php`, import.meta.url), 'utf8');
        assert.match(view, /<script\b[^>]*type="module"[^>]*src="[^\n]*teacher-message-receipts\.js/);
    }
});

test('page ignores external and unrelated endpoint values instead of issuing arbitrary requests', async () => {
    const page = pageHarness([...unsafeReceipts, receipt]);
    await flush();
    assert.equal(page.calls.length, 1);
    assertReceiptPost(page.calls[0]);
});

for (const name of ['app', 'parent']) {
    test(`${name} worker acknowledges delivery only after showNotification successfully resolves`, async () => {
        const started = deferred();
        const display = deferred();
        const worker = workerHarness({ name, showResult() { started.resolve(); return display.promise; } });
        const showing = worker.scope.schoolPush.show({
            notification_id: uuid, category: 'teacher_notification', title: 'رسالة المعلم', body: 'نص الرسالة',
            url: `/teacher-messages/${uuid}`, receipt_url: receipt,
        });
        await started.promise;
        assert.equal(worker.shown.length, 1);
        assert.equal(worker.calls.length, 0);
        display.resolve();
        await showing;
        assert.equal(worker.calls.length, 1);
        assertReceiptPost(worker.calls[0]);
        assert.equal(worker.shown[0].options.data.url, origin + `/teacher-messages/${uuid}`);
    });
}

test('worker does not claim delivery when the operating system notification display fails', async () => {
    const worker = workerHarness({ showResult: async () => { throw new Error('display rejected'); } });
    await worker.scope.schoolPush.show({ notification_id: uuid, category: 'teacher_notification', receipt_url: receipt }).catch(() => {});
    assert.equal(worker.calls.length, 0);
});

test('failed acknowledgement requests never break a successfully displayed notification', async () => {
    for (const fetchResult of [async () => { throw new Error('offline'); }, async () => ({ ok: false, status: 503 })]) {
        const worker = workerHarness({ fetchResult });
        await assert.doesNotReject(worker.scope.schoolPush.show({ notification_id: uuid, category: 'teacher_notification', receipt_url: receipt }));
        assert.equal(worker.shown.length, 1);
        assert.equal(worker.calls.length, 1);
        assertReceiptPost(worker.calls[0]);
    }
});

test('worker ignores external or unrelated receipt paths and leaves legacy messages unchanged', async () => {
    const worker = workerHarness();
    for (const [index, receiptUrl] of [...unsafeReceipts, undefined].entries()) {
        await worker.scope.schoolPush.show({ notification_id: 'unsafe-' + index, category: 'teacher_notification', title: 'رسالة', receipt_url: receiptUrl });
    }
    assert.equal(worker.shown.length, unsafeReceipts.length + 1);
    assert.equal(worker.calls.length, 0);
    await worker.scope.schoolPush.show({ notification_id: 'legacy', category: 'announcement', receipt_url: receipt });
    assert.equal(worker.calls.length, 0);
});

test('duplicate foreground/background deliveries across both worker scopes produce one display and one acknowledgement', async () => {
    const indexedDB = new IDBFactory();
    const app = workerHarness({ indexedDB });
    const parent = workerHarness({ name: 'parent', indexedDB });
    const payload = { notification_id: uuid, category: 'teacher_notification', title: 'رسالة', receipt_url: receipt, url: `/teacher-messages/${uuid}` };
    await Promise.all([
        app.emit('message', { data: { type: 'SCHOOL_FOREGROUND_PUSH', payload } }),
        parent.scope.schoolPush.show(payload),
        app.scope.schoolPush.show(payload),
    ]);
    assert.equal(app.shown.length + parent.shown.length, 1);
    assert.equal(app.calls.length + parent.calls.length, 1);
    [...app.calls, ...parent.calls].forEach(call => assertReceiptPost(call));
    await app.emit('notificationclick', { notification: { data: { url: payload.url }, close() {} } });
    assert.deepEqual(app.opened, [origin + payload.url]);
    assert.equal(app.calls.length + parent.calls.length, 1);
});

function viewHarness(url = viewedUrl, { visibilityState = 'visible', token = 'normal-session-csrf', fetchResult = async () => ({ ok: true, status: 204 }) } = {}) {
    const calls = [];
    const listeners = {};
    const element = {
        dataset: { teacherViewedUrl: url },
        getAttribute: name => name === 'data-teacher-viewed-url' ? url : null,
    };
    const csrf = token === null ? null : { content: token, getAttribute: name => name === 'content' ? token : null };
    const addEventListener = (name, listener) => { (listeners[name] ||= []).push(listener); };
    const location = { origin, href: origin + `/teacher-messages/${uuid}` };
    const document = {
        readyState: 'complete', visibilityState, hidden: visibilityState === 'hidden',
        querySelector(selector) {
            if (selector === '[data-teacher-viewed-url]') return element;
            if (/^meta\[name=["']?csrf-token["']?\]$/.test(selector)) return csrf;
            return null;
        },
        querySelectorAll: selector => selector === '[data-teacher-viewed-url]' ? [element] : [],
        addEventListener,
    };
    const send = async (url, options) => {
        calls.push({ url: String(url), options });
        return fetchResult(calls.length, url, options);
    };
    document.defaultView = { location, addEventListener };
    const api = bootTeacherMessageView(document, { origin, send });
    return {
        calls, document, api,
        async emit(name) {
            for (const listener of listeners[name] ?? []) listener({ type: name });
            await flush();
        },
        setVisibility(value) { document.visibilityState = value; document.hidden = value === 'hidden'; },
    };
}

function assertViewedPost(call) {
    assert.equal(new URL(call.url, origin).href, origin + viewedUrl);
    assert.equal(call.options.method, 'POST');
    assert.equal(call.options.credentials, 'same-origin');
    const headers = Object.fromEntries(Object.entries(call.options.headers).map(([key, value]) => [key.toLowerCase(), value]));
    assert.equal(headers['x-csrf-token'], 'normal-session-csrf');
    assert.doesNotMatch(call.url, /signature=|\/teacher-message-receipts\//);
}

test('hidden message details never mark viewed, then becoming visible posts with authenticated CSRF protection', async () => {
    const page = viewHarness(viewedUrl, { visibilityState: 'hidden' });
    await flush();
    await page.emit('visibilitychange');
    await page.emit('online');
    assert.equal(page.calls.length, 0);
    page.setVisibility('visible');
    await page.emit('visibilitychange');
    assert.equal(page.calls.length, 1);
    assertViewedPost(page.calls[0]);
    await page.emit('visibilitychange');
    await page.emit('online');
    assert.equal(page.calls.length, 1);
});

test('visible message details coalesce pending viewed updates and remember successful completion', async () => {
    const response = deferred();
    const page = viewHarness(viewedUrl, { fetchResult: () => response.promise });
    await flush();
    await page.emit('visibilitychange');
    await page.emit('online');
    assert.equal(page.calls.length, 1);
    response.resolve({ ok: true, status: 204 });
    await flush();
    await page.emit('online');
    assert.equal(page.calls.length, 1);
    assertViewedPost(page.calls[0]);
});

test('failed viewed updates retry only while visible and never treat an HTTP error as a successful view', async () => {
    const page = viewHarness(viewedUrl, {
        fetchResult: async attempt => {
            if (attempt === 1) throw new Error('offline');
            return { ok: attempt >= 3, status: attempt >= 3 ? 204 : 503 };
        },
    });
    await flush();
    assert.equal(page.calls.length, 1);
    page.setVisibility('hidden');
    await page.emit('visibilitychange');
    await page.emit('online');
    assert.equal(page.calls.length, 1);
    page.setVisibility('visible');
    await page.emit('visibilitychange');
    assert.equal(page.calls.length, 2);
    await page.emit('online');
    assert.equal(page.calls.length, 3);
    await page.emit('visibilitychange');
    assert.equal(page.calls.length, 3);
    page.calls.forEach(assertViewedPost);
});

test('viewed updates reject foreign paths and cannot use a public signed delivery capability', async () => {
    for (const url of [
        'https://evil.test' + viewedUrl, '//evil.test' + viewedUrl, 'javascript:alert(1)',
        '/logout', '/student/notifications/read-all', `/teacher-messages/${uuid}`,
        '/teacher-messages/not-a-uuid/viewed', receipt,
    ]) {
        const page = viewHarness(url);
        await flush();
        await page.emit('visibilitychange');
        assert.equal(page.calls.length, 0, url);
    }
    for (const token of [null, '']) {
        const page = viewHarness(viewedUrl, { token });
        await flush();
        await page.emit('online');
        assert.equal(page.calls.length, 0, 'Missing CSRF token must fail closed');
    }
});

test('viewed URLs accept only one positive integer student context and retain every existing URL restriction', () => {
    assert.equal(safeViewedUrl(viewedUrl, origin), origin + viewedUrl);
    assert.equal(safeViewedUrl(viewedUrl + '?student=17', origin), origin + viewedUrl + '?student=17');
    assert.equal(safeViewedUrl(origin + viewedUrl + '?student=1', origin), origin + viewedUrl + '?student=1');
    for (const value of [
        viewedUrl + '?student=0', viewedUrl + '?student=-1', viewedUrl + '?student=01',
        viewedUrl + '?student=1.5', viewedUrl + '?student=1e3', viewedUrl + '?student=',
        viewedUrl + '?student=%31', viewedUrl + '?student=17&student=18',
        viewedUrl + '?student=17&other=1', viewedUrl + '?other=1',
        viewedUrl + '?student[]=17', viewedUrl + '?student=17#another-child',
        'https://other.test' + viewedUrl + '?student=17', '//other.test' + viewedUrl + '?student=17',
        `/teacher-messages/${uuid}?student=17`, '/teacher-messages/not-a-uuid/viewed?student=17',
        receipt + '&student=17',
    ]) assert.equal(safeViewedUrl(value, origin), null, value);
});

test('child-context viewed posts preserve the validated student and remain hidden-tab and CSRF protected', async () => {
    const value = viewedUrl + '?student=17';
    const page = viewHarness(value, { visibilityState: 'hidden' });
    await flush();
    assert.equal(page.calls.length, 0);
    page.setVisibility('visible');
    await page.emit('visibilitychange');
    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, origin + value);
    assert.equal(page.calls[0].options.method, 'POST');
    assert.equal(page.calls[0].options.headers['X-CSRF-TOKEN'], 'normal-session-csrf');
    await page.emit('online');
    assert.equal(page.calls.length, 1);
    const noToken = viewHarness(value, { token: null });
    await flush();
    assert.equal(noToken.calls.length, 0);
});
