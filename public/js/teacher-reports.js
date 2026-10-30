export const reportRatingLabels = {attention: 'يرجى الاهتمام', good: 'جيد', very_good: 'جيد جدًا', excellent: 'ممتاز'};
const periodLabels = {weekly: 'أسبوعي', monthly: 'شهري', midterm: 'نصفي', final: 'نهائي', overall: 'شامل'};
const categoryLabels = {weekly: 'أسبوعي', monthly: 'شهري', midterm: 'نصفي', final: 'نهائي', other: 'تقييمات أخرى'};
const clone = value => JSON.parse(JSON.stringify(value));
const finiteNumber = value => {
    if (value === null || value === undefined || typeof value === 'boolean' || typeof value === 'object' || String(value).trim() === '') return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
};
const rounded = value => Math.round((value + Number.EPSILON) * 100) / 100;
export function ratingFor(percent, thresholds = {excellent: 85, very_good: 75, good: 65}) {
    const score = finiteNumber(percent);
    const good = finiteNumber(thresholds?.good), veryGood = finiteNumber(thresholds?.very_good), excellent = finiteNumber(thresholds?.excellent);
    if (score === null || score < 0 || score > 100 || good === null || veryGood === null || excellent === null || good < 0 || !(good < veryGood && veryGood < excellent) || excellent > 100) return null;
    return score >= excellent ? 'excellent' : score >= veryGood ? 'very_good' : score >= good ? 'good' : 'attention';
}
export function reportPercentage(scores, aggregation = 'mean') {
    const valid = (Array.isArray(scores) ? scores : []).map(item => ({score: finiteNumber(item?.score), maximum: finiteNumber(item?.maximum)})).filter(item => item.score !== null && item.maximum !== null && item.maximum > 0 && item.score >= 0 && item.score <= item.maximum);
    if (!valid.length) return null;
    return rounded(aggregation === 'points'
        ? valid.reduce((sum, item) => sum + item.score, 0) / valid.reduce((sum, item) => sum + item.maximum, 0) * 100
        : valid.reduce((sum, item) => sum + item.score / item.maximum * 100, 0) / valid.length);
}
export function normalizeReportSearch(value) {
    return String(value ?? '').toLowerCase().replace(/[\u064B-\u065F\u0670\u0640]/g, '').replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/\s+/g, ' ').trim();
}
export function matchesReportSearch(text, query) {
    const haystack = normalizeReportSearch(text);
    return normalizeReportSearch(query).split(' ').filter(Boolean).every(token => haystack.includes(token));
}
export function eligibleReportSource(source, config) {
    if (!source || !config || !source.subject_id || !(config.subject_ids || []).map(String).includes(String(source.subject_id))) return false;
    const categories = config.categories === null || config.categories === undefined ? (config.report_type === 'overall' ? [] : [config.report_type]) : config.categories;
    if (categories.length && !categories.includes(source.assessment_type)) return false;
    if ((config.from || config.to) && !/^\d{4}-\d{2}-\d{2}$/.test(source.assessment_date || '')) return false;
    return (!config.from || source.assessment_date >= config.from) && (!config.to || source.assessment_date <= config.to);
}
export function applyEntryModes(entry, thresholds) {
    const effective = clone(entry);
    effective.score = effective.score_mode === 'manual' ? finiteNumber(effective.score) : finiteNumber(effective.automatic_score);
    effective.automatic_rating = ratingFor(effective.automatic_score, thresholds);
    effective.rating = effective.rating_mode === 'manual' ? (Object.hasOwn(reportRatingLabels, effective.rating) ? effective.rating : null) : ratingFor(effective.score, thresholds);
    return effective;
}

export function initTeacherReports(doc = document, options = {}) {
    const root = doc.querySelector('[data-teacher-reports]');
    const initialData = root?.querySelector('[data-trp-data]');
    if (!root || !initialData) return null;
    let builder;
    try { builder = JSON.parse(initialData.textContent); } catch { return null; }
    if (!builder.ready) return null;
    const win = options.window || doc.defaultView || globalThis.window;
    const fetchFn = options.fetchFn || globalThis.fetch.bind(globalThis);
    const form = root.querySelector('[data-trp-config]');
    const errorBox = root.querySelector('[data-trp-error]');
    const saveState = root.querySelector('[data-trp-save-state]');
    const token = root.querySelector('[name="_token"]')?.value || '';
    let dirty = false, pendingFilters = false, metadataDirty = false, busy = false, disposed = false;
    let refreshSources = false, sourceSelection = builder.config.source_keys === null ? null : [...builder.config.source_keys];
    let categorySelection = builder.config.categories === null ? null : [...builder.config.categories];
    let activePeriod = builder.config.report_type;
    let resetPeriodDefaults = false;
    let aborter;
    let sharePreviewTimer, sharePreviewSequence = 0;
    let share = {reportId: null, students: [], selected: new Set(), specificInitialized: false, scope: 'all', counts: null, key: null, selectionKey: null, requestId: null, sentKey: null};
    let disabledControls = new Map();
    const $ = selector => root.querySelector(selector);
    const $$ = selector => Array.from(root.querySelectorAll(selector));
    const field = name => $(`[data-trp-config-field="${name}"]`);
    const text = (tag, value, className) => { const node = doc.createElement(tag); if (value !== undefined) node.textContent = String(value ?? ''); if (className) node.className = className; return node; };
    const button = (label, attribute, className = 'trp-button trp-button-text') => { const node = text('button', label, className); node.type = 'button'; node.setAttribute(attribute, ''); return node; };
    const numberText = value => value === null || value === undefined ? '—' : String(rounded(Number(value)));

    function status(message, kind = '') {
        saveState.textContent = message;
        saveState.className = `trp-save-state${kind ? ` is-${kind}` : ''}`;
    }
    function markDirty() {
        dirty = true;
        status('تغييرات غير محفوظة', 'dirty');
        updatePrintLinks();
    }
    function setBusy(value) {
        busy = value;
        root.setAttribute('aria-busy', String(value));
        if (value) {
            disabledControls = new Map();
            for (const control of $$('button,input,select,textarea')) { disabledControls.set(control, control.disabled); control.disabled = true; }
        } else {
            for (const [control, wasDisabled] of disabledControls) if (control.isConnected) control.disabled = wasDisabled;
            disabledControls.clear();
            updateSendConfirm();
        }
    }
    function showError(error) {
        errorBox.replaceChildren();
        const messages = error.messages || [error.message || 'تعذّر تنفيذ الطلب. تحقق من الاتصال وأعد المحاولة.'];
        const list = text('ul');
        for (const message of messages) list.append(text('li', message));
        errorBox.append(list);
        errorBox.hidden = false;
        status('لم تُحفظ التغييرات. تبقى بياناتك ظاهرة للمراجعة.', 'error');
    }
    async function exclusive(action) {
        if (busy || disposed) return null;
        if (!valid()) return null;
        errorBox.hidden = true;
        setBusy(true);
        try { return await action(); }
        catch (error) { if (!disposed && error?.name !== 'AbortError') showError(error); return null; }
        finally { if (!disposed) setBusy(false); }
    }
    async function request(url, body, method = 'POST') {
        const endpoint = new URL(url, win.location.href);
        if (endpoint.origin !== win.location.origin) throw new Error('تعذّر التحقق من عنوان الطلب.');
        aborter = new AbortController();
        const response = await fetchFn(endpoint.toString(), {method, credentials: 'same-origin', cache: 'no-store', signal: aborter.signal, headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest'}, ...(method === 'GET' ? {} : {body: JSON.stringify(body)})});
        let data;
        try { data = await response.json(); } catch { throw new Error('تعذّر الاتصال. أعد المحاولة مع الاحتفاظ بهذه الصفحة مفتوحة.'); }
        if (!response.ok || response.redirected) {
            const error = new Error(response.status === 409 ? 'عُدّل التقرير من نافذة أخرى. افتح آخر نسخة وراجع الاختلافات قبل الحفظ.' : [401, 419].includes(response.status) ? 'انتهت الجلسة. احتفظ بملاحظاتك ثم أعد تسجيل الدخول.' : data.message || 'تعذّر حفظ التقرير. أعد المحاولة.');
            if (response.status === 422 && data.errors) error.messages = Object.values(data.errors).flat();
            throw error;
        }
        return data;
    }
    function configFromForm() {
        return {
            report_type: activePeriod,
            from: resetPeriodDefaults ? null : field('from').value || null,
            to: resetPeriodDefaults ? null : field('to').value || null,
            subject_ids: $$('[data-trp-subject]:checked').map(input => Number(input.value)),
            categories: resetPeriodDefaults ? null : categorySelection,
            source_keys: sourceSelection,
            thresholds: Object.fromEntries($$('[data-trp-threshold]').map(input => [input.dataset.trpThreshold, finiteNumber(input.value)])),
            aggregation: field('aggregation').value,
        };
    }
    function payload(forPreview = false) {
        const config = configFromForm();
        const restrictToCurrentRoster = forPreview || !builder.report.id || refreshSources;
        const currentStudentIds = builder.catalog.students.map(student => String(student.id));
        return {
            classroom_id: builder.classroom.id,
            title: field('title').value.trim(),
            config,
            entries: builder.entries.filter(entry => config.subject_ids.map(String).includes(String(entry.subject_id)) && (!restrictToCurrentRoster || currentStudentIds.includes(String(entry.student_id)))).map(entry => Object.fromEntries(['student_id', 'subject_id', 'score_mode', 'score', 'rating_mode', 'rating', 'behaviour', 'activity', 'notes'].map(key => [key, entry[key]]))),
            ...(builder.report.id ? {revision: builder.report.revision, refresh_sources: refreshSources} : {}),
        };
    }
    function valid() {
        setThresholdValidity();
        const invalid = $$('input,select,textarea').find(control => !control.disabled && typeof control.checkValidity === 'function' && !control.checkValidity());
        if (invalid) { for (let node = invalid.parentElement; node && node !== root; node = node.parentElement) if (node.tagName === 'DETAILS') node.open = true; invalid.reportValidity(); return false; }
        return true;
    }
    function setThresholdValidity() {
        const thresholds = configFromForm().thresholds;
        const invalid = ratingFor(50, thresholds) === null;
        $$('[data-trp-threshold]').forEach(input => input.setCustomValidity(invalid ? 'يجب أن يكون حد «ممتاز» أعلى من «جيد جدًا»، وحد «جيد جدًا» أعلى من «جيد»، وجميعها بين 0 و100.' : ''));
    }
    function applyConfigControls() {
        activePeriod = builder.config.report_type;
        resetPeriodDefaults = false;
        field('title').value = builder.report.title;
        field('from').value = builder.config.from || '';
        field('to').value = builder.config.to || '';
        field('aggregation').value = builder.config.aggregation;
        $$('[data-trp-subject]').forEach(input => { input.checked = builder.config.subject_ids.map(String).includes(input.value); });
        const categories = builder.config.categories === null ? (activePeriod === 'overall' ? [] : [activePeriod]) : builder.config.categories;
        categorySelection = builder.config.categories === null ? null : [...builder.config.categories];
        $$('[data-trp-category]').forEach(input => { input.checked = categories.includes(input.value); });
        $$('[data-trp-threshold]').forEach(input => { input.value = builder.config.thresholds[input.dataset.trpThreshold]; });
        $$('[data-trp-period]').forEach(tab => { const active = tab.dataset.trpPeriod === activePeriod; tab.classList.toggle('is-active', active); tab.setAttribute('aria-pressed', String(active)); });
        sourceSelection = builder.config.source_keys === null ? null : [...builder.config.source_keys];
    }
    function makeSelect(optionsList, value, entryField, label) {
        const select = text('select');
        select.dataset.trpEntryField = entryField;
        select.setAttribute('aria-label', label);
        for (const [key, name] of optionsList) { const option = text('option', name); option.value = key; option.selected = key === value; select.append(option); }
        return select;
    }
    function resizeTextArea(area) {
        if (area?.tagName !== 'TEXTAREA') return;
        area.style.height = 'auto';
        const contentHeight = area.scrollHeight + 2;
        area.style.height = `${Math.min(160, Math.max(68, contentHeight))}px`;
        area.style.overflowY = contentHeight > 160 ? 'auto' : 'hidden';
    }
    function renderGrid() {
        const body = $('[data-trp-entries]');
        body.replaceChildren();
        for (const entry of builder.entries) {
            const row = text('tr'); row.dataset.trpEntry = entry.key; row.dataset.search = `${entry.student_name} ${entry.subject_name}`;
            const identity = text('td'); identity.dataset.label = 'الطالب والمادة';
            const student = button(entry.student_name, 'data-trp-preview-student', 'trp-button-text trp-student-name'); student.dataset.trpPreviewStudent = entry.student_id;
            identity.append(student, text('span', entry.subject_name, 'trp-subject-name')); row.append(identity);
            const grade = text('td'); grade.dataset.label = 'الدرجة %';
            const control = text('div', undefined, 'trp-grade-control');
            const input = text('input'); input.type = 'number'; input.min = '0'; input.max = '100'; input.step = '0.01'; input.value = entry.score ?? ''; input.dataset.trpEntryField = 'score'; input.setAttribute('aria-label', `درجة ${entry.student_name} في ${entry.subject_name}`);
            const reset = button('↶', 'data-trp-reset-score', 'trp-reset'); reset.title = 'الرجوع إلى الدرجة التلقائية'; reset.setAttribute('aria-label', reset.title); control.append(input, reset);
            const mode = text('span', entry.score_mode === 'manual' ? 'تعديل يدوي' : 'تلقائي من الدرجات المختارة', `trp-mode${entry.score_mode === 'manual' ? ' is-manual' : ''}`); mode.setAttribute('data-trp-score-mode', '');
            const evidence = text('details', undefined, 'trp-evidence'); evidence.append(text('summary', `مصدر الدرجة (${entry.evidence.length})`)); const evidenceList = text('ul');
            for (const item of entry.evidence) evidenceList.append(text('li', `${item.title}: ${numberText(item.score)} / ${numberText(item.maximum)}`));
            evidence.append(evidenceList); grade.append(control, mode, evidence); row.append(grade);
            const rating = text('td'); rating.dataset.label = 'التقدير';
            rating.append(makeSelect([['auto', `تلقائي${entry.rating ? ` — ${reportRatingLabels[entry.rating]}` : ''}`], ['blank', 'دون تقدير'], ...Object.entries(reportRatingLabels)], entry.rating_mode === 'auto' ? 'auto' : entry.rating || 'blank', 'rating', `تقدير ${entry.student_name} في ${entry.subject_name}`));
            const ratingMode = text('span', entry.rating_mode === 'manual' ? 'اختيار يدوي' : 'يتبع الدرجة وحدود التقدير', 'trp-mode'); ratingMode.setAttribute('data-trp-rating-mode', ''); rating.append(ratingMode); row.append(rating);
            const behaviour = text('td'); behaviour.dataset.label = 'السلوك'; behaviour.append(makeSelect([['', 'لم يُحدد'], ['calm', 'هادئ'], ['disruptive', 'مشاغب']], entry.behaviour || '', 'behaviour', `سلوك ${entry.student_name} في ${entry.subject_name}`)); row.append(behaviour);
            for (const [key, label, maximum, placeholder] of [['activity', 'النشاط', 500, 'أضف النشاط يدويًا…'], ['notes', 'ملاحظات', 2000, 'ملاحظاتك للطالب…']]) {
                const cell = text('td'); cell.dataset.label = label; const area = text('textarea'); area.rows = 2; area.maxLength = maximum; area.value = entry[key] || ''; area.placeholder = placeholder; area.dataset.trpEntryField = key; area.setAttribute('aria-label', `${label} — ${entry.student_name} — ${entry.subject_name}`); cell.append(area); row.append(cell);
            }
            body.append(row);
        }
        $('[data-trp-no-students]').hidden = builder.entries.length > 0;
        applySearch();
    }
    function renderSources() {
        const list = $('[data-trp-sources]'); list.replaceChildren();
        for (const source of builder.catalog.sources) {
            const card = text('article', undefined, `trp-source${source.needs_mapping ? ' is-unclassified' : ''}`); card.dataset.trpSourceCard = source.key;
            const checkbox = text('input'); checkbox.type = 'checkbox'; checkbox.dataset.trpSource = ''; checkbox.value = source.key; checkbox.checked = builder.selected_sources.includes(source.key); checkbox.setAttribute('aria-label', `تضمين ${source.title}`);
            const heading = text('div'); heading.append(text('div', source.title, 'trp-source-title'));
            const meta = text('div', undefined, 'trp-source-meta'); [source.kind === 'exam' ? 'اختبار' : 'سجل الدرجات', source.subject_name || 'حدد المادة', source.assessment_date || 'التاريخ غير محدد'].forEach(value => meta.append(text('span', value))); heading.append(meta);
            card.append(checkbox, heading, text('span', `${Object.keys(source.marks || {}).length} درجة`, 'trp-source-count'));
            const mapping = text('div', undefined, 'trp-source-mapping');
            for (const [key, title] of [['subject_id', 'المادة'], ['assessment_type', 'نوع التقييم'], ['assessment_date', 'تاريخ التقييم']]) {
                const label = text('label', undefined, 'trp-field'); label.append(text('span', title));
                let input;
                if (key === 'assessment_date') { input = text('input'); input.type = 'date'; input.value = source[key] || ''; input.disabled = !source.metadata_editable.date; }
                else { input = text('select'); const values = key === 'subject_id' ? [['', 'حدد المادة'], ...builder.catalog.subjects.map(subject => [String(subject.id), subject.name])] : [['', 'غير محدد'], ...Object.entries(categoryLabels)]; for (const [value, name] of values) { const option = text('option', name); option.value = value; option.selected = String(source[key] ?? '') === value; input.append(option); } input.disabled = !source.metadata_editable[key === 'subject_id' ? 'subject' : 'type']; }
                input.dataset.trpMap = key; label.append(input); mapping.append(label);
            }
            card.append(mapping); list.append(card);
        }
        updateSourceEligibility();
    }
    function updateSourceEligibility() {
        const config = configFromForm();
        for (const card of $$('[data-trp-source-card]')) {
            const source = builder.catalog.sources.find(item => item.key === card.dataset.trpSourceCard);
            const input = card.querySelector('[data-trp-source]');
            const eligible = eligibleReportSource(source, config);
            input.disabled = !eligible;
            card.classList.toggle('is-disabled', !eligible);
            if (sourceSelection === null) input.checked = eligible;
            else input.checked = eligible && sourceSelection.includes(source.key);
        }
    }
    function updateSummary() {
        for (const key of ['students', 'subjects', 'assessments']) { const counter = $(`[data-trp-count="${key}"]`); if (counter) counter.textContent = builder.counts[key]; }
        const manualCounter = $('[data-trp-count="manual"]'); if (manualCounter) manualCounter.textContent = builder.entries.filter(entry => entry.score_mode === 'manual' || entry.rating_mode === 'manual').length;
        $('[data-trp-source-count]').textContent = `${builder.counts.assessments} تقييم`;
    }
    function renderWarnings() {
        const box = $('[data-trp-warnings]'); box.replaceChildren();
        for (const warning of builder.warnings) box.append(text('p', warning, 'trp-note'));
        const periodNote = $('[data-trp-period-note]');
        if (periodNote) periodNote.hidden = builder.counts.assessments > 0;
    }
    function applySearch() {
        const query = $('[data-trp-search]').value;
        let visible = 0;
        for (const row of $$('[data-trp-entry]')) { row.hidden = !matchesReportSearch(row.dataset.search, query); if (!row.hidden) { visible++; row.querySelectorAll('textarea').forEach(resizeTextArea); } }
        $('[data-trp-search-empty]').hidden = visible > 0 || !builder.entries.length;
    }
    function previewStudent() { return Number($('[data-trp-student-preview]')?.value); }
    function reportStudents() {
        return Array.from(new Map(builder.entries.map(entry => [String(entry.student_id), {id: entry.student_id, name: entry.student_name}])).values());
    }
    function updatePreviewOptions() {
        const select = $('[data-trp-student-preview]');
        const previous = select.value;
        select.replaceChildren();
        for (const student of reportStudents()) { const option = text('option', student.name); option.value = student.id; select.append(option); }
        if (Array.from(select.options).some(option => option.value === previous)) select.value = previous;
    }
    function renderCard() {
        const selected = previewStudent();
        const paper = $('[data-trp-paper]');
        if (!paper) return;
        const student = reportStudents().find(item => Number(item.id) === selected);
        paper.hidden = !student;
        const empty = $('[data-trp-card-empty]'); if (empty) empty.hidden = !!student;
        $('[data-trp-paper-title]').textContent = field('title').value || builder.report.title;
        $('[data-trp-paper-student]').textContent = student?.name || '';
        $('[data-trp-paper-classroom]').textContent = builder.classroom.label;
        $('[data-trp-paper-period]').textContent = `${periodLabels[builder.config.report_type]}${builder.config.from || builder.config.to ? ` · ${builder.config.from || 'البداية'} — ${builder.config.to || 'النهاية'}` : ''}`;
        const body = $('[data-trp-paper-rows]'), extras = $('[data-trp-paper-extras]'); body.replaceChildren(); extras.replaceChildren();
        for (const entry of builder.entries.filter(item => Number(item.student_id) === selected)) {
            const row = text('tr'); const subject = text('th', entry.subject_name); subject.scope = 'row'; row.append(subject, text('td', numberText(entry.score), 'trp-paper-mark'));
            for (const code of Object.keys(reportRatingLabels)) row.append(text('td', entry.rating === code ? '✓' : '', 'trp-tick'));
            for (const code of ['calm', 'disruptive']) row.append(text('td', entry.behaviour === code ? '✓' : '', 'trp-tick'));
            body.append(row);
            const extra = text('div', undefined, 'trp-paper-extra'); extra.append(text('strong', entry.subject_name));
            for (const [key, label] of [['activity', 'النشاط'], ['notes', 'ملاحظات']]) { const paragraph = text('p'); paragraph.append(text('b', `${label}: `), doc.createTextNode(entry[key] || '—')); extra.append(paragraph); }
            extras.append(extra);
        }
        updatePrintLinks();
    }
    function updatePrintLinks() {
        const base = root.dataset.printUrl;
        for (const [selector, format, single] of [['[data-trp-print]', 'class', false], ['[data-trp-print-cards]', 'cards', false], ['[data-trp-print-student]', 'cards', true]]) {
            const link = $(selector); if (!link) continue;
            link.setAttribute('aria-disabled', 'false');
            link.title = 'تُحفظ التغييرات قبل الطباعة.';
            if (base) { const url = new URL(base, win.location.href); url.searchParams.set('format', format); if (single && previewStudent()) url.searchParams.set('student_id', String(previewStudent())); link.href = url.toString(); }
        }
    }
    function updateLocalEntry(entry, preserveFocusedScore = false) {
        const index = builder.entries.findIndex(item => item.key === entry.key);
        builder.entries[index] = applyEntryModes(entry, builder.config.thresholds);
        const row = $$('[data-trp-entry]').find(item => item.dataset.trpEntry === entry.key);
        if (row) {
            const current = builder.entries[index];
            const scoreInput = row.querySelector('[data-trp-entry-field="score"]');
            if (!preserveFocusedScore || doc.activeElement !== scoreInput) scoreInput.value = current.score ?? '';
            const mode = row.querySelector('[data-trp-score-mode]'); mode.textContent = current.score_mode === 'manual' ? 'تعديل يدوي' : 'تلقائي من الدرجات المختارة'; mode.classList.toggle('is-manual', current.score_mode === 'manual');
            const select = row.querySelector('[data-trp-entry-field="rating"]'); select.options[0].textContent = `تلقائي${current.rating_mode === 'auto' && current.rating ? ` — ${reportRatingLabels[current.rating]}` : ''}`; select.value = current.rating_mode === 'auto' ? 'auto' : current.rating || 'blank';
            row.querySelector('[data-trp-rating-mode]').textContent = current.rating_mode === 'manual' ? 'اختيار يدوي' : 'يتبع الدرجة وحدود التقدير';
        }
        updateSummary(); renderCard();
    }
    function integrate(incoming, preserveIdentity = false) {
        if (!incoming?.catalog || !Array.isArray(incoming.entries) || !incoming.config || !incoming.report) throw new Error('تعذّر قراءة التقرير. لم تُستبدل بياناتك المحفوظة.');
        const identity = preserveIdentity && builder.report.id ? {id: builder.report.id, revision: builder.report.revision, updated_at: builder.report.updated_at} : {};
        builder = clone(incoming); builder.report = {...builder.report, ...identity};
        applyConfigControls(); renderSources(); renderGrid(); renderWarnings(); updateSummary(); updatePreviewOptions(); renderCard();
        pendingFilters = false;
    }
    async function doPreview(force = false) {
        if (!valid()) return null;
        if (metadataDirty) throw new Error('احفظ تنظيم التقييمات أولًا، ثم اعرض الدرجات.');
        if (builder.report.id && !pendingFilters && !force) { renderCard(); return builder; }
        status('جارٍ إعداد الدرجات…');
        const data = await request(root.dataset.previewUrl, payload(true));
        if (disposed) return null;
        integrate(data.builder, true);
        refreshSources = true;
        markDirty();
        return builder;
    }
    async function save({navigate = true} = {}) {
        if (!valid()) return null;
        return exclusive(async () => {
            if (metadataDirty) throw new Error('احفظ تنظيم التقييمات أولًا، ثم احفظ التقرير.');
            if (pendingFilters && !await doPreview(true)) return null;
            status('جارٍ حفظ التقرير…');
            const existing = !!builder.report.id;
            const data = await request(existing ? root.dataset.updateUrl : root.dataset.storeUrl, payload(), existing ? 'PUT' : 'POST');
            if (disposed) return null;
            integrate(data.builder);
            dirty = false; refreshSources = false;
            root.dataset.updateUrl = existing ? root.dataset.updateUrl : new URL(data.redirect_url, win.location.href).pathname;
            root.dataset.printUrl = `${root.dataset.updateUrl.replace(/\/$/, '')}/print`;
            root.dataset.recipientsUrl = `${root.dataset.updateUrl.replace(/\/$/, '')}/recipients`;
            root.dataset.sendPreviewUrl = `${root.dataset.updateUrl.replace(/\/$/, '')}/send-preview`;
            root.dataset.sendUrl = `${root.dataset.updateUrl.replace(/\/$/, '')}/send`;
            $('[data-trp-context-state]').textContent = 'تقرير محفوظ';
            status('تم الحفظ', 'saved'); updatePrintLinks();
            if (!existing && data.redirect_url) { if (navigate) options.navigate ? options.navigate(data.redirect_url) : win.location.assign(data.redirect_url); else win.history?.replaceState?.({}, '', data.redirect_url); }
            return builder;
        });
    }
    async function ensureSaved() {
        if (busy || disposed) return null;
        return !builder.report.id || dirty || metadataDirty ? save({navigate: false}) : builder;
    }
    async function printReport(format = 'class', single = false) {
        if (!await ensureSaved()) return;
        const url = new URL(root.dataset.printUrl, win.location.href);
        url.searchParams.set('format', format);
        if (single && previewStudent()) url.searchParams.set('student_id', String(previewStudent()));
        options.navigate ? options.navigate(url.toString()) : win.location.assign(url.toString());
    }
    function sendSelection() {
        return {
            revision: builder.report.revision,
            audiences: $$('[data-trp-audience]:checked').map(input => input.value).sort(),
            student_ids: (share.scope === 'all' ? share.students.map(student => Number(student.id)) : [...share.selected]).sort((left, right) => left - right),
        };
    }
    function sendSelectionKey() { return JSON.stringify(sendSelection()); }
    function updateSendConfirm() {
        const confirm = $('[data-trp-send-confirm]');
        if (confirm) confirm.disabled = busy || !share.counts?.total || share.key !== sendSelectionKey() || share.sentKey === share.key;
    }
    function showSendError(error) {
        const box = $('[data-trp-send-error]');
        box.textContent = (error.messages || [error.message || 'تعذّر الإرسال. أعد المحاولة.']).join(' ');
        box.hidden = false;
    }
    function renderSendStudents() {
        const list = $('[data-trp-send-student-list]'); list.replaceChildren();
        for (const student of share.students) {
            const label = text('label'); label.dataset.trpSendStudentRow = ''; label.dataset.search = student.name;
            const input = text('input'); input.type = 'checkbox'; input.value = student.id; input.dataset.trpSendStudent = ''; input.checked = share.selected.has(Number(student.id));
            label.append(input, text('span', student.name)); list.append(label);
        }
        filterSendStudents();
        $('[data-trp-send-selected-count]').textContent = `${share.selected.size} محدد`;
    }
    function filterSendStudents() {
        const query = $('[data-trp-send-search]').value;
        let visible = 0;
        for (const row of $$('[data-trp-send-student-row]')) { row.hidden = !matchesReportSearch(row.dataset.search, query); if (!row.hidden) visible++; }
        $('[data-trp-send-search-empty]').hidden = visible > 0;
    }
    function invalidateSendPreview() {
        clearTimeout(sharePreviewTimer); sharePreviewSequence++;
        const nextKey = sendSelectionKey();
        if (nextKey !== share.selectionKey) { share.requestId = null; share.sentKey = null; }
        share.selectionKey = nextKey; share.counts = null; share.key = null;
        $('[data-trp-send-error]').hidden = true;
        $('[data-trp-send-unlinked]').hidden = true;
        const summary = $('[data-trp-send-summary]'); summary.classList.remove('is-success');
        const selection = sendSelection();
        if (!selection.audiences.length) summary.textContent = 'اختر من يستلم التقرير.';
        else if (!selection.student_ids.length) summary.textContent = 'اختر طالبًا واحدًا على الأقل.';
        else { summary.textContent = 'جارٍ حساب المستلمين…'; sharePreviewTimer = setTimeout(() => { void previewSend(); }, 180); }
        updateSendConfirm();
    }
    async function previewSend() {
        const selection = sendSelection();
        if (!selection.audiences.length || !selection.student_ids.length || disposed) return;
        const sequence = ++sharePreviewSequence, key = JSON.stringify(selection);
        try {
            const result = await request(root.dataset.sendPreviewUrl, selection);
            if (disposed || sequence !== sharePreviewSequence || key !== sendSelectionKey()) return;
            share.counts = result.counts; share.key = key;
            $('[data-trp-send-summary]').textContent = `الطلاب ${result.counts.student}، أولياء الأمور ${result.counts.parent}، الإدارة ${result.counts.admin}`;
            const unlinked = $('[data-trp-send-unlinked]');
            unlinked.hidden = !selection.audiences.includes('parent') || !result.unlinked_students?.length;
            if (!unlinked.hidden) unlinked.textContent = `${result.unlinked_students.length} طلاب دون ولي أمر مرتبط؛ تُتخطى نسخة ولي الأمر لهم.`;
        } catch (error) {
            if (disposed || sequence !== sharePreviewSequence) return;
            $('[data-trp-send-summary]').textContent = 'تعذّر حساب المستلمين.';
            showSendError(error);
        }
        updateSendConfirm();
    }
    async function openSendDialog() {
        if (!await ensureSaved()) return;
        const loaded = await exclusive(async () => {
            const result = await request(root.dataset.recipientsUrl, undefined, 'GET');
            if (!Array.isArray(result.students)) throw new Error('تعذّر قراءة قائمة المستلمين.');
            const firstOpen = share.reportId !== builder.report.id;
            const students = result.students.map(student => ({...student, id: Number(student.id)}));
            share.students = students;
            if (firstOpen) {
                share.reportId = builder.report.id; share.scope = 'all'; share.selected = new Set(students.map(student => student.id)); share.specificInitialized = false; share.selectionKey = null;
                $$('[data-trp-audience]').forEach(input => { input.checked = false; });
                $$('[data-trp-send-scope]').forEach(input => { input.checked = input.value === 'all'; });
            } else share.selected = new Set([...share.selected].filter(id => students.some(student => student.id === id)));
            $('[data-trp-send-students]').hidden = share.scope === 'all';
            renderSendStudents(); invalidateSendPreview();
            return true;
        });
        if (loaded && !disposed) { $('[data-trp-send-dialog]').showModal(); $('[data-trp-audience]')?.focus(); }
    }
    async function sendReport() {
        if (busy || !share.counts?.total || share.key !== sendSelectionKey() || share.sentKey === share.key) return null;
        share.requestId ||= win.crypto?.randomUUID?.() || globalThis.crypto?.randomUUID?.() || '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, character => (Number(character) ^ Math.random() * 16 >> Number(character) / 4).toString(16));
        return exclusive(async () => {
            $('[data-trp-send-error]').hidden = true;
            try {
                const result = await request(root.dataset.sendUrl, {...sendSelection(), request_id: share.requestId});
                if (disposed) return null;
                share.sentKey = share.key;
                const summary = $('[data-trp-send-summary]'); summary.textContent = result.message || 'تم إرسال التقرير.'; summary.classList.add('is-success');
                status('تم إرسال التقرير', 'saved');
                return result;
            } catch (error) { if (!disposed) showSendError(error); return null; }
            finally { updateSendConfirm(); }
        });
    }
    async function saveMetadata() {
        return exclusive(async () => {
            const changes = [];
            for (const card of $$('[data-trp-source-card]')) {
                const source = builder.catalog.sources.find(item => item.key === card.dataset.trpSourceCard);
                const row = {key: source.key};
                for (const input of card.querySelectorAll('[data-trp-map]')) row[input.dataset.trpMap] = input.dataset.trpMap === 'subject_id' ? (input.value ? Number(input.value) : null) : input.value || null;
                if (['subject_id', 'assessment_type', 'assessment_date'].some(key => String(row[key] ?? '') !== String(source[key] ?? ''))) changes.push(row);
            }
            if (!changes.length) { $('[data-trp-metadata-status]').textContent = 'لا توجد تغييرات في تنظيم التقييمات.'; return null; }
            $('[data-trp-metadata-status]').textContent = 'جارٍ حفظ التنظيم…';
            await request(root.dataset.sourcesUrl, {classroom_id: builder.classroom.id, sources: changes});
            metadataDirty = false; sourceSelection = null; pendingFilters = true;
            await doPreview(true);
            $('[data-trp-metadata-status]').textContent = 'تم حفظ تنظيم التقييمات وتحديث الدرجات المعروضة.';
            return builder;
        });
    }
    function onInput(event) {
        if (busy) return;
        const target = event.target;
        if (target.matches('[data-trp-send-search]')) { filterSendStudents(); return; }
        if (target.matches('[data-trp-audience]')) { invalidateSendPreview(); return; }
        if (target.matches('[data-trp-send-scope]')) {
            share.scope = target.value;
            if (share.scope === 'selected' && !share.specificInitialized) { share.selected.clear(); share.specificInitialized = true; }
            $('[data-trp-send-students]').hidden = share.scope === 'all';
            renderSendStudents(); invalidateSendPreview(); return;
        }
        if (target.matches('[data-trp-send-student]')) {
            const id = Number(target.value); target.checked ? share.selected.add(id) : share.selected.delete(id);
            $('[data-trp-send-selected-count]').textContent = `${share.selected.size} محدد`;
            invalidateSendPreview(); return;
        }
        if (target.matches('[data-trp-search]')) { applySearch(); return; }
        if (target.matches('[data-trp-map]')) { metadataDirty = true; $('[data-trp-metadata-status]').textContent = 'تعديلات التنظيم غير محفوظة.'; markDirty(); return; }
        const row = target.closest('[data-trp-entry]');
        if (row && target.dataset.trpEntryField) {
            const entry = builder.entries.find(item => item.key === row.dataset.trpEntry); if (!entry) return;
            const key = target.dataset.trpEntryField;
            if (key === 'score') { entry.score_mode = 'manual'; entry.score = target.value === '' ? null : finiteNumber(target.value); }
            else if (key === 'rating') { entry.rating_mode = target.value === 'auto' ? 'auto' : 'manual'; entry.rating = Object.hasOwn(reportRatingLabels, target.value) ? target.value : null; }
            else entry[key] = target.value || (key === 'behaviour' ? null : '');
            updateLocalEntry(entry, true); resizeTextArea(target); markDirty(); return;
        }
        if (target.matches('[data-trp-threshold]') || target.dataset.trpConfigField === 'aggregation') {
            const config = configFromForm(); builder.config.thresholds = config.thresholds; builder.config.aggregation = config.aggregation;
            builder.entries = builder.entries.map(entry => applyEntryModes({...entry, automatic_score: reportPercentage(entry.evidence, config.aggregation)}, config.thresholds));
            setThresholdValidity(); renderGrid(); renderCard(); updateSummary(); markDirty(); return;
        }
        if (target.dataset.trpConfigField === 'title') { builder.report.title = target.value; renderCard(); markDirty(); return; }
        if (target.matches('[data-trp-source]')) { sourceSelection = $$('[data-trp-source]:checked').map(input => input.value); pendingFilters = true; markDirty(); return; }
        if (target.matches('[data-trp-subject],[data-trp-category]') || ['from', 'to'].includes(target.dataset.trpConfigField)) {
            if (target.matches('[data-trp-category]')) categorySelection = $$('[data-trp-category]:checked').map(input => input.value);
            resetPeriodDefaults = false; sourceSelection = null; pendingFilters = true; updateSourceEligibility(); markDirty();
        }
    }
    function onClick(event) {
        const control = event.target.closest('button,a'); if (!control || !root.contains(control)) return;
        if (control.matches('[data-trp-send-close]')) { $('[data-trp-send-dialog]').close(); return; }
        if (control.matches('[data-trp-print],[data-trp-print-cards],[data-trp-print-student]')) { event.preventDefault(); if (!busy) void printReport(control.matches('[data-trp-print]') ? 'class' : 'cards', control.matches('[data-trp-print-student]')); return; }
        if (busy) return;
        if (control.dataset.trpPeriod) {
            activePeriod = control.dataset.trpPeriod; resetPeriodDefaults = true; sourceSelection = null; pendingFilters = true; markDirty(); void exclusive(() => doPreview(true));
        } else if (control.matches('[data-trp-save]')) { void save(); }
        else if (control.matches('[data-trp-print-now]')) { void printReport(); }
        else if (control.matches('[data-trp-send-open]')) { void openSendDialog(); }
        else if (control.matches('[data-trp-send-confirm]')) { void sendReport(); }
        else if (control.matches('[data-trp-send-select-all]')) { share.selected = new Set(share.students.map(student => student.id)); renderSendStudents(); invalidateSendPreview(); }
        else if (control.matches('[data-trp-send-clear]')) { share.selected.clear(); renderSendStudents(); invalidateSendPreview(); }
        else if (control.matches('[data-trp-refresh]')) { void exclusive(() => doPreview(true)); }
        else if (control.matches('[data-trp-save-metadata]')) { void saveMetadata(); }
        else if (control.matches('[data-trp-apply-sources]')) { void exclusive(() => doPreview(true)); }
        else if (control.matches('[data-trp-select-sources]')) { sourceSelection = null; pendingFilters = true; updateSourceEligibility(); markDirty(); }
        else if (control.matches('[data-trp-clear-sources]')) { sourceSelection = []; pendingFilters = true; updateSourceEligibility(); markDirty(); }
        else if (control.matches('[data-trp-reset-score]')) { const entry = builder.entries.find(item => item.key === control.closest('[data-trp-entry]').dataset.trpEntry); entry.score_mode = 'auto'; updateLocalEntry(entry); markDirty(); }
        else if (control.dataset.trpPreviewStudent) { $('[data-trp-student-preview]').value = control.dataset.trpPreviewStudent; $('[data-trp-preview-panel]').open = true; renderCard(); $('[data-trp-card-container]').scrollIntoView({behavior: win.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start'}); }
    }
    function onChange(event) { if (event.target.matches('[data-trp-student-preview]')) renderCard(); else if (event.target.tagName === 'SELECT' || ['checkbox', 'date'].includes(event.target.type)) onInput(event); }
    function onSubmit(event) { if (event.target === form) { event.preventDefault(); void exclusive(() => doPreview()); } }
    function beforeUnload(event) { if (dirty || metadataDirty) { event.preventDefault(); event.returnValue = ''; } }
    function dispose() { if (disposed) return; disposed = true; clearTimeout(sharePreviewTimer); sharePreviewSequence++; aborter?.abort(); root.removeEventListener('input', onInput); root.removeEventListener('change', onChange); root.removeEventListener('click', onClick); form.removeEventListener('submit', onSubmit); win.removeEventListener('beforeunload', beforeUnload); win.removeEventListener('pagehide', onPageHide); }
    function onPageHide(event) { if (!event.persisted) dispose(); }
    root.addEventListener('input', onInput); root.addEventListener('change', onChange); root.addEventListener('click', onClick); form.addEventListener('submit', onSubmit); win.addEventListener('beforeunload', beforeUnload); win.addEventListener('pagehide', onPageHide);
    updateSourceEligibility(); updatePrintLinks(); $$('[data-trp-entry] textarea').forEach(resizeTextArea);
    return {save, preview: () => exclusive(() => doPreview(true)), dispose, getState: () => clone(builder)};
}

if (typeof document !== 'undefined') initTeacherReports(document);
