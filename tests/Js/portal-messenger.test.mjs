import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
    bootPortalMessenger, matchesInboxSearch, messengerViewportHeight, normalizeInboxSearch, safeConversationReadUrl,
    syncChildUnreadBadges,
} from '../../public/js/portal-messenger.js';

const origin = 'https://school.test';
const uuid = '52f9bb08-b6c4-4710-8b63-b67311d93cb2';
const receiptUrl = `/teacher-message-receipts/${uuid}/delivered?expires=2000000000&signature=${'a'.repeat(64)}`;
const viewedUrl = `/teacher-messages/${uuid}/viewed`;
const readUrl = '/parent/messages/read?thread=teacher_6';
const flush = () => new Promise(resolve => setImmediate(resolve));
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};

// A small event/element model keeps these tests independent of a browser and any network.
// Refresh uses the public parser injection; imports clone nodes instead of sharing fixtures.
class Element {
    constructor(attributes = {}, children = [], text = '') {
        this.attributes = { ...attributes };
        this.childNodes = children;
        this.textContent = text;
        this.listeners = new Map();
        this.value = '';
        this.hidden = 'hidden' in attributes;
        this.disabled = false;
        this.style = { setProperty(name, value) { this[name] = value; } };
        this.scrollTop = 0;
        this.clientHeight = 300;
        this.rect = { top: 0, bottom: 300 };
    }
    getAttribute(name) { return Object.hasOwn(this.attributes, name) ? this.attributes[name] : null; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    get scrollHeight() { return this.height ?? Math.max(300, this.childNodes.length * 150); }
    get innerHTML() { return this.childNodes.map(node => node.serialize()).join(''); }
    serialize() { return JSON.stringify([this.attributes, this.textContent, this.innerHTML]); }
    matches(selector) {
        if (/^[a-z]+$/.test(selector)) return this.tagName === selector;
        if (selector.startsWith('.')) return (this.attributes.class ?? '').split(' ').includes(selector.slice(1));
        const match = /^(?:meta)?\[([^=\]]+)(?:="([^"]*)")?\]$/.exec(selector);
        return !!match && Object.hasOwn(this.attributes, match[1])
            && (match[2] === undefined || this.attributes[match[1]] === match[2]);
    }
    querySelectorAll(selector) {
        return this.childNodes.flatMap(node => [
            ...(node.matches(selector) ? [node] : []), ...node.querySelectorAll(selector),
        ]);
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
    replaceChildren(...children) { this.childNodes = children; }
    getBoundingClientRect() { return this.rect; }
    addEventListener(name, listener) {
        if (!this.listeners.has(name)) this.listeners.set(name, []);
        this.listeners.get(name).push(listener);
    }
    async emit(name) {
        const event = { type: name, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
        for (const listener of this.listeners.get(name) ?? []) await listener(event);
        await flush();
        return event;
    }
    focus() { this.focused = true; }
    cloneNode() {
        const copy = new Element(this.attributes, this.childNodes.map(node => node.cloneNode()), this.textContent);
        copy.value = this.value;
        copy.rect = { ...this.rect };
        copy.height = this.height;
        copy.tagName = this.tagName;
        return copy;
    }
}

const node = (attribute, children = [], text = '') => new Element({ [attribute]: '' }, children, text);
const contact = name => new Element({ 'data-pm-contact': '', 'data-search': name }, [], name);
const bubble = (key, { received, viewed, top = 20, bottom = 80, text = key } = {}) => {
    const item = new Element({
        'data-message-key': key,
        ...(received ? { 'data-pm-receipt': received } : {}),
        ...(viewed ? { 'data-pm-viewed': viewed } : {}),
    }, [], text);
    item.rect = { top, bottom };
    return item;
};

function childSelect(entries, selected) {
    const select = new Element({ 'data-unread-badges': '', name: 'student' }, entries.map(entry => {
        const option = new Element({
            value: String(entry.id), 'data-unread-count': String(entry.count), 'data-option-label': entry.label,
        }, [], entry.text ?? `${entry.label} — ${entry.count} غير مقروءة`);
        option.tagName = 'option';
        option.selected = String(entry.id) === String(selected);
        return option;
    }));
    select.tagName = 'select';
    select.value = String(selected);
    select.selectedIndex = entries.findIndex(entry => String(entry.id) === String(selected));
    return select;
}

function markup({
    role = 'parent', selected = 'teacher_6', contacts = ['المعلمة مَرْيَم أحمد', 'المعلم سامي'],
    messages = [bubble('message-1')], read = '', last = 'message-1', draft = '', search = '', student = '', childOptions = null,
} = {}) {
    const nodes = {
        search: node('data-pm-search'), list: node('data-pm-threads', contacts.map(contact)),
        total: node('data-pm-total', [], String(contacts.length)), empty: node('data-pm-search-empty'),
        stream: new Element({ 'data-pm-stream': '', 'data-read-action': read, 'data-last-key': last }, messages),
        body: node('data-pm-body'), csrfInput: new Element({ name: '_token', value: 'native-csrf' }),
        child: new Element({ name: 'student_id' }), button: node('data-pm-send'),
        feedback: node('data-pm-feedback'), connection: node('data-pm-connection'),
        newMessages: node('data-pm-new'), refresh: node('data-pm-refresh'),
    };
    nodes.search.value = search;
    nodes.body.value = draft;
    nodes.child.value = '17';
    nodes.empty.hidden = nodes.feedback.hidden = nodes.connection.hidden = nodes.newMessages.hidden = true;
    nodes.form = new Element({ 'data-pm-composer': '', method: 'post', action: '/parent/messages' }, [
        nodes.csrfInput, nodes.child, nodes.body, nodes.button, nodes.feedback,
    ]);
    nodes.shell = new Element({ class: selected ? 'pm-shell' : 'pm-shell pm-shell--directory' }, [
        nodes.search, nodes.list, nodes.total, nodes.empty,
        ...(selected ? [nodes.stream, nodes.form, nodes.newMessages, nodes.refresh] : []), nodes.connection,
    ]);
    const app = new Element({ 'data-messenger': '', 'data-role': role, 'data-selected-key': selected, 'data-student-id': student }, [nodes.shell]);
    const page = new Element({}, [app]);
    if (childOptions) {
        nodes.childSelect = childSelect(childOptions, student);
        page.childNodes.push(nodes.childSelect);
    }
    return { ...nodes, app, page };
}

function harness(settings = {}) {
    const elements = markup(settings);
    const document = elements.page;
    const calls = [];
    const observers = [];
    const layoutObservers = [];
    const intervals = new Map();
    let nextInterval = 0;
    let incoming = markup(settings);
    let network = settings.network;
    const window = new Element();
    window.location = { origin, href: origin + '/parent/messages?thread=teacher_6' };
    window.setInterval = (callback, milliseconds) => {
        const id = ++nextInterval;
        intervals.set(id, { callback, milliseconds });
        return id;
    };
    window.clearInterval = id => intervals.delete(id);
    if (settings.observer) {
        window.IntersectionObserver = class {
            constructor(callback, options) { this.callback = callback; this.options = options; this.targets = []; observers.push(this); }
            observe(target) { this.targets.push(target); }
            disconnect() { this.disconnected = true; }
            intersect(target, isIntersecting = true) { this.callback([{ target, isIntersecting }]); }
        };
    }
    if (settings.resizeObserver) {
        window.ResizeObserver = class {
            constructor(callback) { this.callback = callback; this.targets = []; layoutObservers.push(this); }
            observe(target) { if (!this.targets.includes(target)) this.targets.push(target); }
            disconnect() { this.targets = []; }
            resize() { this.callback([]); }
        };
    }
    document.defaultView = window;
    document.visibilityState = settings.visibility ?? 'visible';
    document.importNode = source => source.cloneNode(true);
    if (settings.viewport) {
        const viewport = settings.viewport;
        window.innerHeight = viewport.height;
        window.innerWidth = viewport.width;
        elements.shell.rect.top = viewport.shellTop;
        if (viewport.visualHeight) {
            window.visualViewport = new Element();
            window.visualViewport.height = viewport.visualHeight;
        }
        if (viewport.navigation) {
            elements.navigation = new Element({ class: 'bottom-nav', 'data-position': viewport.navigation.position });
            elements.navigation.rect = { ...viewport.navigation };
            document.childNodes.push(elements.navigation);
        }
        window.getComputedStyle = element => ({ position: element.getAttribute('data-position') });
    }
    if (settings.csrf !== null) document.childNodes.push(new Element({ name: 'messenger-csrf', content: settings.csrf ?? 'session-csrf' }));
    const send = async (url, options) => {
        const call = { url: String(url), options };
        calls.push(call);
        return network ? network(call) : { ok: true, text: async () => 'incoming-document' };
    };
    const api = bootPortalMessenger(document, {
        origin, send, parser: html => {
            assert.equal(html, 'incoming-document');
            return incoming.page;
        },
        pollInterval: 12000,
    });
    return {
        ...elements, document, window, api, calls, intervals, observers, layoutObservers,
        get posts() { return calls.filter(call => call.options.method === 'POST'); },
        get polls() { return calls.filter(call => call.options.method !== 'POST'); },
        update(value) { incoming = markup(value); return incoming; },
        setNetwork(value) { network = value; },
        setVisibility(value) { document.visibilityState = value; },
    };
}

test('Arabic inbox search normalizes diacritics, hamza, tatweel, letter variants, and whitespace', () => {
    assert.equal(normalizeInboxSearch('  مُعَلِّمَة   إيمَان\nأَحمَـد على  '), 'معلمه ايمان احمد علي');
    assert.equal(normalizeInboxSearch('ا\u0654حمد  ENGLISH'), 'احمد english');
    assert.equal(normalizeInboxSearch(null), '');
    assert.equal(matchesInboxSearch('المعلمة إيمان أحمد — والدة هدى', 'هدي ايمان'), true);
    assert.equal(matchesInboxSearch('المعلمة إيمان أحمد', 'احمد سامي'), false);
    assert.equal(matchesInboxSearch('المعلم سامي', '  '), true);
});

test('messenger viewport height reserves fixed navigation space and respects its usable minimum and desktop maximum', () => {
    assert.equal(messengerViewportHeight(844, 180, 64), 586);
    assert.equal(messengerViewportHeight(420, 230, 64), 220);
    assert.equal(messengerViewportHeight(1400, -50), 730);
});

test('the shell fits the visual viewport above a fixed bottom navigation and recalculates on keyboard resize', async () => {
    const page = harness({ viewport: {
        height: 844, width: 390, shellTop: 180, visualHeight: 600,
        navigation: { width: 390, height: 64, position: 'fixed' },
    } });
    assert.equal(page.shell.style.height, '342px');
    assert.equal(page.shell.style.minHeight, '220px');
    page.window.visualViewport.height = 420;
    await page.window.visualViewport.emit('resize');
    assert.equal(page.shell.style.height, '220px');
    page.window.visualViewport.height = 600;
    page.navigation.setAttribute('data-position', 'static');
    await page.window.emit('resize');
    assert.equal(page.shell.style.height, '406px', 'in-flow navigation must not be subtracted a second time');
});

test('a centered 520px parent footer reserves its height in a 980px viewport without treating a vertical sidebar as a footer', () => {
    const page = harness({ viewport: {
        height: 844, width: 980, shellTop: 180,
        navigation: { width: 520, height: 70, position: 'fixed' },
    } });
    assert.equal(page.shell.style.height, '580px');
    assert.equal(page.shell.style['--pm-available-height'], '580px');
    const desktop = harness({ viewport: {
        height: 844, width: 1280, shellTop: 180,
        navigation: { width: 240, height: 844, position: 'fixed' },
    } });
    assert.equal(desktop.shell.style.height, '650px', 'a vertical desktop navigation does not consume bottom space');
});

test('the unselected directory uses natural height with a viewport limit and creates no conversation or composer', async () => {
    const page = harness({
        selected: '', read: readUrl, messages: [bubble('broadcast-1', { received: receiptUrl, viewed: viewedUrl })],
        viewport: { height: 844, width: 390, shellTop: 180, navigation: { width: 390, height: 64, position: 'fixed' } },
    });
    await flush();
    assert.equal(page.shell.style.height, 'auto');
    assert.equal(page.shell.style.minHeight, '0px');
    assert.equal(page.shell.style['--pm-available-height'], '586px');
    assert.equal(page.document.querySelector('[data-pm-stream]'), null);
    assert.equal(page.document.querySelector('[data-pm-composer]'), null);
    assert.equal(page.document.querySelector('[data-pm-body]'), null);
    page.window.innerHeight = 600;
    await page.window.emit('resize');
    assert.equal(page.shell.style['--pm-available-height'], '342px');
    assert.equal(page.shell.style.height, 'auto');
    page.api.acknowledge();
    await flush();
    assert.equal(page.posts.length, 0);
});

test('directory search and polling refresh all teacher contacts without opening or marking a conversation viewed', async () => {
    const page = harness({ selected: '', search: 'مريم' });
    page.list.scrollTop = 31;
    assert.deepEqual(page.list.childNodes.map(item => item.hidden), [false, true]);
    page.update({ selected: '', contacts: ['المعلم سامي', 'المعلمة مريم أحمد', 'المعلمة مريم علي', 'المعلم خالد'] });
    await page.api.refresh();
    assert.equal(page.search.value, 'مريم');
    assert.equal(page.total.textContent, '4');
    assert.deepEqual(page.list.childNodes.map(item => item.hidden), [true, false, false, true]);
    assert.equal(page.list.scrollTop, 31);
    assert.equal(page.empty.hidden, true);
    assert.equal(page.document.querySelector('[data-pm-stream]'), null);
    assert.equal(page.document.querySelector('[data-pm-composer]'), null);
    await page.window.emit('online');
    assert.equal(page.posts.length, 0);
    assert.equal(page.polls.length, 2);
    page.search.value = 'معلم غير موجود';
    await page.search.emit('input');
    assert.equal(page.empty.hidden, false);
    page.update({ selected: '', contacts: [] });
    await page.api.refresh();
    assert.equal(page.total.textContent, '0');
    assert.equal(page.posts.length, 0);
});

test('parent polling pins the rendered child even when the URL lacks a child or still names a sibling', async () => {
    const page = harness({ student: '17' });
    for (const address of [
        origin + '/parent/messages?thread=teacher_6',
        origin + '/parent/messages?thread=teacher_6&student=22&student=23&before=100',
    ]) {
        page.window.location.href = address;
        await page.api.refresh();
        const requested = new URL(page.polls.at(-1).url);
        assert.deepEqual(requested.searchParams.getAll('student'), ['17']);
        assert.equal(requested.searchParams.get('thread'), 'teacher_6');
        assert.equal(requested.searchParams.get('before'), new URL(address).searchParams.get('before'));
        assert.equal(page.window.location.href, address, 'polling does not navigate or rewrite the address bar');
    }
    const teacher = harness({ role: 'teacher', student: '17' });
    teacher.window.location.href = origin + '/teacher/messages?thread=family_8';
    await teacher.api.refresh();
    assert.equal(new URL(teacher.polls[0].url).searchParams.has('student'), false, 'the parent child parameter is not added to teacher requests');
});

test('a same-teacher response for a sibling or another role cannot replace messages, change badges, or acknowledge that child', async () => {
    const children = [{ id: 17, count: 1, label: 'أحمد' }, { id: 22, count: 2, label: 'مريم' }];
    for (const incomingContext of [{ student: '22', role: 'parent' }, { student: '17', role: 'teacher' }]) {
        const page = harness({ student: '17', childOptions: children, draft: 'مسودة لأحمد' });
        const beforeStream = page.stream.innerHTML;
        const beforeList = page.list.innerHTML;
        page.update({
            ...incomingContext, selected: 'teacher_6', contacts: ['معلم الرسائل الأخرى'],
            messages: [bubble('sibling-message', { received: receiptUrl, viewed: viewedUrl + '?student=22' })],
            read: readUrl + '&student=22', last: 'sibling-message',
            childOptions: children.map(child => ({ ...child, count: 99 })),
        });
        await page.api.refresh();
        await flush();
        assert.equal(page.stream.innerHTML, beforeStream);
        assert.equal(page.list.innerHTML, beforeList);
        assert.equal(page.body.value, 'مسودة لأحمد');
        assert.deepEqual(page.childSelect.childNodes.map(option => option.getAttribute('data-unread-count')), ['1', '2']);
        assert.equal(page.connection.hidden, false);
        assert.equal(page.posts.length, 0);
    }
});

test('badge synchronization matches child IDs rather than order and never changes selection or dispatches change', () => {
    const entries = [{ id: 17, count: 1, label: 'أحمد' }, { id: 22, count: 2, label: 'مريم' }];
    const page = markup({ student: '17', childOptions: entries });
    const secondSelect = childSelect(entries, '22');
    page.page.childNodes.push(secondSelect);
    let changes = 0;
    for (const select of [page.childSelect, secondSelect]) select.addEventListener('change', () => { changes++; });
    const incoming = markup({ student: '22', childOptions: [
        { id: 22, count: 5, label: 'مريم علي', text: 'مريم علي — 5 رسائل غير مقروءة' },
        { id: 17, count: 0, label: 'أحمد', text: 'أحمد' },
        { id: 999, count: 20, label: 'طفل غير موجود' },
    ] });
    syncChildUnreadBadges(page.page, incoming.page);
    assert.equal(page.childSelect.value, '17');
    assert.equal(secondSelect.value, '22');
    assert.equal(page.childSelect.selectedIndex, 0);
    assert.equal(secondSelect.selectedIndex, 1);
    for (const select of [page.childSelect, secondSelect]) {
        assert.equal(select.childNodes.length, 2, 'a polling response cannot add another child option');
        assert.deepEqual(select.childNodes.map(option => option.getAttribute('value')), ['17', '22']);
        assert.deepEqual(select.childNodes.map(option => option.getAttribute('data-unread-count')), ['0', '5']);
        assert.deepEqual(select.childNodes.map(option => option.textContent), ['أحمد', 'مريم علي — 5 رسائل غير مقروءة']);
        assert.equal(select.childNodes[1].getAttribute('data-option-label'), 'مريم علي');
    }
    assert.deepEqual(page.childSelect.childNodes.map(option => option.selected), [true, false]);
    assert.deepEqual(secondSelect.childNodes.map(option => option.selected), [false, true]);
    assert.equal(changes, 0);
    syncChildUnreadBadges(page.page, markup().page);
    assert.equal(page.childSelect.childNodes[1].getAttribute('data-unread-count'), '5', 'a page without badge data leaves the selector untouched');
});

test('a valid child refresh updates badge counts without posting Viewed for a sibling or changing the composer child', async () => {
    const children = [{ id: 17, count: 2, label: 'أحمد' }, { id: 22, count: 4, label: 'مريم' }];
    const page = harness({ student: '17', childOptions: children, draft: 'رسالة تخص أحمد' });
    page.update({
        student: '17', childOptions: children.map(child => ({ ...child, count: child.id === 17 ? 1 : 6 })),
        messages: [bubble('ahmed-message', { viewed: viewedUrl + '?student=17' })],
        read: readUrl + '&student=17&read_token=child17-snapshot', last: 'ahmed-message',
    });
    let changes = 0;
    page.childSelect.addEventListener('change', () => { changes++; });
    await page.api.refresh();
    await flush();
    assert.deepEqual(page.childSelect.childNodes.map(option => option.getAttribute('data-unread-count')), ['1', '6']);
    assert.equal(page.childSelect.value, '17');
    assert.equal(page.child.value, '17');
    assert.equal(page.body.value, 'رسالة تخص أحمد');
    assert.equal(changes, 0);
    assert.equal(page.posts.length, 2);
    assert.ok(page.posts.every(call => new URL(call.url).searchParams.get('student') === '17'));
    assert.ok(page.posts.some(call => new URL(call.url).pathname.endsWith('/viewed')));
    assert.equal(page.connection.hidden, true);
});

test('layout resizing follows the latest messages but preserves the position of someone reading older messages', async () => {
    const page = harness({
        resizeObserver: true, messages: Array.from({ length: 10 }, (_, index) => bubble('message-' + index)),
        viewport: { height: 844, width: 980, shellTop: 180 },
    });
    assert.equal(page.layoutObservers.length, 1);
    const observer = page.layoutObservers[0];
    assert.ok(observer.targets.includes(page.app));
    assert.ok(observer.targets.includes(page.stream));
    page.stream.height = 1800;
    observer.resize();
    assert.equal(page.stream.scrollTop, 1800);
    page.stream.scrollTop = 180;
    await page.stream.emit('scroll');
    page.stream.height = 2100;
    observer.resize();
    assert.equal(page.stream.scrollTop, 180);
    await page.newMessages.emit('click');
    page.stream.height = 2300;
    observer.resize();
    assert.equal(page.stream.scrollTop, 2300);
});

test('both directory and selected-chat container observation survive back-forward page restoration', async () => {
    for (const selected of ['', 'teacher_6']) {
        const page = harness({
            selected, resizeObserver: true,
            viewport: { height: 844, width: 980, shellTop: 180 },
        });
        assert.equal(page.layoutObservers.length, 1, 'the directory also needs container observation');
        const observer = page.layoutObservers[0];
        assert.ok(observer.targets.includes(page.app));
        assert.equal(observer.targets.includes(page.stream), Boolean(selected));
        assert.equal(page.shell.style['--pm-available-height'], '650px');
        await page.window.emit('pagehide');
        assert.equal(observer.targets.length, 0);
        page.shell.rect.top = 260;
        observer.resize();
        assert.equal(page.shell.style['--pm-available-height'], '650px', 'a disposed page ignores stale resize callbacks');
        await page.window.emit('pageshow');
        assert.ok(observer.targets.includes(page.app));
        assert.equal(observer.targets.includes(page.stream), Boolean(selected));
        observer.resize();
        assert.equal(page.shell.style['--pm-available-height'], '570px');
        assert.equal(page.shell.style.height, selected ? '570px' : 'auto');
        assert.equal(page.intervals.size, 1, 'history restoration must not duplicate polling timers');
    }
});

test('conversation read URLs must use the same origin, exact role endpoint, and opaque thread key', () => {
    assert.equal(safeConversationReadUrl(readUrl, origin, 'parent'), origin + readUrl);
    assert.equal(safeConversationReadUrl(readUrl + '&read_token=server-issued-token', origin, 'parent'), origin + readUrl + '&read_token=server-issued-token');
    assert.equal(safeConversationReadUrl('/teacher/messages/read?thread=conversation-19', origin, 'teacher'), origin + '/teacher/messages/read?thread=conversation-19');
    const invalid = [
        'https://other.test' + readUrl, '//other.test' + readUrl, 'javascript:alert(1)',
        '/logout?thread=teacher_6', '/parent/messages?thread=teacher_6',
        '/teacher/messages/read?thread=teacher_6', '/parent/messages/read',
        '/parent/messages/read?thread=', '/parent/messages/read?thread=a%2Fb',
        '/parent/messages/read?thread=a%20b', '/parent/messages/read?thread=' + 'a'.repeat(101),
        readUrl + '#fragment', receiptUrl, viewedUrl,
    ];
    for (const value of invalid) assert.equal(safeConversationReadUrl(value, origin, 'parent'), null, value);
    assert.equal(safeConversationReadUrl('/student/messages/read?thread=teacher_6', origin, 'student'), null);
});

test('search filters real contacts with all tokens and shows an empty result only for a nonempty query', async () => {
    const page = harness();
    page.search.value = 'احمد مريم';
    await page.search.emit('input');
    assert.deepEqual(page.list.childNodes.map(item => item.hidden), [false, true]);
    assert.equal(page.empty.hidden, true);
    page.search.value = 'غير موجود';
    await page.search.emit('input');
    assert.equal(page.empty.hidden, false);
    page.search.value = '   ';
    await page.search.emit('input');
    assert.deepEqual(page.list.childNodes.map(item => item.hidden), [false, false]);
    assert.equal(page.empty.hidden, true);
});

test('a hidden inbox acknowledges receipt but never reads a conversation, marks viewed, or polls', async () => {
    const page = harness({ visibility: 'hidden', read: readUrl, messages: [bubble('broadcast-1', { received: receiptUrl, viewed: viewedUrl })] });
    await flush();
    page.api.acknowledge();
    await page.api.refresh();
    await page.window.emit('online');
    assert.equal(page.calls.length, 1);
    assert.equal(page.posts[0].url, origin + receiptUrl);
    assert.equal(page.posts[0].options.headers['X-CSRF-TOKEN'], undefined);
    page.setVisibility('visible');
    await page.document.emit('visibilitychange');
    assert.deepEqual(page.posts.map(call => call.url), [origin + receiptUrl, origin + readUrl, origin + viewedUrl]);
    for (const call of page.posts.slice(1)) {
        assert.equal(call.options.headers['X-CSRF-TOKEN'], 'session-csrf');
        assert.equal(call.options.credentials, 'same-origin');
    }
});

test('only intersecting broadcast bubbles are viewed and queued observer callbacks cannot mark hidden tabs', async () => {
    const item = bubble('broadcast-1', { viewed: viewedUrl });
    const page = harness({ observer: true, messages: [item] });
    assert.equal(page.observers.length, 1);
    const observer = page.observers[0];
    assert.equal(observer.options.root, page.stream);
    observer.intersect(item, false);
    await flush();
    assert.equal(page.posts.length, 0);
    page.setVisibility('hidden');
    await page.document.emit('visibilitychange');
    assert.equal(observer.disconnected, true);
    observer.intersect(item);
    await flush();
    assert.equal(page.posts.length, 0);
    page.setVisibility('visible');
    page.api.acknowledge();
    page.observers.at(-1).intersect(item);
    await flush();
    assert.equal(page.posts.length, 1);
    assert.equal(page.posts[0].url, origin + viewedUrl);
});

test('becoming hidden or leaving the page before a queued request starts cannot mark anything read or viewed', async () => {
    for (const state of ['hidden', 'disposed']) {
        const page = harness({ read: readUrl, messages: [bubble('broadcast-1', { viewed: viewedUrl })] });
        if (state === 'hidden') page.setVisibility('hidden');
        else page.api.dispose();
        await flush();
        assert.equal(page.posts.length, 0, state);
        if (state === 'hidden') {
            page.setVisibility('visible');
            page.api.acknowledge();
            await flush();
            assert.deepEqual(page.posts.map(call => call.url), [origin + readUrl, origin + viewedUrl]);
        }
    }
});

test('without IntersectionObserver, only bubbles in the stream viewport count as viewed', async () => {
    const other = '/teacher-messages/952083ed-37a9-4909-8685-3e0ea9cdd7e7/viewed';
    const page = harness({ messages: [
        bubble('on-screen', { viewed: viewedUrl, top: 20, bottom: 90 }),
        bubble('off-screen', { viewed: other, top: 400, bottom: 500 }),
    ] });
    await flush();
    assert.deepEqual(page.posts.map(call => call.url), [origin + viewedUrl]);
});

test('unsafe read and broadcast endpoints are ignored and missing CSRF never marks read or viewed', async () => {
    for (const csrf of [null, '']) {
        const page = harness({ csrf, read: readUrl, messages: [bubble('message', { viewed: viewedUrl, received: receiptUrl })] });
        await flush();
        assert.deepEqual(page.posts.map(call => call.url), [origin + receiptUrl]);
    }
    const page = harness({
        read: 'https://other.test' + readUrl,
        messages: [bubble('message', { received: 'https://other.test' + receiptUrl, viewed: 'https://other.test' + viewedUrl })],
    });
    await flush();
    assert.equal(page.posts.length, 0);
});

test('receipts deduplicate by stable message key even when signed URLs rotate during polling', async () => {
    const page = harness({ messages: [bubble('broadcast-1', { received: receiptUrl })] });
    await flush();
    page.update({ messages: [bubble('broadcast-1', { received: receiptUrl.replace('2000000000', '2000000015') })] });
    await page.api.refresh();
    await flush();
    assert.equal(page.posts.length, 1);
    assert.equal(page.posts[0].url, origin + receiptUrl);
});

test('receipt retries coalesce pending requests and retry network or HTTP errors until a successful acknowledgement', async () => {
    const first = deferred();
    let attempts = 0;
    const page = harness({ messages: [bubble('broadcast-1', { received: receiptUrl }), bubble('broadcast-1', { received: receiptUrl })], network: call => {
        if (call.options.method !== 'POST') return { ok: true, text: async () => 'incoming-document' };
        attempts++;
        if (attempts === 1) return first.promise;
        if (attempts === 2) return { ok: false, status: 503 };
        return { ok: true, status: 204 };
    } });
    await flush();
    page.api.acknowledge();
    page.api.acknowledge();
    await flush();
    assert.equal(attempts, 1);
    first.reject(new Error('offline'));
    await flush();
    await page.window.emit('online');
    assert.equal(attempts, 2);
    await page.document.emit('visibilitychange');
    assert.equal(attempts, 3);
    await page.window.emit('online');
    assert.equal(attempts, 3);
    assert.equal(page.connection.hidden, true);
});

test('failed viewed requests are retried when visible and successful views are never posted again', async () => {
    let attempts = 0;
    const page = harness({ messages: [bubble('broadcast-1', { viewed: viewedUrl })], network: call => {
        if (call.options.method !== 'POST') return { ok: true, text: async () => 'incoming-document' };
        attempts++;
        return { ok: attempts > 1, status: attempts > 1 ? 204 : 503 };
    } });
    await flush();
    assert.equal(attempts, 1);
    page.setVisibility('hidden');
    await page.window.emit('online');
    page.api.acknowledge();
    await flush();
    assert.equal(attempts, 1);
    page.setVisibility('visible');
    await page.document.emit('visibilitychange');
    assert.equal(attempts, 2);
    await page.window.emit('online');
    assert.equal(attempts, 2);
    for (const call of page.posts) {
        assert.equal(call.url, origin + viewedUrl);
        assert.equal(call.options.headers['X-CSRF-TOKEN'], 'session-csrf');
    }
});

test('polling replaces messages and contact rows while preserving draft, child choice, search, CSRF, and scroll position', async () => {
    const draft = 'مسودة طويلة\n' + 'نص لم أرسله بعد '.repeat(1000);
    const page = harness({ draft, search: 'مريم', messages: Array.from({ length: 10 }, (_, i) => bubble('message-' + i)), last: 'message-9' });
    page.body.selectionStart = 25;
    page.body.selectionEnd = 34;
    page.child.value = '22';
    page.stream.scrollTop = 160;
    page.list.scrollTop = 43;
    const incoming = page.update({
        contacts: ['المعلم سامي', 'المعلمة مَرْيَم أحمد', 'المعلم خالد'],
        messages: Array.from({ length: 11 }, (_, i) => bubble('message-' + i)), last: 'message-10',
    });
    await page.api.refresh();
    assert.equal(page.body.value, draft);
    assert.equal(page.body.selectionStart, 25);
    assert.equal(page.body.selectionEnd, 34);
    assert.equal(page.child.value, '22');
    assert.equal(page.form.getAttribute('action'), '/parent/messages');
    assert.equal(page.csrfInput.getAttribute('value'), 'native-csrf');
    assert.equal(page.search.value, 'مريم');
    assert.deepEqual(page.list.childNodes.map(item => item.hidden), [true, false, true]);
    assert.equal(page.list.scrollTop, 43);
    assert.equal(page.stream.scrollTop, 160);
    assert.equal(page.stream.childNodes.length, 11);
    assert.notEqual(page.stream.childNodes[0], incoming.stream.childNodes[0]);
    assert.equal(page.total.textContent, '3');
    assert.equal(page.newMessages.hidden, false);
    assert.equal(page.polls[0].options.headers['X-Messenger-Refresh'], '1');
    await page.newMessages.emit('click');
    assert.equal(page.stream.scrollTop, page.stream.scrollHeight);
    assert.equal(page.newMessages.hidden, true);
});

test('polling follows incoming messages only when already near the end and does not announce status-only updates', async () => {
    const messages = Array.from({ length: 10 }, (_, i) => bubble('message-' + i));
    const page = harness({ messages, last: 'message-9' });
    page.stream.scrollTop = page.stream.scrollHeight - page.stream.clientHeight - 30;
    page.update({ messages: [...messages, bubble('message-10')], last: 'message-10' });
    await page.api.refresh();
    assert.equal(page.stream.scrollTop, page.stream.scrollHeight);
    assert.equal(page.newMessages.hidden, true);
    page.stream.scrollTop = 100;
    page.update({ messages: [...messages, bubble('message-10', { text: 'same message, new delivery state' })], last: 'message-10' });
    await page.api.refresh();
    assert.equal(page.stream.scrollTop, 100);
    assert.equal(page.newMessages.hidden, true);
});

test('polling is single-flight and hidden or disposed documents cannot apply an outstanding response', async () => {
    for (const state of ['hidden', 'disposed']) {
        const response = deferred();
        const page = harness({ draft: 'احتفظ بمسودتي', network: () => response.promise });
        const before = page.stream.innerHTML;
        page.update({ messages: [bubble('new-message')], last: 'new-message' });
        const first = page.api.refresh();
        await page.api.refresh();
        assert.equal(page.polls.length, 1);
        if (state === 'hidden') page.setVisibility('hidden');
        else page.api.dispose();
        response.resolve({ ok: true, text: async () => 'incoming-document' });
        await first;
        assert.equal(page.stream.innerHTML, before);
        assert.equal(page.body.value, 'احتفظ بمسودتي');
        assert.equal(page.posts.length, 0);
    }
});

test('a failed refresh or mismatched selected conversation preserves the current thread and recovers on retry', async () => {
    const page = harness({ draft: 'مسودة', network: async () => { throw new Error('offline'); } });
    const before = page.stream.innerHTML;
    await page.api.refresh();
    assert.equal(page.connection.hidden, false);
    assert.equal(page.stream.innerHTML, before);
    page.setNetwork(async () => ({ ok: true, text: async () => 'incoming-document' }));
    page.update({ selected: 'another-family', messages: [bubble('private-other-thread')] });
    await page.api.refresh();
    assert.equal(page.connection.hidden, false);
    assert.equal(page.stream.innerHTML, before);
    assert.equal(page.body.value, 'مسودة');
    page.update({ messages: [bubble('new-message')], last: 'new-message' });
    await page.api.refresh();
    assert.equal(page.connection.hidden, true);
    assert.equal(page.stream.getAttribute('data-last-key'), 'new-message');
});

test('polling refuses a current page outside the configured origin and safely retries after returning', async () => {
    const page = harness();
    page.window.location.href = 'https://other.test/parent/messages?thread=teacher_6';
    await page.api.refresh();
    assert.equal(page.calls.length, 0);
    page.window.location.href = origin + '/parent/messages?thread=teacher_6';
    await page.api.refresh();
    assert.equal(page.polls.length, 1);
    assert.equal(page.polls[0].options.credentials, 'same-origin');
});

test('native message POST keeps the full body and CSRF, blocks double submission, and resumes after history restoration', async () => {
    const draft = 'رسالة\n' + 'أهلا بالعائلة <نص> '.repeat(1000);
    const page = harness({ draft });
    const first = await page.form.emit('submit');
    assert.equal(first.defaultPrevented, false);
    assert.equal(page.button.disabled, true);
    assert.equal(page.body.value, draft);
    assert.equal(page.csrfInput.getAttribute('value'), 'native-csrf');
    assert.equal(page.form.getAttribute('method'), 'post');
    const second = await page.form.emit('submit');
    assert.equal(second.defaultPrevented, true);
    await page.api.refresh();
    assert.equal(page.calls.length, 0, 'native submission must not create an extra fetch or poll');
    await page.window.emit('pagehide');
    assert.equal(page.intervals.size, 0);
    await page.window.emit('pageshow');
    assert.equal(page.button.disabled, false);
    assert.equal(page.intervals.size, 1);
    assert.equal([...page.intervals.values()][0].milliseconds, 12000);
    const restored = await page.form.emit('submit');
    assert.equal(restored.defaultPrevented, false);
});

test('blank composer bodies cannot submit and editing them does not silently clear content', async () => {
    const page = harness({ draft: ' \n\t ' });
    const event = await page.form.emit('submit');
    assert.equal(event.defaultPrevented, true);
    assert.equal(page.button.disabled, false);
    assert.equal(page.feedback.hidden, false);
    assert.equal(page.body.focused, true);
    page.body.height = 54;
    await page.body.emit('input');
    assert.equal(page.body.style.height, '56px', 'the textarea includes its border without a one-pixel scrollbar');
    page.body.value = 'نص صالح';
    page.body.height = 500;
    await page.body.emit('input');
    assert.equal(page.body.value, 'نص صالح');
    assert.equal(page.body.style.height, '150px');
    assert.equal((await page.form.emit('submit')).defaultPrevented, false);
});

test('messenger markup uses deferred modules, escaped message bodies, and a normal unlimited CSRF-protected form', () => {
    const shell = readFileSync(new URL('../../resources/views/shared/messenger.blade.php', import.meta.url), 'utf8');
    const content = readFileSync(new URL('../../resources/views/shared/messenger-content.blade.php', import.meta.url), 'utf8');
    assert.match(shell, /<script\b[^>]*type="module"[^>]*portal-messenger\.js/);
    assert.match(shell, /name="messenger-csrf"/);
    assert.match(content, /<form\b[^>]*method="post"[^>]*data-pm-composer/);
    assert.match(content, /@csrf/);
    assert.match(content, /<p>\{\{ \$message\['body'\] \}\}<\/p>/);
    assert.doesNotMatch(content, /<textarea\b[^>]*maxlength=/);
    assert.match(content, /data-message-key="\{\{ \$message\['key'\] \}\}"/);
});
