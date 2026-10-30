import assert from 'node:assert/strict';
import test from 'node:test';
import {initTeacherReports} from '../../public/js/teacher-reports.js';

const flush = () => new Promise(resolve => setImmediate(resolve));
const settlePreview = async () => { await new Promise(resolve => setTimeout(resolve, 205)); await flush(); };
const deferred = () => { let resolve; const promise = new Promise(yes => { resolve = yes; }); return {promise, resolve}; };

// This intentionally small DOM models the actual editor event hooks. No browser,
// database, push provider, or external network is used by these interaction tests.
class Element {
    constructor(tag = 'div', attrs = {}, children = []) {
        this.tagName = tag.toUpperCase(); this.attributes = {}; this.dataset = {}; this.children = []; this.listeners = new Map();
        this.disabled = false; this.hidden = false; this.checked = false; this.open = false; this._value = ''; this._text = ''; this.className = ''; this.style = {};
        this.classList = {
            contains: name => this.className.split(' ').includes(name),
            add: name => { if (!this.classList.contains(name)) this.className = `${this.className} ${name}`.trim(); },
            remove: name => { this.className = this.className.split(' ').filter(item => item !== name).join(' '); },
            toggle: (name, force) => { const value = force ?? !this.classList.contains(name); this.classList[value ? 'add' : 'remove'](name); return value; },
        };
        Object.entries(attrs).forEach(([name, value]) => this.setAttribute(name, value)); this.append(...children);
    }
    setAttribute(name, value) { this.attributes[name] = String(value); if (name.startsWith('data-')) this.dataset[this.dataKey(name)] = String(value); }
    dataKey(name) { return name.slice(5).replace(/-([a-z])/g, (_, character) => character.toUpperCase()); }
    getAttribute(name) { return name.startsWith('data-') ? this.dataset[this.dataKey(name)] ?? null : this.attributes[name] ?? null; }
    hasAttribute(name) { return name.startsWith('data-') ? Object.hasOwn(this.dataset, this.dataKey(name)) : Object.hasOwn(this.attributes, name); }
    set textContent(value) { this._text = String(value ?? ''); this.replaceChildren(); }
    get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
    set value(value) { this._value = String(value ?? ''); if (this.tagName === 'SELECT') this.options.forEach(option => { option.selected = option.value === this._value; }); }
    get value() { return this.tagName === 'SELECT' ? (this.options.find(option => option.selected) ?? this.options[0])?.value ?? '' : this._value; }
    get options() { return this.children.filter(child => child.tagName === 'OPTION'); }
    get parentElement() { return this.parentNode; }
    get scrollHeight() { return this.contentHeight ?? 66; }
    get isConnected() { return this.tagName === 'DOCUMENT' || !!this.parentNode?.isConnected; }
    append(...nodes) { for (const node of nodes) { node.parentNode = this; this.children.push(node); } }
    replaceChildren(...nodes) { this.children.forEach(node => { node.parentNode = null; }); this.children = []; this.append(...nodes); }
    matches(selector) {
        return selector.split(',').some(part => {
            const checked = part.trim().endsWith(':checked'); const simple = part.trim().replace(/:checked$/, '');
            const match = /^\[([^=\]]+)(?:="([^"]*)")?\]$/.exec(simple);
            const matches = match ? this.hasAttribute(match[1]) && (match[2] === undefined || this.getAttribute(match[1]) === match[2]) : this.tagName === simple.toUpperCase();
            return matches && (!checked || this.checked);
        });
    }
    querySelectorAll(selector) { return this.children.flatMap(child => [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)]); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
    closest(selector) { return this.matches(selector) ? this : this.parentNode?.closest(selector) ?? null; }
    contains(node) { return this === node || this.children.some(child => child.contains(node)); }
    addEventListener(name, callback) { if (!this.listeners.has(name)) this.listeners.set(name, []); this.listeners.get(name).push(callback); }
    removeEventListener(name, callback) { this.listeners.set(name, (this.listeners.get(name) ?? []).filter(item => item !== callback)); }
    emit(name, target = this) { const event = {target, preventDefault() { this.defaultPrevented = true; }}; for (const callback of this.listeners.get(name) ?? []) callback(event); return event; }
    checkValidity() { return this.disabled || !this.customValidity; }
    reportValidity() { this.reportedInvalid = !this.checkValidity(); return this.checkValidity(); }
    setCustomValidity(value) { this.customValidity = value; }
    focus() { this.focused = true; }
    showModal() { this.open = true; }
    close() { this.open = false; }
    scrollIntoView() {}
}
const element = (tag, hook, children = []) => new Element(tag, hook ? {[hook]: ''} : {}, children);
const input = (hook, value = '', type = 'text') => { const node = element('input', hook); node.value = value; node.type = type; return node; };
const button = hook => element('button', hook);
const response = (data, status = 200) => ({ok: status >= 200 && status < 300, status, redirected: false, json: async () => data});

function fixture({saved = true} = {}) {
    const students = [{id: 1, name: 'محمد أَحمد', parents_count: 1}, {id: 2, name: 'سارة علي', parents_count: 0}];
    const entries = students.map(student => ({key: `${student.id}:3`, student_id: student.id, student_name: student.name, subject_id: 3, subject_name: 'رياضيات', automatic_score: null, automatic_rating: null, score_mode: 'auto', score: null, rating_mode: 'auto', rating: null, behaviour: null, activity: '', notes: '', evidence: []}));
    const builder = {ready: true, report: {id: saved ? 4 : null, title: 'تقرير الصف', revision: saved ? 2 : 0, updated_at: null}, classroom: {id: 17, label: 'الصف الرابع'}, catalog: {subjects: [{id: 3, name: 'رياضيات'}], students, sources: []}, config: {report_type: 'overall', from: null, to: null, subject_ids: [3], categories: null, source_keys: null, thresholds: {excellent: 85, very_good: 75, good: 65}, aggregation: 'mean'}, entries, selected_sources: [], warnings: [], counts: {students: 2, subjects: 1, assessments: 0, unmapped: 0}};
    const data = element('script', 'data-trp-data'); data.textContent = JSON.stringify(builder);
    const title = input('data-trp-config-field', builder.report.title); title.dataset.trpConfigField = 'title';
    const from = input('data-trp-config-field'); from.dataset.trpConfigField = 'from';
    const to = input('data-trp-config-field'); to.dataset.trpConfigField = 'to';
    const aggregation = element('select', 'data-trp-config-field'); aggregation.dataset.trpConfigField = 'aggregation'; const mean = element('option'); mean.value = 'mean'; aggregation.append(mean);
    const subject = input('data-trp-subject', 3, 'checkbox'); subject.checked = true;
    const thresholds = Object.entries(builder.config.thresholds).map(([key, value]) => { const node = input('data-trp-threshold', value, 'number'); node.dataset.trpThreshold = key; return node; });
    const form = element('form', 'data-trp-config', [title, from, to, aggregation, subject, ...thresholds]);
    const settings = element('details', 'data-trp-settings', [form]);
    const preview = element('select', 'data-trp-student-preview');
    const card = element('article', 'data-trp-paper', ['title', 'student', 'classroom', 'period', 'rows', 'extras'].map(name => element(name === 'rows' ? 'tbody' : 'div', `data-trp-paper-${name}`)));
    const audience = ['student', 'parent', 'admin'].map(value => input('data-trp-audience', value, 'checkbox'));
    const all = input('data-trp-send-scope', 'all', 'radio'); all.checked = true;
    const selected = input('data-trp-send-scope', 'selected', 'radio');
    const confirm = button('data-trp-send-confirm'); confirm.disabled = true;
    const search = input('data-trp-send-search');
    const list = element('div', 'data-trp-send-student-list');
    const selectedArea = element('section', 'data-trp-send-students', [search, list, element('span', 'data-trp-send-selected-count'), element('p', 'data-trp-send-search-empty')]); selectedArea.hidden = true;
    const summary = element('div', 'data-trp-send-summary'); const error = element('div', 'data-trp-send-error'); error.hidden = true;
    const dialog = element('dialog', 'data-trp-send-dialog', [...audience, all, selected, selectedArea, summary, element('p', 'data-trp-send-unlinked'), error, confirm, button('data-trp-send-close'), button('data-trp-send-select-all'), button('data-trp-send-clear')]);
    const open = button('data-trp-send-open');
    const token = new Element('input', {name: '_token'}); token.value = 'test-csrf';
    const root = element('section', 'data-teacher-reports', [data, token, settings, element('div', 'data-trp-error'), element('div', 'data-trp-save-state'), input('data-trp-search'), element('div', 'data-trp-source-count'), element('div', 'data-trp-sources'), element('div', 'data-trp-warnings'), element('tbody', 'data-trp-entries'), element('div', 'data-trp-no-students'), element('div', 'data-trp-search-empty'), element('span', 'data-trp-context-state'), element('div', 'data-trp-card-empty'), preview, card, open, dialog]);
    Object.assign(root.dataset, {previewUrl: '/teacher/reports/preview', sourcesUrl: '/teacher/reports/sources', storeUrl: '/teacher/reports', updateUrl: saved ? '/teacher/reports/4' : '', printUrl: saved ? '/teacher/reports/4/print' : '', recipientsUrl: saved ? '/teacher/reports/4/recipients' : '', sendPreviewUrl: saved ? '/teacher/reports/4/send-preview' : '', sendUrl: saved ? '/teacher/reports/4/send' : ''});
    const document = new Element('document', {}, [root]); document.createElement = tag => new Element(tag); document.createTextNode = value => { const node = new Element('#text'); node.textContent = value; return node; };
    const window = new Element('window'); window.location = {href: 'https://school.test/teacher/reports/4', origin: 'https://school.test'};
    const history = []; window.history = {replaceState: (...args) => history.push(args)}; let nextUuid = 0; window.crypto = {randomUUID: () => `7f20e21c-9518-43dd-815f-${String(++nextUuid).padStart(12, '0')}`};
    const calls = []; let handle;
    const fetchFn = async (url, options) => {
        const call = {path: new URL(url).pathname, method: options.method, body: options.body ? JSON.parse(options.body) : null, headers: options.headers}; calls.push(call);
        if (handle) { const result = await handle(call); if (result) return result; }
        if (call.path.endsWith('/recipients')) return response({students, admins_count: 2});
        if (call.path.endsWith('/send-preview')) return response({counts: {student: call.body.audiences.includes('student') ? call.body.student_ids.length : 0, parent: call.body.audiences.includes('parent') && call.body.student_ids.includes(1) ? 1 : 0, admin: call.body.audiences.includes('admin') ? 2 : 0, total: (call.body.audiences.includes('student') ? call.body.student_ids.length : 0) + (call.body.audiences.includes('parent') && call.body.student_ids.includes(1) ? 1 : 0) + (call.body.audiences.includes('admin') ? 2 : 0)}, unlinked_students: call.body.student_ids.includes(2) ? [students[1]] : []});
        if (call.path.endsWith('/send')) return response({message: 'تم إرسال التقرير.', counts: {total: 1}});
        if (call.path === '/teacher/reports' || call.method === 'PUT') { const savedBuilder = structuredClone(builder); savedBuilder.report = {...savedBuilder.report, id: 4, revision: 3}; return response({builder: savedBuilder, redirect_url: '/teacher/reports/4'}, call.method === 'PUT' ? 200 : 201); }
        throw new Error(`Unexpected test request: ${call.path}`);
    };
    const controller = initTeacherReports(document, {window, fetchFn});
    const click = async node => { root.emit('click', node); await flush(); };
    const change = node => root.emit('input', node);
    const choose = values => { audience.forEach(node => { node.checked = values.includes(node.value); }); change(audience[0]); };
    return {root, dialog, open, confirm, summary, error, audience, all, selected, search, list, title, settings, controller, calls, history, click, change, choose, handle: callback => { handle = callback; }};
}

test('dialog opens without any audience selected and previews every recipient combination accurately', async () => {
    const app = fixture();
    try {
        await app.click(app.open);
        assert.equal(app.dialog.open, true); assert.equal(app.confirm.disabled, true); assert.ok(app.audience.every(node => !node.checked));
        for (const audiences of [['student'], ['parent'], ['admin'], ['student', 'parent'], ['student', 'admin'], ['parent', 'admin'], ['student', 'parent', 'admin']]) {
            app.choose(audiences); await settlePreview();
            const call = app.calls.findLast(item => item.path.endsWith('/send-preview'));
            assert.deepEqual(call.body.audiences, [...audiences].sort()); assert.deepEqual(call.body.student_ids, [1, 2]);
            assert.equal(app.summary.textContent, `الطلاب ${audiences.includes('student') ? 2 : 0}، أولياء الأمور ${audiences.includes('parent') ? 1 : 0}، الإدارة ${audiences.includes('admin') ? 2 : 0}`);
            assert.equal(app.confirm.disabled, false);
        }
    } finally { app.controller.dispose(); }
});

test('selected students are explicit, Arabic search reorders tokens, and hidden results do not alter selections', async () => {
    const app = fixture();
    try {
        await app.click(app.open); app.selected.checked = true; app.all.checked = false; app.change(app.selected);
        assert.ok(app.list.children.every(row => !row.children[0].checked));
        app.search.value = 'احمد محمد'; app.change(app.search);
        assert.equal(app.list.children[0].hidden, false); assert.equal(app.list.children[1].hidden, true);
        const selected = app.list.children[0].children[0]; selected.checked = true; app.change(selected);
        app.choose(['student', 'parent']); await settlePreview();
        assert.deepEqual(app.calls.findLast(item => item.path.endsWith('/send-preview')).body.student_ids, [1]);
        assert.equal(app.summary.textContent, 'الطلاب 1، أولياء الأمور 1، الإدارة 0');
        app.search.value = 'سارة'; app.change(app.search); assert.equal(selected.checked, true);
        assert.equal(app.confirm.disabled, false);
    } finally { app.controller.dispose(); }
});

test('a late recipient preview cannot overwrite a newer selection or enable stale sending', async () => {
    const app = fixture(), first = deferred(), second = deferred(); let previews = 0;
    app.handle(call => call.path.endsWith('/send-preview') ? (++previews === 1 ? first.promise : second.promise) : undefined);
    try {
        await app.click(app.open); app.choose(['student']); await settlePreview();
        app.choose(['parent']); assert.equal(app.confirm.disabled, true); await settlePreview();
        second.resolve(response({counts: {student: 0, parent: 1, admin: 0, total: 1}, unlinked_students: []})); await flush();
        first.resolve(response({counts: {student: 999, parent: 0, admin: 0, total: 999}, unlinked_students: []})); await flush();
        assert.equal(app.summary.textContent, 'الطلاب 0، أولياء الأمور 1، الإدارة 0'); assert.equal(app.confirm.disabled, false);
        await app.click(app.confirm); assert.deepEqual(app.calls.findLast(item => item.path.endsWith('/send')).body.audiences, ['parent']);
    } finally { app.controller.dispose(); }
});

test('failed sending preserves its UUID for retry and successful sending stays visibly disabled', async () => {
    const app = fixture(); let sends = 0;
    app.handle(call => call.path.endsWith('/send') && ++sends === 1 ? response({message: 'انقطع الاتصال.'}, 503) : undefined);
    try {
        await app.click(app.open); app.choose(['parent']); await settlePreview(); await app.click(app.confirm);
        assert.equal(app.error.hidden, false); assert.equal(app.confirm.disabled, false);
        const first = app.calls.findLast(item => item.path.endsWith('/send')).body.request_id;
        await app.click(app.confirm);
        const second = app.calls.findLast(item => item.path.endsWith('/send')).body.request_id;
        assert.equal(second, first); assert.match(first, /^[0-9a-f-]{36}$/); assert.equal(app.confirm.disabled, true);
        assert.equal(app.summary.textContent, 'تم إرسال التقرير.');
        await app.click(app.confirm); assert.equal(sends, 2);
        const close = app.dialog.querySelector('[data-trp-send-close]'); await app.click(close); await app.click(app.open); await settlePreview();
        assert.equal(app.confirm.disabled, true, 'reopening the same sent selection cannot enable a second dispatch');
    } finally { app.controller.dispose(); }
});

test('new reports are saved before loading recipients and sending requires an explicit audience', async () => {
    const app = fixture({saved: false});
    try {
        await app.click(app.open);
        assert.deepEqual(app.calls.map(call => [call.method, call.path]), [['POST', '/teacher/reports'], ['GET', '/teacher/reports/4/recipients']]);
        assert.equal(app.dialog.open, true); assert.equal(app.confirm.disabled, true); assert.equal(app.history[0][2], '/teacher/reports/4');
        assert.equal(app.calls[0].headers['X-CSRF-TOKEN'], 'test-csrf');
        await app.click(app.confirm); assert.equal(app.calls.filter(call => call.path.endsWith('/send')).length, 0);
    } finally { app.controller.dispose(); }
});

test('invalid fields in collapsed settings open before reporting validation and no recipient request is made', async () => {
    const app = fixture({saved: false});
    try {
        app.title.setCustomValidity('العنوان مطلوب'); assert.equal(app.settings.open, false);
        await app.click(app.open);
        assert.equal(app.settings.open, true); assert.equal(app.title.reportedInvalid, true); assert.equal(app.calls.length, 0);
    } finally { app.controller.dispose(); }
});

test('activity and notes grow in place without an inner scrollbar until the height cap', async () => {
    const app = fixture({saved: false});
    try {
        await app.click(app.open);
        const area = app.root.querySelector('[data-trp-entry-field="activity"]');
        assert.equal(area.style.height, '68px'); assert.equal(area.style.overflowY, 'hidden');
        const originalParent = area.parentNode;
        area.value = 'مشاركة جيدة في الأنشطة الصفية'; area.contentHeight = 116; area.focus(); app.change(area);
        assert.equal(area.style.height, '118px'); assert.equal(area.style.overflowY, 'hidden');
        assert.equal(area.parentNode, originalParent); assert.equal(area.focused, true);
        assert.equal(app.controller.getState().entries[0].activity, area.value);
        area.contentHeight = 240; app.change(area);
        assert.equal(area.style.height, '160px'); assert.equal(area.style.overflowY, 'auto');
    } finally { app.controller.dispose(); }
});
