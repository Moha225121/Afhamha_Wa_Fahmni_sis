import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const template = readFileSync(process.env.QA_EXAM_FORM_FILE || new URL('../../resources/views/teacher/exams/form.blade.php', import.meta.url), 'utf8');
const script = template.match(/<script>\s*(\(function\(\)\{[\s\S]*?\}\)\(\);)\s*<\/script>/)[1];

// Small DOM doubles execute the actual inline editor script and expose its controls/events.
function node() {
    return { style: {}, dataset: {}, listeners: {}, value: '', options: [], innerHTML: '',
        addEventListener(name, callback) { this.listeners[name] = callback; },
        querySelectorAll() { return []; },
    };
}

function render(existing) {
    const container = { children: [], appendChild(child) { this.children.push(child); }, querySelectorAll() { return this.children; } };
    const elements = { 'questions-container': container, 'classroom-select': node(), 'subject-select': node(), 'add-question': node(), 'existing-questions-data': { textContent: JSON.stringify(existing) } };
    const document = { getElementById(id) { return elements[id]; }, createElement() {
        const slots = { '.choices-list': node(), '.rich-editor': node(), '.type-select': node(), '.add-choice': node(), '.remove-question': node(), h2: node(), textarea: node() };
        slots['.choices-list'].rows = [];
        slots['.choices-list'].insertAdjacentHTML = (_, html) => slots['.choices-list'].rows.push(html);
        const wrap = { dataset: {}, slots, querySelector(selector) { return slots[selector.startsWith('textarea') ? 'textarea' : selector]; }, querySelectorAll() { return []; } };
        slots['.rich-editor'].parentElement = wrap;
        Object.defineProperty(wrap, 'innerHTML', { set(html) {
            slots['.type-select'].value = html.match(/option value="([^"]+)" selected/)[1];
            slots['.rich-editor'].innerHTML = html.match(/class="rich-editor"[^>]*>([\s\S]*?)<\/div>/)[1];
        } });
        return wrap;
    } };
    runInNewContext(script, { document });
    return { elements, container };
}

test('an existing short-answer question initializes without a declaration-order error', () => {
    const { container } = render([{ type: 'short_answer', text: '<b>سؤال عربي</b>', score: 2, choices: [] }]);
    assert.equal(container.children.length, 1);
    const slots = container.children[0].slots;
    assert.equal(slots['.choices-list'].style.display, 'none');
    assert.equal(slots['.add-choice'].style.display, 'none');
    assert.equal(slots.textarea.value, '<b>سؤال عربي</b>');
});

test('question type changes and adding a question keep the editor interactive', () => {
    const { container, elements } = render([{ type: 'short_answer', text: 'سؤال', score: 1 }]);
    const slots = container.children[0].slots;
    slots['.type-select'].value = 'mcq';
    slots['.type-select'].listeners.change();
    assert.equal(slots['.add-choice'].style.display, '');
    elements['add-question'].listeners.click();
    assert.equal(container.children.length, 2);
    assert.equal(container.children[1].slots.h2.textContent, 'السؤال 2');
});

test('literal choice text is escaped before it is inserted into an HTML attribute', () => {
    const { container } = render([{ type: 'mcq', text: 'سؤال', score: 1, choices: [{ text: '&quot; <tag> "', is_correct: true }] }]);
    assert.match(container.children[0].slots['.choices-list'].rows[0], /value="&amp;quot; &lt;tag&gt; &quot;"/);
});
