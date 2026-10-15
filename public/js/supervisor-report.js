(() => {
    const page = document.querySelector('[data-report-page]');
    if (!page) return;

    const period = page.querySelector('[data-report-period]');
    const date = page.querySelector('[data-report-date]');
    const month = page.querySelector('[data-report-month]');
    const from = page.querySelector('[data-report-from]');
    const to = page.querySelector('[data-report-to]');
    const classroom = page.querySelector('[data-report-classroom]');
    const student = page.querySelector('[data-report-student]');
    const options = student ? [...student.options].map((option) => ({ option, classroomId: option.dataset.classroomId })) : [];

    const updatePeriodFields = () => {
        const value = period?.value ?? 'daily';
        if (date) date.hidden = value !== 'daily' && value !== 'weekly';
        if (month) month.hidden = value !== 'monthly';
        if (from) from.hidden = value !== 'custom';
        if (to) to.hidden = value !== 'custom';
    };

    const updateStudents = () => {
        const classId = classroom?.value ?? '';
        options.forEach(({ option, classroomId }) => {
            if (!classroomId) return;
            option.hidden = Boolean(classId && classroomId !== classId);
            if (option.hidden && option.selected) {
                student.value = '';
            }
        });
    };

    period?.addEventListener('change', updatePeriodFields);
    classroom?.addEventListener('change', updateStudents);
    updatePeriodFields();
    updateStudents();
})();
