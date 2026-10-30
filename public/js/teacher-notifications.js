export function allowedClass(subjectId, subjectIds) {
    return !subjectId || subjectIds.map(String).includes(String(subjectId));
}

export function filterClasses(subject, classroom) {
    for (const option of classroom.options) {
        const permitted = !option.value || allowedClass(subject.value, JSON.parse(option.dataset.subjects || '[]'));
        option.disabled = !permitted;
        option.hidden = !permitted;
    }
    if (classroom.selectedOptions[0]?.disabled) classroom.value = '';
}

export function bootTeacherNotifications(document) {
    const filters = document.querySelector('[data-teacher-notification-filters]');
    if (filters) {
        const subject = filters.querySelector('[name="subject_id"]');
        const classroom = filters.querySelector('[name="classroom_id"]');
        const refresh = () => filterClasses(subject, classroom);
        subject.addEventListener('change', refresh);
        refresh();
    }
    document.querySelectorAll('[data-teacher-notification-submit]').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            button.textContent = 'جارٍ تنفيذ الطلب…';
        });
    });
}

if (typeof document !== 'undefined') bootTeacherNotifications(document);
