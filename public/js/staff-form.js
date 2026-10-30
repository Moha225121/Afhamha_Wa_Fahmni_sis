(() => {
    const form = document.getElementById('staff-create-form');
    if (!form) return;
    const roles = [...form.querySelectorAll('[data-staff-role]')];
    const classroomBox = form.querySelector('[data-staff-classrooms]');
    const classInputs = [...form.querySelectorAll('[name="classroom_ids[]"]')];
    const update = () => {
        const permissions = new Set(roles.filter(input => input.checked).flatMap(input => JSON.parse(input.dataset.permissions)));
        let visible = 0;
        form.querySelectorAll('[data-staff-portal]').forEach(chip => {
            chip.hidden = !JSON.parse(chip.dataset.permissions).some(permission => permissions.has(permission));
            if (!chip.hidden) visible++;
        });
        form.querySelector('[data-staff-preview-empty]').hidden = visible > 0;
        const selected = form.querySelector('[name="scope_mode"]:checked').value === 'selected';
        classroomBox.hidden = !selected;
        classInputs.forEach(input => { input.disabled = !selected; });
        form.querySelector('[data-staff-class-count]').textContent = `الصفوف المحددة: ${classInputs.filter(input => input.checked).length}`;
    };
    form.addEventListener('change', update);
    const normalize = value => value.replace(/[أإآ]/g, 'ا').replace(/[\u064B-\u065F]/g, '').toLowerCase().trim();
    form.querySelector('[data-staff-class-search]').addEventListener('input', event => {
        const words = normalize(event.target.value).split(/\s+/);
        form.querySelectorAll('[data-staff-class]').forEach(row => { row.hidden = !words.every(word => normalize(row.textContent).includes(word)); });
    });
    form.querySelector('[data-staff-class-all]').addEventListener('click', () => {
        classInputs.filter(input => !input.closest('[data-staff-class]').hidden).forEach(input => { input.checked = true; }); update();
    });
    form.querySelector('[data-staff-class-clear]').addEventListener('click', () => { classInputs.forEach(input => { input.checked = false; }); update(); });
    form.addEventListener('submit', event => {
        if (!roles.some(input => input.checked)) { event.preventDefault(); roles[0]?.focus(); alert('اختر دورًا واحدًا على الأقل للموظف.'); }
        else if (!classroomBox.hidden && !classInputs.some(input => input.checked)) { event.preventDefault(); classInputs[0]?.focus(); alert('حدد صفًا واحدًا على الأقل أو اختر كل الصفوف.'); }
    });
    update();
})();
