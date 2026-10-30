import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { bootMessaging, initialSelection, nameMatches, normalizeArabic, resolveRecipients, serializeSelection } from '../../public/js/teacher-messaging.js';

const blade = readFileSync(new URL('../../resources/views/teacher/notifications/create.blade.php', import.meta.url), 'utf8');
const reviewBlade = readFileSync(new URL('../../resources/views/teacher/notifications/preview.blade.php', import.meta.url), 'utf8');
const script = readFileSync(new URL('../../public/js/teacher-messaging.js', import.meta.url), 'utf8');
const fixture = JSON.parse(readFileSync(new URL('../Fixtures/teacher-messaging.json', import.meta.url), 'utf8'));
const ids = people => people.map(person => person.id).sort((a, b) => a - b);
const state = overrides => ({ audience: 'families', mode: 'grade', gradeId: 'first', branchIds: [], personIds: [], ...overrides });
const resolve = (overrides = {}, data = fixture) => resolveRecipients(data, state(overrides));
const childIds = parent => ids(parent.children);

test('selected siblings count their parent once and include only the selected children as context', () => {
    const siblings = resolve({ mode: 'people', personIds: [1, 3] });
    assert.deepEqual(ids(siblings.students), [1, 3]);
    assert.deepEqual(ids(siblings.parents), [101]);
    assert.deepEqual(childIds(siblings.parents[0]), [1, 3]);
    assert.equal(siblings.total, 3);

    const oneChild = resolve({ mode: 'people', personIds: [1] });
    assert.deepEqual(ids(oneChild.students), [1]);
    assert.deepEqual(ids(oneChild.parents), [101]);
    assert.deepEqual(childIds(oneChild.parents[0]), [1]);
    assert.equal(oneChild.total, 2);
});

test('selecting unrelated students copies only their own linked parents', () => {
    const result = resolve({ mode: 'people', personIds: [1, 5] });
    assert.deepEqual(ids(result.students), [1, 5]);
    assert.deepEqual(ids(result.parents), [101, 104]);
    assert.deepEqual(result.parents.map(parent => [parent.id, childIds(parent)]), [[101, [1]], [104, [5]]]);
    assert.equal(result.total, 4);
});

test('parent-only individual selection does not notify a co-guardian or another family', () => {
    const data = structuredClone(fixture);
    data.parents.push({ id: 109, name: 'ولي أمر إضافي لأحمد' });
    data.students.find(student => student.id === 1).parentIds.push(109);

    const result = resolve({ audience: 'parents', mode: 'people', personIds: [101] }, data);
    assert.deepEqual(result.students, []);
    assert.deepEqual(ids(result.parents), [101]);
    assert.deepEqual(childIds(result.parents[0]), [1, 3]);
    assert.equal(result.total, 1);
});

test('parent-only multi-selection retains each selected family and deduplicates repeated choices', () => {
    const result = resolve({ audience: 'parents', mode: 'people', personIds: [101, 104, 101, 999999] });
    assert.deepEqual(result.students, []);
    assert.deepEqual(ids(result.parents), [101, 104]);
    assert.deepEqual(result.parents.map(parent => [parent.id, childIds(parent)]), [[101, [1, 3]], [104, [5]]]);
    assert.equal(result.total, 2);
});

test('grade selection uses the exact existing class IDs rather than similar class names', () => {
    const data = structuredClone(fixture);
    data.classes.push({ id: 999, name: 'الصف الأول A', branch: 'A' });
    data.students.push({ id: 999, name: 'طالب خارج الصف المحدد', classId: 999, parentIds: [] });

    const first = resolve({}, data);
    assert.deepEqual(ids(first.students), [1, 2, 3, 4, 5, 6]);
    assert.deepEqual(ids(first.parents), [101, 102, 103, 104, 105]);
    assert.equal(first.total, 11);

    const sixth = resolve({ gradeId: 'sixth' }, data);
    assert.deepEqual(ids(sixth.students), [7, 8, 9]);
    assert.deepEqual(ids(sixth.parents), [106, 107, 108]);
    assert.equal(sixth.total, 6);
    assert.equal(resolve({ gradeId: 'missing' }, data).total, 0);
});

test('choosing branches A and C excludes B students and does not disclose their sibling context', () => {
    const result = resolve({ mode: 'branches', branchIds: [1, 17] });
    assert.deepEqual(ids(result.students), [1, 2, 5, 6]);
    assert.deepEqual(ids(result.parents), [101, 102, 104, 105]);
    assert.deepEqual(childIds(result.parents.find(parent => parent.id === 101)), [1]);
    assert.equal(result.total, 8);

    const parentsOnly = resolve({ audience: 'parents', mode: 'branches', branchIds: [1, 17] });
    assert.deepEqual(parentsOnly.students, []);
    assert.deepEqual(ids(parentsOnly.parents), [101, 102, 104, 105]);
    assert.equal(parentsOnly.total, 4);
    assert.equal(resolve({ mode: 'branches', branchIds: [] }).total, 0);
});

test('student-only policy retains unlinked students without inventing parent recipients', () => {
    const data = structuredClone(fixture);
    data.students.push({ id: 11, name: 'طالب بارتباط قديم', classId: 14, parentIds: [999] });
    const result = resolve({ gradeId: 'secondary' }, data);
    assert.deepEqual(ids(result.students), [10, 11]);
    assert.deepEqual(result.parents, []);
    assert.deepEqual(ids(result.unlinked), [10, 11]);
    assert.equal(result.total, 2);

    const parentsOnly = resolve({ audience: 'parents', gradeId: 'secondary' }, data);
    assert.equal(parentsOnly.total, 0);
    assert.deepEqual(parentsOnly.students, []);
});

test('exclude policy omits unlinked students but retains their count for the explanation', () => {
    const data = structuredClone(fixture);
    data.unlinkedPolicy = 'exclude';
    const result = resolve({ mode: 'all' }, data);
    assert.deepEqual(ids(result.students), [1, 2, 3, 4, 5, 6, 7, 8, 9]);
    assert.deepEqual(ids(result.parents), [101, 102, 103, 104, 105, 106, 107, 108]);
    assert.deepEqual(ids(result.unlinked), [10]);
    assert.equal(result.total, 17);
    assert.equal(resolve({ gradeId: 'secondary' }, data).total, 0);
});

test('all and empty individual selections are predictable without mutating fixture or state', () => {
    const data = structuredClone(fixture);
    const selection = state({ mode: 'all' });
    const before = JSON.stringify({ data, selection });
    const all = resolveRecipients(data, selection);
    assert.equal(all.students.length, 10);
    assert.equal(all.parents.length, 8);
    assert.equal(all.total, 18);
    assert.equal(JSON.stringify({ data, selection }), before);
    assert.equal(resolve({ mode: 'people', personIds: [] }).total, 0);
    assert.equal(resolve({ audience: 'parents', mode: 'people', personIds: [] }).total, 0);
});

test('Arabic normalization handles diacritics, tatweel, hamza, final letters and repeated whitespace', () => {
    assert.equal(normalizeArabic('  أَحْـمَد\t إِبْرَاهِيم\nآدم  '), 'احمد ابراهيم ادم');
    assert.equal(normalizeArabic('مُصْطَفَى فَاطِمَة'), 'مصطفي فاطمه');
    assert.equal(normalizeArabic('ٱلْأَوَّل'), 'الاول');
    assert.equal(normalizeArabic(' Grade\tA '), 'grade a');
    assert.equal(normalizeArabic(null), '');
});

test('Arabic search accepts partial, reordered tokens and normalized spacing while requiring every token', () => {
    const name = 'أَحْـمَد   إِبْرَاهِيم التجريبي';
    assert.equal(nameMatches('ابراهيم احمد', name), true);
    assert.equal(nameMatches('  أَحمد\n\tتجري  ', name), true);
    assert.equal(nameMatches('احمد يوسف', name), false);
    assert.equal(nameMatches('مصطفي فاطمه', 'مُصْطَفَى فَاطِمَة'), true);
    assert.equal(nameMatches('عبد الله', 'عبدالله التجريبي'), true);
    assert.equal(nameMatches('  ', name), true);
});

test('the actual composer writes the message first and keeps search outside the switchable recipient panels', () => {
    const textarea = blade.indexOf('<textarea id="tm-message"');
    const recipients = blade.indexOf('<section class="tm-recipient-card"');
    const search = blade.indexOf('id="tm-search"');
    assert.ok(textarea >= 0 && textarea < recipients);
    assert.ok(search > recipients && search < blade.indexOf('id="tm-groups-panel"'));
    assert.ok(search < blade.indexOf('id="tm-people-panel"'));
    assert.match(blade, /<div class="tm-search-field">/);
    assert.match(blade, /<input type="search" id="tm-search"[^>]*>/);
    assert.doesNotMatch(blade.match(/<input type="search" id="tm-search"[^>]*>/)[0], /\bhidden\b/);
    assert.doesNotMatch(blade, /<dialog\b|tm-chat-canvas|tm-draft-bubble|<select\b[^>]*id="tm-grade"/);
});

test('the actual form posts to server review with CSRF and fail-closed empty selection defaults', () => {
    assert.match(blade, /<form id="tm-compose" method="post" action="\{\{ route\('teacher\.notifications\.preview'\) \}\}">\s*@csrf/);
    const defaults = blade.match(/<div id="tm-selection-fields"[^>]*>([\s\S]*?)<\/div>/)[1];
    assert.match(defaults, /name="audience" value="families"/);
    assert.match(defaults, /name="selection_mode" value="branches"/);
    assert.doesNotMatch(defaults, /name="(?:classroom_ids|student_ids|guardian_ids)(?:\[\])?"/);
    assert.doesNotMatch(script, /\b(?:fetch|XMLHttpRequest|WebSocket|EventSource)\b|\bsendBeacon\s*\(|\.submit\s*\(|\b(?:axios|ky)\s*\./);
});

test('review has one confirmation button and a hidden accepted value that survives disabling the submitter', () => {
    const form = reviewBlade.match(/<form\b[^>]*data-teacher-notification-submit[^>]*>([\s\S]*?)<\/form>/)[1];
    assert.match(form, /@csrf/);
    assert.match(form, /<input\b(?=[^>]*type="hidden")(?=[^>]*name="confirmed")(?=[^>]*value="1")[^>]*>/);
    assert.doesNotMatch(form, /<input\b[^>]*type="checkbox"/);
    const buttons = form.match(/<button\b[^>]*type="submit"[^>]*>/g) ?? [];
    assert.equal(buttons.length, 1);
    assert.match(buttons[0], /class="[^"]*\btm-send\b/);
    assert.doesNotMatch(buttons[0], /\bname="confirmed"/);
});

function previewDocument(data = fixture) {
    const elements = new Map();
    const queriedSelectors = [];
    const make = (tag = 'div') => ({
        tagName: tag, value: '', textContent: '', hidden: false, disabled: false, checked: false, indeterminate: false,
        dataset: {}, children: [], attributes: {}, listeners: {},
        append(...children) { this.children.push(...children); },
        replaceChildren(...children) { this.children = children; },
        setAttribute(name, value) { this.attributes[name] = value; },
        getAttribute(name) { return this.attributes[name]; },
        addEventListener(name, listener) { this.listeners[name] = listener; },
        focus() { this.focused = true; },
    });
    for (const [, tag, attributes, id] of blade.matchAll(/<([a-z]+)\b([^>]*\bid="([^"]+)"[^>]*)>/g)) {
        elements.set('#' + id, Object.assign(make(tag), { hidden: /\bhidden\b/.test(attributes) }));
    }
    const buttons = { '[data-audience]': [], '[data-mode]': [] };
    for (const [, , kind, value] of blade.matchAll(/<button\b([^>]*\bdata-(audience|mode)="([^"]+)"[^>]*)>/g)) {
        const button = make('button');
        button.dataset[kind] = value;
        buttons['[data-' + kind + ']'].push(button);
    }
    elements.get('#tm-data').textContent = JSON.stringify(data);
    const csrf = Object.assign(make(), { type: 'hidden', name: '_token', value: 'original-csrf-token' });
    const fields = elements.get('#tm-selection-fields');
    fields.append(Object.assign(make('input'), { type: 'hidden', name: 'audience', value: 'families' }));
    fields.append(Object.assign(make('input'), { type: 'hidden', name: 'selection_mode', value: 'branches' }));
    const form = elements.get('#tm-compose');
    form.setAttribute('method', 'post');
    form.setAttribute('action', '/teacher/notifications/preview');
    form.append(csrf, fields);
    const doc = {
        querySelector(selector) { queriedSelectors.push(selector); return elements.get(selector); },
        querySelectorAll(selector) { queriedSelectors.push(selector); return buttons[selector] ?? []; },
        createElement: make,
    };
    bootMessaging(doc);
    return { doc, elements, csrf, queriedSelectors, buttons };
}

test('data without explicit live mode cannot submit even with a valid message and selection', () => {
    const { elements } = previewDocument({ ...fixture, initialState: state({ mode: 'branches', branchIds: [1] }) });
    const message = elements.get('#tm-message');
    message.value = 'رسالة تجريبية للمراجعة فقط';
    message.listeners.input();
    assert.equal(elements.get('#tm-preview').disabled, false);
    let prevented = false;
    elements.get('#tm-compose').listeners.submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.deepEqual(elements.get('#tm-selection-fields').children.map(input => [input.name, input.value]), [
        ['audience', 'families'], ['selection_mode', 'branches'],
    ]);
    assert.equal(message.value, 'رسالة تجريبية للمراجعة فقط');
});

test('group serialization sends only the exact existing classroom IDs for grade, branches and all modes', () => {
    const cases = [
        [state({ gradeId: 'first' }), { audience: 'families', selection_mode: 'grade', classroom_ids: [1, 16, 17] }],
        [state({ audience: 'parents', mode: 'branches', branchIds: [1, 17] }), { audience: 'parents', selection_mode: 'branches', classroom_ids: [1, 17] }],
        [state({ mode: 'all' }), { audience: 'families', selection_mode: 'all', classroom_ids: fixture.classes.map(room => room.id) }],
    ];
    for (const [selection, expected] of cases) {
        assert.deepEqual(serializeSelection(fixture, selection), expected);
    }
    assert.deepEqual(serializeSelection(fixture, state({ gradeId: 'missing' })), {
        audience: 'families', selection_mode: 'grade', classroom_ids: [],
    });
});

test('person serialization sends selected user IDs only and leaves linked-parent resolution to the server', () => {
    assert.deepEqual(serializeSelection(fixture, state({ mode: 'people', personIds: [1, 3] })), {
        audience: 'families', selection_mode: 'people', student_ids: [1, 3],
    });
    assert.deepEqual(serializeSelection(fixture, state({ audience: 'parents', mode: 'people', personIds: [101, 104] })), {
        audience: 'parents', selection_mode: 'people', guardian_ids: [101, 104],
    });
    const selection = state({ mode: 'branches', branchIds: [1, 17] });
    const serialized = serializeSelection(fixture, selection);
    serialized.classroom_ids.push(999);
    assert.deepEqual(selection.branchIds, [1, 17]);
    assert.deepEqual(fixture.grades.find(grade => grade.id === 'first').classIds, [1, 16, 17]);
});

test('live form allows its normal POST with one precise selection array and preserves the existing CSRF field', () => {
    const cases = [
        [state({ mode: 'branches', branchIds: [1, 16, 17] }), [['audience', 'families'], ['selection_mode', 'branches'], ['classroom_ids[]', '1'], ['classroom_ids[]', '16'], ['classroom_ids[]', '17']]],
        [state({ mode: 'branches', branchIds: [1, 17] }), [['audience', 'families'], ['selection_mode', 'branches'], ['classroom_ids[]', '1'], ['classroom_ids[]', '17']]],
        [state({ mode: 'people', personIds: [1, 3] }), [['audience', 'families'], ['selection_mode', 'people'], ['student_ids[]', '1'], ['student_ids[]', '3']]],
        [state({ audience: 'parents', mode: 'people', personIds: [101, 104] }), [['audience', 'parents'], ['selection_mode', 'people'], ['guardian_ids[]', '101'], ['guardian_ids[]', '104']]],
    ];
    for (const [initialState, expected] of cases) {
        const data = { ...structuredClone(fixture), previewOnly: false, teacherName: 'الأستاذ سالم', limit: 2000, initialState };
        const { elements, csrf, queriedSelectors } = previewDocument(data);
        const message = elements.get('#tm-message');
        message.value = 'رسالة للمراجعة على الخادم';
        message.listeners.input();
        const form = elements.get('#tm-compose');
        let prevented = false;
        form.listeners.submit({ preventDefault() { prevented = true; } });

        assert.equal(prevented, false);
        assert.deepEqual(elements.get('#tm-selection-fields').children.map(input => [input.name, input.value]), expected);
        assert.ok(elements.get('#tm-selection-fields').children.every(input => input.type === 'hidden'));
        assert.equal(form.getAttribute('action'), '/teacher/notifications/preview');
        assert.equal(form.getAttribute('method'), 'post');
        assert.strictEqual(form.children[0], csrf);
        assert.equal(csrf.name, '_token');
        assert.equal(csrf.value, 'original-csrf-token');
        assert.equal(queriedSelectors.some(selector => selector.includes('_token')), false);
        assert.equal(elements.get('#tm-title').value, 'رسالة من الأستاذ سالم');
        assert.equal(elements.get('#tm-preview').disabled, true);
        assert.equal(elements.has('#tm-review'), false);
    }
});

test('live form blocks empty selections, blank messages and recipient overflow before preparing a POST', () => {
    const cases = [
        { initialState: state({ mode: 'people', personIds: [] }), message: 'رسالة' },
        { initialState: state({ mode: 'branches', branchIds: [1, 16, 17] }), message: '   ' },
        { initialState: state({ mode: 'branches', branchIds: [1, 16, 17] }), message: 'رسالة', limit: 10 },
    ];
    for (const options of cases) {
        const data = { ...structuredClone(fixture), previewOnly: false, ...options };
        const { elements, csrf } = previewDocument(data);
        elements.get('#tm-message').value = options.message;
        elements.get('#tm-message').listeners.input();
        let prevented = false;
        elements.get('#tm-compose').listeners.submit({ preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
        assert.equal(elements.get('#tm-preview').disabled, true);
        assert.deepEqual(elements.get('#tm-selection-fields').children.map(input => input.name), ['audience', 'selection_mode']);
        assert.equal(csrf.value, 'original-csrf-token');
    }
});

test('initial selection is empty by default and never auto-selects the first grade', () => {
    assert.deepEqual(initialSelection(fixture), { audience: 'families', mode: 'branches', branchIds: [], personIds: [] });
    assert.deepEqual(initialSelection({ ...fixture, initialState: { mode: 'grade', gradeId: 'first', branchIds: [] } }).branchIds, []);
    const { elements } = previewDocument({ ...fixture, previewOnly: false });
    assert.equal(elements.get('#tm-summary').textContent, 'لم تختر مستلمين بعد');
    assert.equal(elements.get('#tm-preview').disabled, true);
    assert.equal(elements.get('#tm-search').hidden, false);
    assert.ok(descendants(elements.get('#tm-grade-list')).filter(element => element.type === 'checkbox').every(input => !input.checked));
});

test('restored selections keep exact multi-grade IDs, filter stale IDs and never broaden legacy all or grade modes', () => {
    for (const mode of ['branches', 'grade', 'all']) {
        const initialState = state({ mode, branchIds: [1, 26, 1, 999], personIds: [] });
        const data = { ...fixture, initialState };
        const before = JSON.stringify(data);
        const selected = initialSelection(data);
        assert.deepEqual(selected, { audience: 'families', mode: 'branches', branchIds: [1, 26], personIds: [] });
        assert.deepEqual(serializeSelection(data, selected), { audience: 'families', selection_mode: 'branches', classroom_ids: [1, 26] });
        assert.deepEqual(ids(resolveRecipients(data, selected).students), [1, 2, 8]);
        assert.equal(JSON.stringify(data), before);
    }
    assert.deepEqual(initialSelection({ ...fixture, initialState: { mode: 'all', branchIds: [] } }).branchIds, []);
    assert.deepEqual(initialSelection({ ...fixture, initialState: { audience: 'parents', mode: 'people', personIds: [101, 101, 1, 999] } }), {
        audience: 'parents', mode: 'people', branchIds: [], personIds: [101],
    });
    assert.deepEqual(initialSelection({ ...fixture, initialState: { audience: 'families', mode: 'people', personIds: [1, 3, 101, 999] } }).personIds, [1, 3]);
});

function descendants(element) {
    return [element, ...element.children.flatMap(descendants)];
}

function gradeCheckbox(context, accessibleName) {
    const input = descendants(context.elements.get('#tm-grade-list')).find(element =>
        element.type === 'checkbox' && element.getAttribute('aria-label') === accessibleName);
    assert.ok(input, 'Expected checkbox: ' + accessibleName);
    return input;
}

function toggle(input, checked = true) {
    input.checked = checked;
    input.listeners.change();
}

function clickMode(context, mode) {
    context.buttons['[data-mode]'].find(button => button.dataset.mode === mode).listeners.click();
}

function searchPeople(context, value) {
    const search = context.elements.get('#tm-search');
    search.value = value;
    search.listeners.input();
}

function choosePerson(context, id) {
    const input = descendants(context.elements.get('#tm-people')).find(element => element.type === 'checkbox' && element.value === String(id));
    assert.ok(input, 'Expected visible person: ' + id);
    toggle(input);
}

function submitSelection(context) {
    const message = context.elements.get('#tm-message');
    message.value = 'رسالة لاختبار المستلمين';
    message.listeners.input();
    let prevented = false;
    context.elements.get('#tm-compose').listeners.submit({ preventDefault() { prevented = true; } });
    return {
        prevented,
        fields: context.elements.get('#tm-selection-fields').children.map(input => [input.name, input.value]),
    };
}

test('grade checkboxes combine multiple whole grades and retain exact subsets across both grades', () => {
    const context = previewDocument({ ...fixture, previewOnly: false });
    toggle(gradeCheckbox(context, 'الصف الأول'));
    toggle(gradeCheckbox(context, 'الصف السادس'));
    assert.equal(gradeCheckbox(context, 'الصف الأول').checked, true);
    assert.equal(gradeCheckbox(context, 'الصف السادس').checked, true);
    let result = submitSelection(context);
    assert.equal(result.prevented, false);
    assert.deepEqual(result.fields.filter(([name]) => name === 'classroom_ids[]').map(([, value]) => Number(value)), [1, 16, 17, 6, 26, 27]);

    toggle(gradeCheckbox(context, 'الصف الأول — الشعبة B'), false);
    toggle(gradeCheckbox(context, 'الصف السادس — الشعبة A'), false);
    assert.equal(gradeCheckbox(context, 'الصف الأول').checked, false);
    assert.equal(gradeCheckbox(context, 'الصف الأول').indeterminate, true);
    assert.equal(gradeCheckbox(context, 'الصف السادس').indeterminate, true);
    result = submitSelection(context);
    assert.deepEqual(result.fields, [
        ['audience', 'families'], ['selection_mode', 'branches'],
        ['classroom_ids[]', '1'], ['classroom_ids[]', '17'], ['classroom_ids[]', '26'], ['classroom_ids[]', '27'],
    ]);
    assert.match(context.elements.get('#tm-summary').textContent, /الطلاب: 6.*أولياء الأمور: 6/);
});

test('an unbranched grade renders one checkbox and posts only its existing classroom ID', () => {
    const data = { ...structuredClone(fixture), previewOnly: false };
    const before = JSON.stringify(data);
    const context = previewDocument(data);
    const card = context.elements.get('#tm-grade-list').children.find(element => descendants(element).some(node => node.getAttribute('aria-label') === 'الثالث ثانوي'));
    assert.ok(card);
    assert.equal(descendants(card).filter(element => element.type === 'checkbox').length, 1);
    toggle(gradeCheckbox(context, 'الثالث ثانوي'));
    assert.deepEqual(submitSelection(context).fields, [
        ['audience', 'families'], ['selection_mode', 'branches'], ['classroom_ids[]', '14'],
    ]);
    assert.equal(JSON.stringify(data), before);
});

test('search selects names successively without discarding them and tabs submit only their own preserved scope', () => {
    const context = previewDocument({ ...fixture, previewOnly: false });
    toggle(gradeCheckbox(context, 'الصف الأول — الشعبة A'));
    searchPeople(context, 'احمد');
    assert.equal(context.elements.get('#tm-groups-panel').hidden, true);
    assert.equal(context.elements.get('#tm-people-panel').hidden, false);
    choosePerson(context, 1);
    searchPeople(context, 'مريم');
    choosePerson(context, 4);
    assert.equal(context.elements.get('#tm-selected-people').children.length, 2);
    assert.deepEqual(submitSelection(context).fields, [
        ['audience', 'families'], ['selection_mode', 'people'], ['student_ids[]', '1'], ['student_ids[]', '4'],
    ]);

    clickMode(context, 'branches');
    assert.deepEqual(submitSelection(context).fields, [
        ['audience', 'families'], ['selection_mode', 'branches'], ['classroom_ids[]', '1'],
    ]);
    assert.equal(context.elements.get('#tm-search').hidden, false);
    clickMode(context, 'people');
    assert.deepEqual(submitSelection(context).fields.filter(([name]) => name === 'student_ids[]'), [['student_ids[]', '1'], ['student_ids[]', '4']]);
    const removeAhmed = context.elements.get('#tm-selected-people').children.find(button => button.getAttribute('aria-label') === 'إزالة أحمد التجريبي');
    removeAhmed.listeners.click();
    assert.deepEqual(submitSelection(context).fields.filter(([name]) => name === 'student_ids[]'), [['student_ids[]', '4']]);

    context.buttons['[data-audience]'].find(button => button.dataset.audience === 'parents').listeners.click();
    assert.equal(context.elements.get('#tm-selected-people').children.length, 0);
    assert.equal(submitSelection(context).prevented, true);
    searchPeople(context, 'احمد');
    choosePerson(context, 101);
    assert.deepEqual(submitSelection(context).fields, [
        ['audience', 'parents'], ['selection_mode', 'people'], ['guardian_ids[]', '101'],
    ]);
    clickMode(context, 'branches');
    assert.deepEqual(submitSelection(context).fields, [
        ['audience', 'parents'], ['selection_mode', 'branches'], ['classroom_ids[]', '1'],
    ]);
});

test('select visible search results preserves hidden selections and clear resets both tabs', () => {
    const context = previewDocument({ ...fixture, previewOnly: false });
    context.elements.get('#tm-select-grades').listeners.click();
    assert.deepEqual(submitSelection(context).fields.filter(([name]) => name === 'classroom_ids[]').map(([, value]) => Number(value)), fixture.classes.map(room => room.id));
    searchPeople(context, 'احمد');
    context.elements.get('#tm-select-all').listeners.click();
    searchPeople(context, 'مريم');
    context.elements.get('#tm-select-all').listeners.click();
    assert.deepEqual(submitSelection(context).fields.filter(([name]) => name === 'student_ids[]'), [['student_ids[]', '1'], ['student_ids[]', '4']]);
    context.elements.get('#tm-select-all').listeners.click();
    assert.deepEqual(submitSelection(context).fields.filter(([name]) => name === 'student_ids[]'), [['student_ids[]', '1']]);
    context.elements.get('#tm-clear').listeners.click();
    assert.equal(submitSelection(context).prevented, true);
    clickMode(context, 'branches');
    assert.equal(submitSelection(context).prevented, true);
    assert.ok(descendants(context.elements.get('#tm-grade-list')).filter(element => element.type === 'checkbox').every(input => !input.checked && !input.indeterminate));
});

test('long Arabic bodies have no HTML length cap and remain complete through normal review submission', () => {
    const textarea = blade.match(/<textarea\b[^>]*id="tm-message"[^>]*>/)[0];
    const title = blade.match(/<input\b[^>]*id="tm-title"[^>]*>/)[0];
    assert.doesNotMatch(textarea, /\bmaxlength\s*=/i);
    assert.match(title, /\bmaxlength="60"/);
    const messageText = 'رسالة عربية طويلة تحتوي على تعليمات وملاحظات تفصيلية للطلاب وأولياء الأمور.\n'.repeat(220)
        + '\nالنهاية: <strong>نص حرفي</strong> & <script>مثال</script>';
    assert.ok(Array.from(messageText).length > 10000);
    const context = previewDocument({ ...fixture, previewOnly: false, initialState: state({ mode: 'people', personIds: [1] }) });
    const message = context.elements.get('#tm-message');
    message.value = messageText;
    message.listeners.input();
    assert.equal(context.elements.get('#tm-preview').disabled, false);
    assert.ok(context.elements.get('#tm-counter').textContent.includes(String(Array.from(messageText).length)));
    assert.doesNotMatch(context.elements.get('#tm-counter').textContent, /\/\s*\d+/);
    let prevented = false;
    context.elements.get('#tm-compose').listeners.submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
    assert.equal(message.value, messageText);
    assert.deepEqual(context.elements.get('#tm-selection-fields').children.map(input => [input.name, input.value]), [
        ['audience', 'families'], ['selection_mode', 'people'], ['student_ids[]', '1'],
    ]);
    assert.equal(context.csrf.value, 'original-csrf-token');
});
