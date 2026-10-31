(() => {
    const form = document.querySelector('[data-attendance-bulk]');
    if (!form) return;

    const rows = [...form.querySelectorAll('[data-attendance-row]')];
    const fields = rows.map((row) => row.querySelector('[data-attendance-status]')).filter(Boolean);
    const markAbsent = form.querySelector('[data-mark-all-absent]');
    const undoMarkAbsent = form.querySelector('[data-undo-mark-all-absent]');
    let previousStatuses = null;

    const absenceStatuses = new Set(['absent', 'excused_absence']);
    const specialStatuses = new Set(['late', 'excused_absence', 'excused_late']);

    const setStatus = (row, status) => {
        const field = row.querySelector('[data-attendance-status]');
        const checkbox = row.querySelector('[data-attendance-absence]');
        const detail = row.querySelector('[data-attendance-detail]');
        const label = row.querySelector('[data-attendance-label]');
        if (field) field.value = status;
        if (checkbox) checkbox.checked = absenceStatuses.has(status);
        if (detail) detail.value = specialStatuses.has(status) ? status : '';
        if (label) label.textContent = absenceStatuses.has(status) ? 'غائب' : 'حاضر';
    };

    rows.forEach((row) => {
        const checkbox = row.querySelector('[data-attendance-absence]');
        const detail = row.querySelector('[data-attendance-detail]');
        const field = row.querySelector('[data-attendance-status]');
        const status = field?.value ?? 'present';
        setStatus(row, status);

        checkbox?.addEventListener('change', () => {
            setStatus(row, checkbox.checked ? 'absent' : 'present');
        });

        detail?.addEventListener('change', () => {
            setStatus(row, detail.value || (checkbox?.checked ? 'absent' : 'present'));
        });
    });

    markAbsent?.addEventListener('click', () => {
        if (!fields.length || !window.confirm(`سيتم تحديد جميع الطلاب (${fields.length}) كغائبين. لن يُحفظ التغيير حتى تضغط «حفظ حضور اليوم». هل تريد المتابعة؟`)) return;

        previousStatuses = fields.map((field) => field.value);
        rows.forEach((row) => setStatus(row, 'absent'));
        markAbsent.hidden = true;
        if (undoMarkAbsent) undoMarkAbsent.hidden = false;
    });

    undoMarkAbsent?.addEventListener('click', () => {
        if (!previousStatuses) return;

        rows.forEach((row, index) => setStatus(row, previousStatuses[index] ?? 'present'));
        previousStatuses = null;
        undoMarkAbsent.hidden = true;
        if (markAbsent) markAbsent.hidden = false;
    });
})();
