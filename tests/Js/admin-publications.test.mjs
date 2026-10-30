import assert from 'node:assert/strict';
import test from 'node:test';
import {bootAdminPublications} from '../../public/js/admin-publications.js';

const origin = 'https://school.test';
const flush = () => new Promise(resolve => setImmediate(resolve));
function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return {promise, resolve, reject};
}

class Events {
    constructor() { this.listeners = new Map(); }
    addEventListener(type, callback) {
        if (!this.listeners.has(type)) this.listeners.set(type, new Set());
        this.listeners.get(type).add(callback);
    }
    removeEventListener(type, callback) { this.listeners.get(type)?.delete(callback); }
    async emit(type, event = {}) {
        for (const callback of this.listeners.get(type) || []) callback({type, ...event});
        await flush();
    }
}

function harness({detail = false, hidden = false, href, endpoint, fields, initialVersion = 'initial-version', reply, intervalMs = 2000} = {}) {
    const doc = new Events();
    const win = new Events();
    win.location = {href: href || `${origin}/admin/publications?view=feed&classroom_id=3&page=2`, origin};
    doc.defaultView = win;
    doc.hidden = hidden;
    const monitor = {dataset: detail
        ? {checkUrl: endpoint || '/admin/publications/lesson/11', publicationVersion: initialVersion}
        : {refreshUrl: endpoint || '/admin/publications/refresh?view=feed&classroom_id=3&page=2'}};
    let replacements = 0;
    let content = 'existing publications';
    const results = {dataset: {publicationVersion: initialVersion}, get innerHTML() { return content; }, set innerHTML(value) { replacements += 1; content = value; }};
    const form = {elements: fields || [
        {name: 'q', value: '', type: 'search', checked: undefined},
        {name: 'classroom_id', value: '3', type: 'select-one', checked: undefined},
        {name: 'view', value: 'feed', type: 'hidden', checked: undefined},
    ]};
    const connection = {dataset: {}, textContent: ''};
    const updated = {textContent: ''};
    const revision = {hidden: true};
    const elements = new Map([
        ['[data-publication-monitor]', detail ? null : monitor],
        ['[data-publication-detail]', detail ? monitor : null],
        ['[data-publication-results]', detail ? null : results],
        ['[data-publication-filters]', detail ? null : form],
        ['[data-publication-connection]', connection],
        ['[data-publication-updated]', updated],
        ['[data-publication-revision]', detail ? revision : null],
    ]);
    doc.querySelector = selector => elements.get(selector) ?? null;
    doc.activeElement = form.elements[0];
    const calls = [];
    const intervals = new Map();
    let serial = 0;
    let network = reply;
    const api = bootAdminPublications(doc, {
        window: win,
        intervalMs,
        setIntervalFn(callback, milliseconds) { const id = ++serial; intervals.set(id, {callback, milliseconds}); return id; },
        clearIntervalFn(id) { intervals.delete(id); },
        async fetchFn(url, options) {
            const call = {url, options};
            calls.push(call);
            return network ? network(call) : {ok: true, redirected: false, json: async () => ({version: 'new-version', html: 'new publications', checked_at: '2026-10-24T08:30:00Z'})};
        },
    });
    return {doc, win, monitor, results, form, connection, updated, revision, calls, intervals, api,
        setNetwork(value) { network = value; }, get replacements() { return replacements; }};
}

test('monitoring is inactive on unrelated pages', () => {
    assert.equal(bootAdminPublications({querySelector: () => null}), null);
});

test('polls use authenticated read-only JSON requests and retain classroom, view, page and known version', async () => {
    const h = harness();
    assert.equal(h.calls.length, 0);
    assert.equal([...h.intervals.values()][0].milliseconds, 2000);
    await h.api.refresh();
    const {url, options} = h.calls[0];
    const parsed = new URL(url);
    assert.equal(parsed.origin, origin);
    assert.equal(parsed.pathname, '/admin/publications/refresh');
    assert.equal(parsed.searchParams.get('classroom_id'), '3');
    assert.equal(parsed.searchParams.get('view'), 'feed');
    assert.equal(parsed.searchParams.get('page'), '2');
    assert.equal(parsed.searchParams.get('version'), 'initial-version');
    assert.equal(options.method, 'GET');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.cache, 'no-store');
    assert.equal(options.headers.Accept, 'application/json');
    assert.ok(options.signal instanceof AbortSignal);
    h.api.dispose();
});

test('a new publication updates only the result region and keeps search, filters and keyboard focus', async () => {
    const h = harness({fields: [{name: 'q', value: 'الهمزة', type: 'search'}, {name: 'classroom_id', value: '3', type: 'select-one'}]});
    const active = h.doc.activeElement;
    await h.api.refresh();
    assert.equal(h.results.innerHTML, 'new publications');
    assert.equal(h.results.dataset.publicationVersion, 'new-version');
    assert.equal(h.results.dataset.fingerprint, 'new-version');
    assert.equal(h.form.elements[0].value, 'الهمزة');
    assert.equal(h.form.elements[1].value, '3');
    assert.equal(h.doc.activeElement, active);
    assert.equal(h.connection.dataset.state, 'live');
    assert.equal(h.updated.dateTime, '2026-10-24T08:30:00Z');
    assert.notEqual(h.updated.textContent, '');
    h.api.dispose();
});

test('unchanged versions never replace the visible result tree', async () => {
    const h = harness({reply: async () => ({ok: true, json: async () => ({version: 'initial-version', html: null, checked_at: '2026-10-24T08:30:00Z'})})});
    await h.api.refresh();
    assert.equal(h.replacements, 0);
    assert.equal(h.results.innerHTML, 'existing publications');
    assert.equal(h.connection.dataset.state, 'live');
    h.api.dispose();
});

test('overlapping timer ticks do not issue simultaneous refresh requests', async () => {
    const pending = deferred();
    const h = harness({reply: () => pending.promise});
    const first = h.api.refresh();
    await h.api.refresh();
    [...h.intervals.values()][0].callback();
    assert.equal(h.calls.length, 1);
    pending.resolve({ok: true, json: async () => ({version: 'done', html: 'done'})});
    await first;
    assert.equal(h.results.innerHTML, 'done');
    h.api.dispose();
});

test('hidden pages do not poll and becoming visible checks for new publications', async () => {
    const h = harness({hidden: true});
    await h.api.refresh();
    assert.equal(h.calls.length, 0);
    h.doc.hidden = false;
    await h.doc.emit('visibilitychange');
    assert.equal(h.calls.length, 1);
    assert.equal(h.results.innerHTML, 'new publications');
    h.api.dispose();
});

test('responses arriving while hidden cannot replace the current results', async () => {
    const pending = deferred();
    const h = harness({reply: () => pending.promise});
    const work = h.api.refresh();
    h.doc.hidden = true;
    await h.doc.emit('visibilitychange');
    assert.equal(h.calls[0].options.signal.aborted, true);
    pending.resolve({ok: true, json: async () => ({version: 'old-hidden-request', html: 'must not appear'})});
    await work;
    assert.equal(h.replacements, 0);
    h.api.dispose();
});

test('editing a filter while its request is running rejects stale results and preserves the unsent filter', async () => {
    const pending = deferred();
    const h = harness({reply: () => pending.promise});
    const work = h.api.refresh();
    h.form.elements[0].value = 'بحث جديد';
    pending.resolve({ok: true, json: async () => ({version: 'stale', html: 'wrong filter results'})});
    await work;
    assert.equal(h.replacements, 0);
    assert.equal(h.form.elements[0].value, 'بحث جديد');
    await h.api.refresh();
    assert.equal(h.calls.length, 1);
    h.api.dispose();
});

test('changing page or classroom in the URL rejects responses belonging to the previous view', async () => {
    const pending = deferred();
    const h = harness({reply: () => pending.promise});
    const work = h.api.refresh();
    h.win.location.href = `${origin}/admin/publications?view=feed&classroom_id=8&page=1`;
    pending.resolve({ok: true, json: async () => ({version: 'wrong-page', html: 'previous classroom'})});
    await work;
    assert.equal(h.replacements, 0);
    await h.api.refresh();
    assert.equal(h.calls.length, 1);
    h.api.dispose();
});

test('failed or redirected requests preserve results, show a connection state and back off until visibility retries', async () => {
    for (const reply of [
        async () => { throw new Error('offline'); },
        async () => ({ok: false, status: 500, json: async () => ({html: 'server error'})}),
        async () => ({ok: true, redirected: true, json: async () => ({version: 'login', html: 'login form'})}),
        async () => ({ok: true, json: async () => { throw new SyntaxError('not JSON'); }}),
    ]) {
        const h = harness({reply});
        await h.api.refresh();
        assert.equal(h.replacements, 0);
        assert.equal(h.connection.dataset.state, 'offline');
        await h.api.refresh();
        assert.equal(h.calls.length, 1);
        h.setNetwork(async () => ({ok: true, json: async () => ({version: 'recovered', html: 'recovered publications'})}));
        await h.doc.emit('visibilitychange');
        assert.equal(h.results.innerHTML, 'recovered publications');
        assert.equal(h.connection.dataset.state, 'live');
        h.api.dispose();
    }
});

test('cross-origin monitoring endpoints are never fetched', async () => {
    const h = harness({endpoint: 'https://outside.test/admin/publications/refresh'});
    await h.api.refresh();
    assert.equal(h.calls.length, 0);
    assert.equal(h.replacements, 0);
    h.api.dispose();
});

test('invalid JSON payloads cannot update the result region', async () => {
    for (const data of [{html: '<h1>no version</h1>'}, {version: 42, html: 'wrong version type'}, {version: 'new', html: {unexpected: 'object'}}]) {
        const h = harness({reply: async () => ({ok: true, json: async () => data})});
        await h.api.refresh();
        assert.equal(h.replacements, 0);
        h.api.dispose();
    }
});

test('a changed detail page shows a revision notice rather than overwriting grades the admin is reading', async () => {
    const h = harness({detail: true, reply: async () => ({ok: true, json: async () => ({version: 'changed-detail', checked_at: '2026-10-24T08:30:00Z'})})});
    await h.api.refresh();
    assert.equal(h.revision.hidden, false);
    assert.equal(h.replacements, 0);
    assert.equal(new URL(h.calls[0].url).pathname, '/admin/publications/lesson/11');
    await h.api.refresh();
    assert.equal(h.calls.length, 1);
    h.api.dispose();
});

test('unchanged detail data keeps the revision notice hidden', async () => {
    const h = harness({detail: true, reply: async () => ({ok: true, json: async () => ({version: 'initial-version'})})});
    await h.api.refresh();
    assert.equal(h.revision.hidden, true);
    h.api.dispose();
});

test('disposal aborts requests, stops timers, removes listeners and prevents late results from applying', async () => {
    const pending = deferred();
    const h = harness({reply: () => pending.promise});
    const work = h.api.refresh();
    h.api.dispose();
    h.api.dispose();
    assert.equal(h.calls[0].options.signal.aborted, true);
    assert.equal(h.intervals.size, 0);
    assert.equal(h.doc.listeners.get('visibilitychange').size, 0);
    assert.equal(h.win.listeners.get('pagehide').size, 0);
    pending.resolve({ok: true, json: async () => ({version: 'too-late', html: 'no longer this page'})});
    await work;
    assert.equal(h.replacements, 0);
    await h.api.refresh();
    assert.equal(h.calls.length, 1);
});

test('leaving the page disposes its live monitor', async () => {
    const h = harness();
    await h.win.emit('pagehide');
    assert.equal(h.intervals.size, 0);
    await h.api.refresh();
    assert.equal(h.calls.length, 0);
});

test('a page restored from the back and forward cache resumes updates with one interval and intact filters', async () => {
    const h = harness();
    await h.api.refresh();
    const interval = [...h.intervals.keys()][0];
    const focusedSearch = h.doc.activeElement;
    await h.win.emit('pagehide', {persisted: true});
    assert.equal(h.intervals.size, 1);
    assert.equal(h.calls[0].options.signal.aborted, true);
    await h.api.refresh();
    h.intervals.get(interval).callback();
    await flush();
    assert.equal(h.calls.length, 1);

    h.setNetwork(async () => ({ok: true, json: async () => ({version: 'published-while-away', html: 'new publication after returning'})}));
    await h.win.emit('pageshow', {persisted: true});
    assert.equal(h.calls.length, 2);
    assert.equal(h.results.innerHTML, 'new publication after returning');
    assert.equal(h.intervals.size, 1);
    assert.equal([...h.intervals.keys()][0], interval);
    assert.equal(h.form.elements[1].value, '3');
    assert.equal(h.doc.activeElement, focusedSearch);
    h.api.dispose();
});

test('a fully disposed page does not restart monitoring when an unrelated pageshow event arrives', async () => {
    const h = harness();
    await h.win.emit('pagehide', {persisted: false});
    assert.equal(h.intervals.size, 0);
    await h.win.emit('pageshow', {persisted: true});
    await h.api.refresh();
    assert.equal(h.intervals.size, 0);
    assert.equal(h.calls.length, 0);
    assert.equal(h.win.listeners.get('pageshow').size, 0);
});
