import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const installScript = readFileSync(new URL('../../public/js/student-install.js', import.meta.url), 'utf8');

function installEnvironment(overrides = {}) {
    const events = {};
    const buttonEvents = {};
    const button = {hidden: false, disabled: false, addEventListener: (name, handler) => buttonEvents[name] = handler};
    const message = {};
    const steps = {replaceChildren: (...items) => steps.items = items};
    const dialog = {
        open: false,
        dataset: {serviceWorkerUrl: '/student-sw.js', serviceWorkerScope: '/student/'},
        querySelector: selector => selector === '[data-install-message]' ? message : steps,
        showModal() { this.open = true; },
        close() { this.open = false; },
    };
    const displayMode = {matches: false, addEventListener: (name, handler) => events.displayChange = handler};
    const registrations = [];
    const navigator = {
        userAgent: 'Desktop', platform: '', maxTouchPoints: 0,
        serviceWorker: {register: async (...args) => registrations.push(args)},
        ...overrides,
    };
    vm.runInNewContext(installScript, {
        document: {
            readyState: 'complete',
            querySelectorAll: () => [button], getElementById: () => dialog,
            createElement: () => ({}),
        },
        window: {isSecureContext: true, matchMedia: () => displayMode, addEventListener: (name, handler) => events[name] = handler},
        navigator,
    });
    return {events, click: () => buttonEvents.click(), button, dialog, message, steps, displayMode, registrations};
}

test('manual guidance covers desktop, Android, iPhone, and desktop-mode iPad', async () => {
    for (const [device, expected] of [
        [{}, 'Chrome أو Edge'],
        [{userAgent: 'Android'}, 'قائمة المتصفح ⋮'],
        [{userAgent: 'iPhone'}, 'Safari'],
        [{platform: 'MacIntel', maxTouchPoints: 5}, 'Safari'],
    ]) {
        const env = installEnvironment(device);
        await env.click();
        assert.equal(env.dialog.open, true);
        assert.ok(env.steps.items.some(item => item.textContent.includes(expected)));
        assert.equal(env.registrations[0][0], '/student-sw.js');
        assert.equal(env.registrations[0][1].scope, '/student/');
    }
});

test('native prompt is consumed once; dismissal allows manual help and a new browser prompt', async () => {
    const env = installEnvironment();
    let calls = 0;
    const prompt = {preventDefault() {}, prompt: async () => calls++, userChoice: Promise.resolve({outcome: 'dismissed'})};
    env.events.beforeinstallprompt(prompt);
    await env.click();
    assert.equal(calls, 1);
    assert.equal(env.button.hidden, false);
    await env.click();
    assert.equal(calls, 1);
    assert.equal(env.dialog.open, true);
    env.events.beforeinstallprompt({...prompt, userChoice: Promise.resolve({outcome: 'accepted'})});
    await env.click();
    assert.equal(calls, 2);
    assert.equal(env.button.hidden, true);
});

test('an unavailable native prompt falls back to help and re-enables the button', async () => {
    const env = installEnvironment();
    env.events.beforeinstallprompt({preventDefault() {}, prompt: async () => { throw new Error('Unavailable'); }});
    await env.click();
    assert.equal(env.dialog.open, true);
    assert.equal(env.button.disabled, false);
});

test('installed and standalone apps hide install controls', async () => {
    const env = installEnvironment();
    await env.click();
    env.events.appinstalled();
    assert.equal(env.dialog.open, false);
    assert.equal(env.button.hidden, true);
    assert.equal(installEnvironment({standalone: true}).button.hidden, true);
    const changed = installEnvironment();
    changed.displayMode.matches = true;
    changed.events.displayChange();
    assert.equal(changed.button.hidden, true);
});

function workerEnvironment(filename) {
    const events = {};
    const removed = [];
    const cached = [];
    const offline = {offline: true};
    let currentCache;
    const context = {
        URL,
        self: {
            location: {origin: 'https://school.test'},
            addEventListener: (name, handler) => events[name] = handler,
            skipWaiting: async () => {}, clients: {claim: async () => {}},
        },
        caches: {
            keys: async () => ['afhamha-parent-static-obsolete', 'afhamha-student-static-obsolete', 'other-app', currentCache],
            delete: async key => removed.push(key),
            open: async () => ({addAll: async paths => cached.push(...paths)}),
            match: async path => path === '/student-offline.html' ? offline : undefined,
        },
        fetch: async () => ({online: true}),
    };
    vm.createContext(context);
    vm.runInContext(readFileSync(new URL(`../../public/${filename}`, import.meta.url), 'utf8'), context);
    currentCache = vm.runInContext('CACHE_NAME', context);
    const run = async name => {
        let pending;
        events[name]({waitUntil: promise => pending = promise});
        await pending;
    };
    const request = async (path, mode = 'navigate', method = 'GET') => {
        let response;
        events.fetch({request: {url: `https://school.test${path}`, mode, method}, respondWith: promise => response = promise});
        return await response;
    };
    return {context, run, request, removed, cached, offline};
}

test('each worker cleans up only its own old caches', async () => {
    const student = workerEnvironment('student-sw.js');
    await student.run('activate');
    assert.deepEqual(student.removed, ['afhamha-student-static-obsolete']);
    const parent = workerEnvironment('parent-sw.js');
    await parent.run('activate');
    assert.deepEqual(parent.removed, ['afhamha-parent-static-obsolete']);
});

test('student worker caches public assets and uses a generic offline screen for account pages', async () => {
    const env = workerEnvironment('student-sw.js');
    await env.run('install');
    assert.ok(env.cached.includes('/student-offline.html'));
    assert.equal(env.cached.some(path => path.startsWith('/student/')), false);
    assert.equal((await env.request('/student/dashboard')).online, true);
    env.context.fetch = async () => { throw new Error('Offline'); };
    assert.equal(await env.request('/student/dashboard'), env.offline);
    assert.equal(await env.request('/student/notifications/summary', 'cors'), undefined);
    assert.equal(await env.request('/student/assignments/1/submission', 'cors', 'POST'), undefined);
    assert.equal(await env.request('/parent/dashboard'), undefined);
});
