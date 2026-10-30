export function normalizeArabic(value) {
    return String(value ?? '').normalize('NFKD').replace(/[\u064b-\u065f\u0670\u0640]/g, '')
        .replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').toLocaleLowerCase('ar').replace(/\s+/g, ' ').trim();
}

export function nameMatches(query, value) {
    const normalized = normalizeArabic(value);
    return normalizeArabic(query).split(' ').filter(Boolean).every(word => normalized.includes(word));
}

export function resolveRecipients(data, state) {
    const classIds = state.mode === 'all' ? data.classes.map(c => c.id)
        : state.mode === 'branches' ? state.branchIds
        : data.grades.find(g => g.id === state.gradeId)?.classIds ?? [];
    let students = state.mode === 'people'
        ? data.students.filter(s => state.audience === 'parents'
            ? s.parentIds.some(id => state.personIds.includes(id)) : state.personIds.includes(s.id))
        : data.students.filter(s => classIds.includes(s.classId));
    const unlinked = students.filter(s => !s.parentIds.some(id => data.parents.some(p => p.id === id)));
    if (data.unlinkedPolicy === 'exclude') students = students.filter(s => !unlinked.includes(s));
    const parents = data.parents.flatMap(parent => {
        if (state.audience === 'parents' && state.mode === 'people' && !state.personIds.includes(parent.id)) return [];
        const children = students.filter(s => s.parentIds.includes(parent.id));
        return children.length ? [{...parent, children}] : [];
    });
    const studentRecipients = state.audience === 'parents' ? [] : students;
    return {students: studentRecipients, parents, unlinked, total: studentRecipients.length + parents.length};
}

export function serializeSelection(data, state) {
    if (state.mode === 'people') return {
        audience: state.audience,
        selection_mode: state.mode,
        [state.audience === 'parents' ? 'guardian_ids' : 'student_ids']: [...state.personIds],
    };
    const classIds = state.mode === 'all' ? data.classes.map(room => room.id)
        : state.mode === 'branches' ? state.branchIds
        : data.grades.find(grade => grade.id === state.gradeId)?.classIds ?? [];
    return {audience: state.audience, selection_mode: state.mode, classroom_ids: [...classIds]};
}

export function initialSelection(data) {
    const previous = data.initialState ?? {};
    const audience = previous.audience === 'parents' ? 'parents' : 'families';
    const allowedPeople = (audience === 'parents' ? data.parents : data.students).map(person => person.id);
    return {
        audience,
        mode: previous.mode === 'people' ? 'people' : 'branches',
        branchIds: [...new Set(previous.branchIds ?? [])].filter(id => data.classes.some(room => room.id === id)),
        personIds: [...new Set(previous.personIds ?? [])].filter(id => allowedPeople.includes(id)),
    };
}

export function bootMessaging(doc) {
    const node = doc.querySelector('#tm-data');
    if (!node) return;
    const data = JSON.parse(node.textContent);
    const state = initialSelection(data);
    const limit = data.limit ?? 100;
    const defaultTitle = data.teacherName ? 'رسالة من ' + data.teacherName : 'رسالة من المعلم';
    const $ = selector => doc.querySelector(selector);
    const message = $('#tm-message');
    const title = $('#tm-title');
    const groupControls = [];
    const make = (tag, text, className) => {
        const element = doc.createElement(tag);
        if (text !== undefined) element.textContent = text;
        if (className) element.className = className;
        return element;
    };
    const classLabel = id => data.classes.find(room => room.id === id)?.name ?? '';
    const childText = children => children.map(student => student.name).join('، ');
    const visiblePeople = () => {
        const people = state.audience === 'parents'
            ? data.parents.map(parent => ({...parent, context: childText(data.students.filter(student => student.parentIds.includes(parent.id)))}))
            : data.students.map(student => ({...student, context: classLabel(student.classId)}));
        return people.filter(person => nameMatches($('#tm-search').value, person.name + ' ' + person.context));
    };
    const selectedPeople = () => (state.audience === 'parents' ? data.parents : data.students).filter(person => state.personIds.includes(person.id));

    function updateGroupChecks() {
        for (const {input, ids} of groupControls) {
            const count = ids.filter(id => state.branchIds.includes(id)).length;
            input.checked = count === ids.length;
            input.indeterminate = count > 0 && count < ids.length;
        }
    }
    function renderGroups() {
        const list = $('#tm-grade-list');
        list.replaceChildren();
        for (const grade of data.grades) {
            const rooms = data.classes.filter(room => grade.classIds.includes(room.id));
            if (!rooms.length) continue;
            const card = make('article', undefined, 'tm-grade-card');
            const heading = make('label', undefined, 'tm-grade-choice');
            const checkbox = make('input');
            checkbox.type = 'checkbox';
            checkbox.setAttribute('aria-label', grade.name);
            const copy = make('span');
            copy.append(make('strong', grade.name), make('small', rooms.length > 1 ? 'تحديد جميع الشعب' : 'تحديد الصف'));
            heading.append(checkbox, copy);
            const ids = rooms.map(room => room.id);
            groupControls.push({input: checkbox, ids});
            checkbox.addEventListener('change', () => {
                state.branchIds = state.branchIds.filter(id => !ids.includes(id));
                if (checkbox.checked) state.branchIds.push(...ids);
                updateGroupChecks();
                refresh();
            });
            card.append(heading);
            // One existing classroom needs no additional branch controls.
            if (rooms.length > 1) {
                const branches = make('div', undefined, 'tm-grade-branches');
                for (const room of rooms) {
                    const label = make('label', undefined, 'tm-branch-choice');
                    const input = make('input');
                    input.type = 'checkbox';
                    input.setAttribute('aria-label', grade.name + ' — ' + (room.branch ? 'الشعبة ' + room.branch : room.name));
                    groupControls.push({input, ids: [room.id]});
                    label.append(input, make('span', room.branch ? 'الشعبة ' + room.branch : room.name));
                    input.addEventListener('change', () => {
                        state.branchIds = state.branchIds.filter(id => id !== room.id);
                        if (input.checked) state.branchIds.push(room.id);
                        updateGroupChecks();
                        refresh();
                    });
                    branches.append(label);
                }
                card.append(branches);
            }
            list.append(card);
        }
        if (!data.classes.length) list.append(make('p', 'لا توجد صفوف مسندة إليك حاليًا.', 'tm-empty-list'));
        updateGroupChecks();
    }
    function renderPeople() {
        const people = visiblePeople();
        const list = $('#tm-people');
        list.replaceChildren();
        for (const person of people) {
            const label = make('label', undefined, 'tm-person');
            const input = make('input');
            input.type = 'checkbox';
            input.value = String(person.id);
            input.checked = state.personIds.includes(person.id);
            const copy = make('span', undefined, 'tm-person-copy');
            copy.append(make('strong', person.name), make('small', person.context));
            label.append(input, copy);
            input.addEventListener('change', () => {
                state.personIds = state.personIds.filter(id => id !== person.id);
                if (input.checked) state.personIds.push(person.id);
                refresh();
            });
            list.append(label);
        }
        if (!people.length) list.append(make('p', 'لا توجد أسماء مطابقة. جرّب جزءًا من الاسم.', 'tm-empty-list'));
        $('#tm-visible-count').textContent = 'نتائج البحث: ' + people.length;
        updateSelectAll();
    }
    function updateSelectAll() {
        const people = visiblePeople();
        const allSelected = people.length && people.every(person => state.personIds.includes(person.id));
        $('#tm-select-all').textContent = allSelected ? 'إلغاء تحديد نتائج البحث' : 'تحديد نتائج البحث';
        $('#tm-select-all').disabled = !people.length;
    }
    function renderSelectedNames() {
        const list = $('#tm-selected-people');
        list.replaceChildren();
        const people = selectedPeople();
        for (const person of people.slice(0, 10)) {
            const button = make('button', person.name + ' ×', 'tm-selected-name');
            button.type = 'button';
            button.setAttribute('aria-label', 'إزالة ' + person.name);
            button.addEventListener('click', () => {
                state.personIds = state.personIds.filter(id => id !== person.id);
                renderPeople();
                refresh();
                $('#tm-search').focus();
            });
            list.append(button);
        }
        if (people.length > 10) list.append(make('span', 'وأسماء أخرى: ' + (people.length - 10), 'tm-helper'));
    }
    function refresh() {
        const result = resolveRecipients(data, state);
        $('#tm-summary').textContent = !result.total ? 'لم تختر مستلمين بعد'
            : state.audience === 'parents' ? 'أولياء الأمور: ' + result.parents.length
            : 'الطلاب: ' + result.students.length + ' · أولياء الأمور: ' + result.parents.length;
        const names = state.mode === 'people' ? selectedPeople().map(person => person.name)
            : data.classes.filter(room => state.branchIds.includes(room.id)).map(room => room.name);
        $('#tm-selection-details').textContent = names.length
            ? names.slice(0, 4).join('، ') + (names.length > 4 ? ' وأخرى (' + names.length + ')' : '')
            : 'اختر صفوفًا أو ابحث عن أسماء محددة.';
        $('#tm-rule').textContent = state.audience === 'parents'
            ? 'تصل الرسالة لأولياء الأمور فقط، مع توضيح أسماء الأبناء المعنيين.'
            : 'يتلقى ولي الأمر المرتبط نسخة واحدة توضح أسماء أبنائه المعنيين.';
        const tooMany = result.total > limit;
        $('#tm-preview').disabled = !result.total || !message.value.trim() || tooMany;
        $('#tm-error').hidden = !tooMany;
        $('#tm-error').textContent = tooMany ? 'اختر مجموعة أصغر. الحد الحالي ' + limit + ' مستلم للرسالة الواحدة.' : '';
        $('#tm-counter').textContent = Array.from(message.value).length + ' حرف';
        renderSelectedNames();
        updateSelectAll();
    }
    function setMode(mode) {
        state.mode = mode;
        $('#tm-groups-panel').hidden = mode !== 'branches';
        $('#tm-people-panel').hidden = mode !== 'people';
        doc.querySelectorAll('[data-mode]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.mode === mode)));
        renderPeople();
        refresh();
    }
    doc.querySelectorAll('[data-mode]').forEach(button => button.addEventListener('click', () => setMode(button.dataset.mode)));
    doc.querySelectorAll('[data-audience]').forEach(button => button.addEventListener('click', () => {
        if (state.audience === button.dataset.audience) return;
        state.audience = button.dataset.audience;
        state.personIds = [];
        updateAudience();
        renderPeople();
        refresh();
    }));
    function updateAudience() {
        doc.querySelectorAll('[data-audience]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.audience === state.audience)));
        $('#tm-search-label').textContent = state.audience === 'parents' ? 'ابحث عن ولي أمر أو اسم ابنه' : 'ابحث عن طالب';
        $('#tm-search').placeholder = state.audience === 'parents' ? 'اكتب اسم ولي الأمر أو الطالب' : 'اكتب اسم الطالب للبحث والاختيار';
    }
    $('#tm-search').addEventListener('input', () => setMode('people'));
    $('#tm-select-all').addEventListener('click', () => {
        const ids = visiblePeople().map(person => person.id);
        const remove = ids.every(id => state.personIds.includes(id));
        state.personIds = remove ? state.personIds.filter(id => !ids.includes(id)) : [...new Set([...state.personIds, ...ids])];
        renderPeople();
        refresh();
    });
    $('#tm-select-grades').addEventListener('click', () => {
        state.branchIds = data.classes.map(room => room.id);
        updateGroupChecks();
        refresh();
    });
    $('#tm-clear').addEventListener('click', () => {
        state.branchIds = [];
        state.personIds = [];
        updateGroupChecks();
        refresh();
        renderPeople();
    });
    message.addEventListener('input', refresh);
    title.addEventListener('input', refresh);
    $('#tm-compose').addEventListener('submit', event => {
        const result = resolveRecipients(data, state);
        if (data.previewOnly !== false || !result.total || !message.value.trim() || result.total > limit) {
            event.preventDefault();
            return;
        }
        const fields = $('#tm-selection-fields');
        fields.replaceChildren();
        for (const [key, value] of Object.entries(serializeSelection(data, state))) {
            for (const item of Array.isArray(value) ? value : [value]) {
                const input = make('input');
                input.type = 'hidden';
                input.name = key + (Array.isArray(value) ? '[]' : '');
                input.value = String(item);
                fields.append(input);
            }
        }
        title.value = title.value.trim() || defaultTitle;
        $('#tm-preview').disabled = true;
        $('#tm-preview').textContent = 'جارٍ مراجعة المستلمين…';
    });
    renderGroups();
    updateAudience();
    setMode(state.mode);
}
if (typeof document !== 'undefined') bootMessaging(document);
