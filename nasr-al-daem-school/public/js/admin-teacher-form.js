(function () {
    const list = document.getElementById('teacher-assignment-list');
    const template = document.getElementById('teacher-assignment-template');
    const addButton = document.getElementById('add-teacher-assignment');
    const data = window.teacherAssignmentData || {};

    if (!list || !template || !addButton) return;

    const classrooms = data.classroomSubjects || {};
    const subjects = data.subjects || [];
    const maxAssignments = Number(list.dataset.maxAssignments || 30);

    function refreshSubjects(row, keepSelection) {
        const classroomSelect = row.querySelector('.teacher-assignment-classroom');
        const subjectSelect = row.querySelector('.teacher-assignment-subject');
        if (!classroomSelect || !subjectSelect) return;

        const classroomId = classroomSelect.value;
        const groupHint = row.querySelector('.teacher-assignment-group-hint');
        const groupMembers = (data.classroomGroups || {})[classroomId];
        if (groupHint) groupHint.textContent = groupMembers ? `سيُطبّق هذا الإسناد على ${groupMembers.length} شعب عند الحفظ.` : '';
        const allowedIds = (classrooms[classroomId] || []).map(String);
        const previousValue = keepSelection ? subjectSelect.value || subjectSelect.dataset.selectedSubject || '' : '';
        const options = subjects.filter((subject) => allowedIds.includes(String(subject.id)));
        const selectionIsAllowed = options.some((subject) => String(subject.id) === String(previousValue));

        subjectSelect.replaceChildren(new Option(classroomId ? 'اختر المادة' : 'اختر الصف أولًا', ''));
        options.forEach((subject) => {
            const option = new Option(subject.label, subject.id);
            option.selected = selectionIsAllowed && String(subject.id) === String(previousValue);
            subjectSelect.add(option);
        });

        subjectSelect.disabled = !classroomId || options.length === 0;
        if (classroomId && options.length === 0) {
            subjectSelect.options[0].textContent = 'لا توجد مواد مرتبطة بهذا الصف';
        }
        delete subjectSelect.dataset.selectedSubject;
        subjectSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function renameRows() {
        const rows = Array.from(list.querySelectorAll('.teacher-assignment-row'));
        rows.forEach((row, index) => {
            row.querySelectorAll('[data-name]').forEach((field) => {
                field.name = field.dataset.name.replace('__INDEX__', String(index));
                delete field.dataset.name;
            });
            const classroom = row.querySelector('.teacher-assignment-classroom');
            const subject = row.querySelector('.teacher-assignment-subject');
            if (classroom) classroom.name = `assignments[${index}][classroom_id]`;
            if (subject) subject.name = `assignments[${index}][subject_id]`;
            const remove = row.querySelector('.remove-teacher-assignment');
            if (remove) remove.setAttribute('aria-label', `حذف الإسناد ${index + 1}`);
        });
        addButton.disabled = rows.length >= maxAssignments;
        addButton.setAttribute('aria-disabled', addButton.disabled ? 'true' : 'false');
    }

    list.addEventListener('change', (event) => {
        if (event.target.matches('.teacher-assignment-classroom')) {
            refreshSubjects(event.target.closest('.teacher-assignment-row'), true);
        }
    });

    list.addEventListener('click', (event) => {
        const remove = event.target.closest('.remove-teacher-assignment');
        if (!remove) return;
        const rows = list.querySelectorAll('.teacher-assignment-row');
        if (rows.length <= 1) return;
        remove.closest('.teacher-assignment-row').remove();
        renameRows();
    });

    addButton.addEventListener('click', () => {
        if (list.querySelectorAll('.teacher-assignment-row').length >= maxAssignments) return;
        const fragment = template.content.cloneNode(true);
        list.appendChild(fragment);
        renameRows();
        document.dispatchEvent(new Event('enhanced-selects:init'));
    });

    list.querySelectorAll('.teacher-assignment-row').forEach((row) => refreshSubjects(row, true));
    renameRows();
})();
