import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const privacySource = readFileSync(new URL('../../public/js/school-push-privacy.js', import.meta.url), 'utf8');
function fixture(state = { user_id: '42', role: 'parent' }) {
    const requests = [], shown = [], closed = [];
    const registration = {
        getNotifications: async () => ['42', '99', undefined].map(id => ({ data: { recipient_user_id: id }, close: () => closed.push(id) })),
        showNotification: async (...args) => shown.push(args),
    };
    const context = vm.createContext({ URL, fetch: async (...args) => {
        requests.push(args);
        if (state === 'offline') throw new Error('network unavailable');
        return { ok: state !== null, json: async () => state };
    } });
    vm.runInContext(privacySource, context);
    return { context, api: context.SchoolPushPrivacy, registration, shown, closed, requests };
}
test('FCM and Web Push authenticate current session and close obsolete or unowned notifications', async () => {
    const f = fixture();
    assert.equal(await f.api.show(f.registration, { recipient_user_id: '42', title: 'Own', event_id: '12' }), true);
    assert.deepEqual(f.closed, ['99', undefined]);
    assert.equal(f.shown[0][1].tag, 'school-event:42:12');
    assert.equal(f.requests[0][1].credentials, 'same-origin');
    assert.equal(f.requests[0][1].cache, 'no-store');
    assert.equal(await f.api.show(f.registration, { recipient_user_id: '99', title: 'Previous account' }), false);
    assert.equal(await f.api.show(f.registration, { title: 'Legacy unowned' }), false);
    assert.equal(f.shown.length, 1);
});
for (const state of [null, 'offline']) {
    test(`signed-out or unavailable session fails closed (${state})`, async () => {
        const f = fixture(state);
        assert.equal(await f.api.show(f.registration, { recipient_user_id: '42', title: 'Private' }), false);
        assert.equal(f.shown.length, 0);
        assert.equal(f.closed.length, 3);
    });
}
test('click destinations stay in the authenticated recipients portal', () => {
    const f = fixture();
    const recipient = { id: '42', role: 'parent' };
    const origin = 'https://school.example';
    assert.equal(f.api.destination({ recipient_user_id: '42', url: '/parent/notifications' }, recipient, origin), origin + '/parent/notifications');
    for (const data of [{ recipient_user_id: '99', url: '/parent/notifications' }, { recipient_user_id: '42', url: '/student/exams' }, { recipient_user_id: '42', url: 'https://external.invalid/' }]) {
        assert.equal(f.api.destination(data, recipient, origin), origin + '/login');
    }
});
test('classic parent worker uses the same privacy gate for late pushes and clicks', async () => {
    const f = fixture({ user_id: '99', role: 'parent' });
    const handlers = {};
    f.context.importScripts = () => {};
    f.context.clients = { openWindow: async url => { f.context.opened = url; } };
    f.context.self = { location: { origin: 'https://school.example' }, registration: f.registration, addEventListener: (name, handler) => { handlers[name] = handler; } };
    vm.runInContext(readFileSync(new URL('../../public/parent-sw.js', import.meta.url), 'utf8'), f.context);
    const event = { data: { json: () => ({ recipient_user_id: '42', title: 'Previous parent' }) }, waitUntil(p) { this.pending = p; } };
    handlers.push(event); await event.pending;
    assert.equal(f.shown.length, 0);
    const click = { notification: { data: { recipient_user_id: '42', url: '/parent/notifications' }, close() {} }, waitUntil(p) { this.pending = p; } };
    handlers.notificationclick(click); await click.pending;
    assert.equal(f.context.opened, 'https://school.example/login');
});

test('page account changes and logout close school notifications without deleting unrelated registrations', async () => {
    const f = fixture();
    let submitHandler, submits = 0;
    class Form { constructor() { this.action = 'https://school.example/logout'; } submit() { submits++; } }
    f.context.HTMLFormElement = Form;
    f.context.location = { origin: 'https://school.example' };
    f.context.navigator = { serviceWorker: { getRegistrations: async () => [
        { ...f.registration, scope: 'https://school.example/parent/' },
        { scope: 'https://school.example/unrelated/', getNotifications() { throw new Error('Unrelated worker must not be touched'); } },
    ] } };
    f.context.document = { currentScript: { dataset: { userId: '42' } }, addEventListener: (name, handler) => { if (name === 'submit') submitHandler = handler; } };
    f.context.setTimeout = () => 0;
    vm.runInContext(readFileSync(new URL('../../public/js/school-push-session.js', import.meta.url), 'utf8'), f.context);
    await new Promise(setImmediate);
    assert.deepEqual(f.closed, ['99', undefined]);
    let prevented = false;
    submitHandler({ target: new Form(), preventDefault() { prevented = true; } });
    await new Promise(setImmediate);
    assert.equal(prevented, true);
    assert.equal(submits, 1);
    assert.deepEqual(f.closed.slice(2), ['42', '99', undefined]);
});
