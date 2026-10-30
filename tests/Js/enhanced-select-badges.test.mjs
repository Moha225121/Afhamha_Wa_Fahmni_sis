import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/enhanced-selects.js', import.meta.url), 'utf8');

function harness({ badges = true, searchable = false, selected = 0, entries = [
    { label: 'أحمد', count: '3', fallback: 'أحمد — 3 رسائل غير مقروءة' },
    { label: 'مريم', count: '0', fallback: 'مريم' },
] } = {}) {
    let document;
    class Node {
        constructor(tag) {
            this.tagName = tag;
            this.attributes = {};
            this.dataset = {};
            this.children = [];
            this.listeners = new Map();
            this.style = {};
            this.className = '';
            this.disabled = false;
            this.hidden = false;
            this.value = '';
            this._text = '';
            this.classList = {
                contains: name => this.className.split(' ').includes(name),
                add: name => { if (!this.classList.contains(name)) this.className = (this.className + ' ' + name).trim(); },
                remove: name => { this.className = this.className.split(' ').filter(value => value !== name).join(' '); },
                toggle: (name, force) => {
                    const enabled = force ?? !this.classList.contains(name);
                    this.classList[enabled ? 'add' : 'remove'](name);
                    return enabled;
                },
            };
        }
        set textContent(value) { this._text = String(value); this.children = []; }
        get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
        set innerHTML(value) { assert.equal(value, ''); this._text = ''; this.children = []; }
        setAttribute(name, value) { this.attributes[name] = String(value); }
        getAttribute(name) { return this.attributes[name] ?? null; }
        hasAttribute(name) { return Object.hasOwn(this.attributes, name); }
        appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
        append(...children) { children.forEach(child => this.appendChild(child)); }
        replaceChildren(...children) { this.children = []; this._text = ''; this.append(...children); }
        insertAdjacentElement(where, node) {
            assert.equal(where, 'afterend');
            node.parentNode = this.parentNode;
            this.parentNode.children.splice(this.parentNode.children.indexOf(this) + 1, 0, node);
        }
        addEventListener(name, callback) {
            if (!this.listeners.has(name)) this.listeners.set(name, []);
            this.listeners.get(name).push(callback);
        }
        dispatchEvent(event) {
            event.target ??= this;
            for (const callback of this.listeners.get(event.type) ?? []) callback(event);
            return !event.defaultPrevented;
        }
        emit(type, fields = {}) {
            const event = { type, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...fields };
            this.dispatchEvent(event);
            return event;
        }
        focus() { document.activeElement = this; }
        select() { this.textSelected = true; }
        contains(target) { return this === target || this.children.some(child => child.contains(target)); }
        getBoundingClientRect() { return { width: 280, left: 20, top: 100, bottom: 146 }; }
        matches(selector) {
            if (selector.startsWith('select:')) return this.tagName === 'select'
                && !['multiple', 'size', 'data-native-select'].some(name => this.hasAttribute(name));
            if (selector.startsWith('[')) {
                const match = /^\[([^=]+)="([^"]*)"\]$/.exec(selector);
                return !!match && this.getAttribute(match[1]) === match[2];
            }
            const name = /^\.([a-zA-Z0-9_-]+)/.exec(selector)?.[1];
            return !!name && this.classList.contains(name)
                && (!selector.includes(':not(:disabled)') || !this.disabled)
                && (!selector.includes(':not([hidden])') || !this.hidden);
        }
        querySelectorAll(selector) { return this.children.flatMap(child => [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)]); }
        querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
    }
    document = new Node('document');
    document.readyState = 'complete';
    document.createElement = tag => new Node(tag);
    document.body = new Node('body');
    document.appendChild(document.body);
    const select = new Node('select');
    if (badges) select.setAttribute('data-unread-badges', '');
    if (searchable) select.setAttribute('data-searchable', '');
    select.setAttribute('aria-label', 'الابن');
    select.selectedIndex = selected;
    select.options = entries.map((entry, index) => {
        const option = new Node('option');
        option.value = String(index + 17);
        option.textContent = entry.fallback ?? entry.label;
        option.setAttribute('data-option-label', entry.label);
        option.setAttribute('data-unread-count', entry.count);
        option.disabled = entry.disabled ?? false;
        option.hidden = entry.hidden ?? false;
        Object.defineProperty(option, 'selected', { get: () => select.selectedIndex === index });
        select.appendChild(option);
        return option;
    });
    Object.defineProperty(select, 'value', { get: () => select.options[select.selectedIndex]?.value ?? '' });
    document.body.appendChild(select);
    const window = new Node('window');
    window.innerHeight = 800;
    const observers = [];
    class MutationObserver {
        constructor(callback) { this.callback = callback; observers.push(this); }
        observe(target, options) { this.target = target; this.options = options; }
    }
    let changes = 0;
    select.addEventListener('change', () => { changes++; });
    vm.runInNewContext(source, {
        document, window, MutationObserver,
        Event: class { constructor(type, options) { this.type = type; Object.assign(this, options); } },
    });
    return {
        document, window, select, observers,
        get button() { return document.querySelector('.select-ui__button'); },
        get menu() { return document.querySelector('.select-ui__menu'); },
        get options() { return this.menu.querySelectorAll('.select-ui__option'); },
        get changes() { return changes; },
        mutate(index, attributes) {
            for (const [name, value] of Object.entries(attributes)) select.options[index].setAttribute(name, value);
            observers[0].callback([]);
        },
    };
}

test('opted-in child selectors show safe unread badges while retaining native fallback and accessible names', () => {
    const page = harness();
    assert.equal(page.button.querySelector('.select-ui__option-label').textContent, 'أحمد');
    assert.equal(page.button.querySelector('.select-ui__unread-badge').textContent, '3');
    assert.equal(page.button.querySelector('.select-ui__unread-badge').getAttribute('aria-hidden'), 'true');
    assert.equal(page.button.getAttribute('aria-label'), 'الابن: أحمد، 3 رسائل غير مقروءة');
    assert.equal(page.select.options[0].textContent, 'أحمد — 3 رسائل غير مقروءة');
    page.button.emit('click');
    assert.equal(page.menu.parentNode, page.document.body, 'the badge must work in the body-level popup');
    assert.equal(page.options[0].getAttribute('role'), 'option');
    assert.equal(page.options[0].getAttribute('aria-selected'), 'true');
    assert.equal(page.options[0].getAttribute('aria-label'), 'أحمد، 3 رسائل غير مقروءة');
    assert.equal(page.options[1].querySelector('.select-ui__unread-badge'), null);
    assert.equal(page.options[1].getAttribute('aria-label'), 'مريم، لا توجد رسائل غير مقروءة');
});

test('ordinary selects keep their original plain text and receive no badges or special accessible labels', () => {
    const page = harness({ badges: false });
    assert.equal(page.button.textContent, 'أحمد — 3 رسائل غير مقروءة');
    assert.equal(page.button.children.length, 0);
    assert.equal(page.button.getAttribute('aria-label'), null);
    page.button.emit('click');
    assert.equal(page.options[0].textContent, page.select.options[0].textContent);
    assert.equal(page.options[0].querySelector('.select-ui__unread-badge'), null);
});

test('polling badge mutations update open and closed selectors without changing the child, submitting, or losing keyboard focus', () => {
    const page = harness();
    page.mutate(0, { 'data-unread-count': '0' });
    assert.equal(page.button.querySelector('.select-ui__unread-badge'), null);
    page.button.emit('click');
    page.options[1].focus();
    page.mutate(1, { 'data-unread-count': '12' });
    assert.equal(page.options[1].querySelector('.select-ui__unread-badge').textContent, '12');
    assert.equal(page.document.activeElement, page.options[1]);
    assert.equal(page.select.selectedIndex, 0);
    assert.equal(page.select.value, '17');
    assert.equal(page.changes, 0);
    assert.equal(page.button.getAttribute('aria-expanded'), 'true');
});

test('keyboard navigation, disabled options, and selecting a child keep the original change event contract', () => {
    const page = harness({ entries: [
        { label: 'أحمد', count: '1' }, { label: 'غير متاح', count: '2', disabled: true }, { label: 'مريم', count: '4' },
    ] });
    assert.equal(page.button.emit('keydown', { key: 'ArrowDown' }).defaultPrevented, true);
    assert.equal(page.document.activeElement, page.options[0]);
    page.menu.emit('keydown', { key: 'ArrowDown' });
    assert.equal(page.document.activeElement, page.options[2]);
    page.options[1].emit('click');
    assert.equal(page.changes, 0);
    page.options[2].emit('click');
    assert.equal(page.select.selectedIndex, 2);
    assert.equal(page.changes, 1);
    assert.equal(page.button.getAttribute('aria-expanded'), 'false');
    assert.equal(page.document.activeElement, page.button);
    assert.equal(page.button.querySelector('.select-ui__unread-badge').textContent, '4');
    page.button.emit('click');
    page.menu.emit('keydown', { key: 'Escape' });
    assert.equal(page.document.activeElement, page.button);
    assert.equal(page.button.getAttribute('aria-expanded'), 'false');
    assert.equal(page.changes, 1);
});

test('invalid counts never create a badge and child labels remain literal text instead of HTML', () => {
    for (const count of ['-1', '1.5', '3x', 'Infinity', '<img src=x>', '9007199254740992']) {
        const page = harness({ entries: [{ label: '<img src=x onerror=alert(1)> أحمد', count }] });
        assert.equal(page.button.querySelector('.select-ui__unread-badge'), null, count);
        assert.equal(page.button.querySelector('.select-ui__option-label').textContent, '<img src=x onerror=alert(1)> أحمد');
    }
    const page = harness({ entries: [{ label: 'أحمد', count: '105' }] });
    assert.equal(page.button.querySelector('.select-ui__unread-badge').textContent, '99+');
    assert.match(page.button.getAttribute('aria-label'), /105 رسائل/);
});

test('searchable badge menus search child names and omit hidden entries', () => {
    const page = harness({ searchable: true, entries: [
        { label: 'أحمد', count: '7' }, { label: 'مريم', count: '2' }, { label: 'أحمد مخفي', count: '8', hidden: true },
    ] });
    page.button.emit('click');
    const search = page.menu.querySelector('.select-ui__search');
    search.value = 'أحمد';
    search.emit('input');
    assert.equal(page.options.length, 1);
    assert.equal(page.options[0].querySelector('.select-ui__option-label').textContent, 'أحمد');
    search.value = 'لا يوجد';
    search.emit('input');
    assert.equal(page.options.length, 0);
    assert.equal(page.menu.querySelector('.select-ui__empty').textContent, 'لا توجد نتائج مطابقة');
});
