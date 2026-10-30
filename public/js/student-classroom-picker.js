(function () {
    'use strict';
    const form = document.getElementById('admin-student-form');
    if (!form) return;

    const grade = form.querySelector('#student-grade');
    const classroom = form.querySelector('#student-classroom');
    const gradeField = form.querySelector('#student-grade-field');
    const branchField = form.querySelector('#student-branch-field');
    const branchLabel = form.querySelector('#student-classroom-label');
    const hint = form.querySelector('#student-classroom-hint');
    const placeholder = classroom.options[0];
    const rooms = Array.from(classroom.options).slice(1);

    function updateClassrooms() {
        const available = rooms.filter(option => option.dataset.grade === grade.value);
        const hasBranches = available.length > 1;
        rooms.forEach(option => {
            option.hidden = !available.includes(option);
            option.disabled = option.hidden;
            option.textContent = option.dataset.branchLabel;
        });
        if (!available.some(option => option.value === classroom.value)) classroom.value = '';
        if (available.length === 1) classroom.value = available[0].value;
        branchField.hidden = !hasBranches;
        branchLabel.textContent = 'الشعبة';
        placeholder.textContent = hasBranches ? 'اختر الشعبة' : 'بدون صف';
        classroom.required = hasBranches;
        hint.hidden = !hasBranches;
        hint.textContent = hasBranches ? 'اختر إحدى الشعب الموجودة لهذا الصف.' : '';
        classroom.dispatchEvent(new Event('change', { bubbles: true }));
    }

    gradeField.hidden = false;
    grade.addEventListener('change', updateClassrooms);
    updateClassrooms();

    [grade, classroom].forEach(select => {
        const button = select.nextElementSibling?.querySelector('.select-ui__button');
        if (!button) return;
        button.id = select.id + '-button';
        button.setAttribute('aria-labelledby', (select === grade ? 'student-grade-label ' : 'student-classroom-label ') + button.id);
        select.closest('label').htmlFor = button.id;
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        select.addEventListener('invalid', event => {
            event.preventDefault();
            button.focus();
            hint.hidden = false;
            hint.textContent = 'اختر الشعبة قبل حفظ بيانات الطالب.';
        });
    });
}());
