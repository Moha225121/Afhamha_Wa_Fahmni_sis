import test from 'node:test';
import assert from 'node:assert/strict';
import { allowedClass, filterClasses, bootTeacherNotifications } from '../../public/js/teacher-notifications.js';

test('subject filters permit only matching teaching pairs and never broaden an explicit subject', () => {
    assert.equal(allowedClass('', [1, 2]), true);
    assert.equal(allowedClass('1', [1, 2]), true);
    assert.equal(allowedClass('3', [1, 2]), false);
});

test('changing subject hides incompatible classes and clears an invalid selection', () => {
    const empty = { value: '', dataset: {} };
    const own = { value: '10', dataset: { subjects: '[1]' } };
    const other = { value: '20', dataset: { subjects: '[2]' } };
    const classroom = { options: [empty, own, other], selectedOptions: [other], value: '20' };
    filterClasses({ value: '1' }, classroom);
    assert.equal(classroom.value, '');
    assert.equal(other.disabled, true); assert.equal(other.hidden, true);
    assert.equal(own.disabled, false); assert.equal(empty.disabled, false);
});

test('submit disables its button without initiating a second request', () => {
    let listener;
    const button = { disabled: false, textContent: '' };
    const form = { addEventListener: (name, fn) => { listener = fn; }, querySelector: () => button };
    bootTeacherNotifications({ querySelector: () => null, querySelectorAll: () => [form] });
    listener(); assert.equal(button.disabled, true); assert.equal(button.textContent, 'جارٍ تنفيذ الطلب…');
});
