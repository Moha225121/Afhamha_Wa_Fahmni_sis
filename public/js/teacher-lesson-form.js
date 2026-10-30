(function () {
    'use strict';
    const form = document.getElementById('teacher-lesson-form');
    if (!form) return;

    const grade = form.querySelector('#lesson-grade');
    const subject = form.querySelector('#lesson-subject');
    const subjectOptions = Array.from(subject.options).filter(option => option.value !== '');
    const rooms = Array.from(form.querySelectorAll('input[name="classroom_ids[]"]'));
    const branchScope = form.querySelector('input[name="branch_scope"]');
    const allBranches = form.querySelector('#lesson-classrooms-all');
    const branchFieldset = form.querySelector('.lesson-branches-fieldset');
    const branchHelp = form.querySelector('#lesson-branch-help');
    const audienceError = form.querySelector('#lesson-audience-error');
    const editor = form.querySelector('.rich-editor');
    const content = form.querySelector('textarea[name="content"]');
    const publishModes = Array.from(form.querySelectorAll('input[name="publish_mode"]'));
    const schedule = form.querySelector('#lesson-schedule');
    const publishedAt = form.querySelector('#lesson-published-at');
    const publishButton = form.querySelector('#lesson-publish-button');
    const submitSummary = form.querySelector('#lesson-submit-summary');
    const fileInput = form.querySelector('#lesson-attachments');
    const fileSummary = form.querySelector('#lesson-file-summary');
    const uploadedFiles = form.querySelector('#lesson-uploaded-files');
    const attachmentTokens = form.querySelector('#lesson-attachment-tokens');
    let uploadCount = 0;
    let uploadFailed = false;
    const originalSelected = new Set(rooms.filter(room => room.dataset.existingRecipient === '1').map(room => room.value));
    let preserveMixedAudience = form.dataset.existingMixed === '1' && grade.value === '';
    let submitting = false;

    function assignedToSubject(room, subjectId) {
        return (room.dataset.subjects || '').split(',').includes(subjectId);
    }

    function eligibleRooms() {
        return rooms.filter(room => !room.disabled);
    }

    function hasBranchChoices() {
        return preserveMixedAudience || eligibleRooms().some(room => (room.dataset.branch || '').trim() !== '');
    }

    function clearError() {
        audienceError.hidden = true;
        audienceError.textContent = '';
    }

    function syncSubjectOptions() {
        const selectedGrade = grade.options[grade.selectedIndex];
        const allowed = preserveMixedAudience
            ? [form.dataset.existingSubject]
            : (selectedGrade?.dataset.subjects || '').split(',').filter(Boolean);
        subjectOptions.forEach(option => {
            option.hidden = !allowed.includes(option.value);
            option.disabled = option.hidden;
        });
        if (!allowed.includes(subject.value)) subject.value = '';
        const available = subjectOptions.filter(option => !option.disabled);
        if (available.length === 1 && subject.value === '') subject.value = available[0].value;
        subject.disabled = !grade.value && !preserveMixedAudience;
    }

    function syncRooms() {
        rooms.forEach(room => {
            const visible = subject.value !== '' && assignedToSubject(room, subject.value)
                && (preserveMixedAudience ? originalSelected.has(room.value) : room.dataset.grade === grade.value);
            room.closest('[data-classroom-option]').hidden = !visible;
            room.closest('[data-classroom-option]').querySelector('.lesson-branch-grade').hidden = !preserveMixedAudience;
            room.disabled = !visible;
            if (!visible) room.checked = false;
        });
        const available = eligibleRooms();
        branchFieldset.hidden = !hasBranchChoices();
        allBranches.disabled = preserveMixedAudience || branchFieldset.hidden || available.length === 0;
        if (preserveMixedAudience) branchScope.value = 'selected';
        if (branchScope.value === 'all' && !preserveMixedAudience) available.forEach(room => { room.checked = true; });
        if (branchFieldset.hidden && available.length > 0 && !available.some(room => room.checked)) {
            available.forEach(room => { room.checked = true; });
        }
        syncSummary();
    }

    function syncSummary() {
        const available = eligibleRooms();
        const selected = available.filter(room => room.checked);
        const everyBranchSelected = available.length > 0 && selected.length === available.length;
        allBranches.checked = !preserveMixedAudience && everyBranchSelected;
        allBranches.indeterminate = !preserveMixedAudience && selected.length > 0 && !everyBranchSelected;
        branchScope.value = allBranches.checked ? 'all' : 'selected';
        const subjectLabel = subject.value ? subject.options[subject.selectedIndex].textContent.split(' — ')[0] : '';
        const gradeLabel = preserveMixedAudience ? 'الصفوف الحالية' : grade.options[grade.selectedIndex]?.textContent.split(' — ')[0];
        if (!grade.value && !preserveMixedAudience) {
            branchHelp.textContent = 'اختر الصف، ثم المادة لعرض الشعب المتاحة.';
            submitSummary.textContent = 'حدد الصف والمادة قبل الحفظ.';
        } else if (!subject.value) {
            branchHelp.textContent = 'اختر المادة لعرض شعبها المسندة إليك.';
            submitSummary.textContent = 'اختر المادة قبل الحفظ.';
        } else if (available.length === 0) {
            branchHelp.textContent = 'لا توجد شعب مسندة إليك لهذه المادة في الصف المختار.';
            submitSummary.textContent = 'اختر صفًا ومادة لهما إسناد.';
        } else if (!hasBranchChoices()) {
            branchHelp.textContent = '';
            submitSummary.textContent = gradeLabel + ' · ' + subjectLabel;
        } else {
            branchHelp.textContent = 'المحدد: ' + selected.length + ' من ' + available.length + ' شعب مسندة إليك.';
            submitSummary.textContent = gradeLabel + ' · ' + subjectLabel + ' · ' + selected.length + ' شعب';
        }
    }

    function syncPublication() {
        const mode = publishModes.find(option => option.checked)?.value || 'now';
        const scheduled = mode === 'scheduled';
        schedule.hidden = !scheduled;
        publishedAt.disabled = !scheduled;
        publishedAt.required = scheduled;
        publishButton.textContent = scheduled ? 'حفظ وجدولة الدرس' : (form.dataset.isEdit === '1' ? 'حفظ التعديلات' : 'نشر الدرس الآن');
    }

    grade.addEventListener('change', () => {
        preserveMixedAudience = form.dataset.existingMixed === '1' && grade.value === '';
        rooms.forEach(room => { room.checked = preserveMixedAudience && originalSelected.has(room.value); });
        branchScope.value = preserveMixedAudience ? 'selected' : 'all';
        clearError();
        syncSubjectOptions();
        syncRooms();
    });
    subject.addEventListener('change', () => {
        rooms.forEach(room => { room.checked = false; });
        branchScope.value = preserveMixedAudience ? 'selected' : 'all';
        clearError();
        syncRooms();
    });
    allBranches.addEventListener('change', () => {
        clearError();
        eligibleRooms().forEach(room => { room.checked = allBranches.checked; });
        syncSummary();
    });
    rooms.forEach(room => room.addEventListener('change', () => {
        clearError();
        syncSummary();
    }));
    publishModes.forEach(option => option.addEventListener('change', syncPublication));
    form.querySelector('button[value="draft"]')?.addEventListener('click', () => { publishedAt.required = false; });
    publishButton.addEventListener('click', syncPublication);

    editor.innerHTML = content.value || '';
    editor.addEventListener('input', () => { content.value = editor.innerHTML; });
    form.querySelectorAll('.rich-toolbar button').forEach(button => button.addEventListener('click', () => {
        editor.focus();
        document.execCommand(button.dataset.command, false);
        content.value = editor.innerHTML;
    }));
    const uploads = new Set();
    function maximumFor(file) {
        const maximum = Number(form.dataset.uploadMax);
        return /\.mp4$/i.test(file.name) ? Math.min(maximum, Number(form.dataset.uploadVideoMax || maximum)) : maximum;
    }
    function sizeLimitMessage() {
        return 'الحد 10 ملفات، و' + (Number(form.dataset.uploadVideoMax || form.dataset.uploadMax) / 1048576) +
            ' MiB لكل فيديو، و' + (Number(form.dataset.uploadMax) / 1048576) + ' MiB لبقية الملفات، ومجموع 1100 MiB.';
    }
    let uploadQueue = Promise.resolve();
    function syncUploadState() {
        uploadCount = Array.from(uploads).filter(item => item.busy).length;
        uploadFailed = Array.from(uploads).some(item => !item.ready);
    }
    function queueUpload(item) {
        item.busy = true; item.retry.disabled = true; item.pause.disabled = true; syncUploadState();
        uploadQueue = uploadQueue.then(async () => {
            if (!uploads.has(item) || !item.busy) return;
            item.pause.disabled = false;
            item.status.textContent = 'جارٍ الرفع…';
            try {
                const result = await item.client.run();
                if (!uploads.has(item)) return;
                item.ready = true; item.card.classList.remove('is-error');
                item.status.textContent = '100% — ' + (item.file.size / 1048576).toFixed(1) + ' MiB — اكتمل الرفع والتحقق'; item.bar.style.width = '100%'; item.pause.hidden = true;
                const input = document.createElement('input'); input.type = 'hidden'; input.name = 'upload_ids[]'; input.value = result.upload_id;
                item.token = input; attachmentTokens.append(input); item.retry.hidden = true;
            } catch (error) {
                if (uploads.has(item)) { item.card.classList.add('is-error'); item.status.textContent = error.message; }
            } finally {
                item.busy = false; item.retry.disabled = false; item.pause.disabled = true; syncUploadState();
            }
        });
    }
    fileInput.addEventListener('change', () => {
        const files = Array.from(fileInput.files || []); fileInput.value = '';
        if (uploads.size + files.length > 10 || files.some(file => file.size < 1 || file.size > maximumFor(file))
            || Array.from(uploads).reduce((n, item) => n + item.file.size, 0) + files.reduce((n, file) => n + file.size, 0) > Number(form.dataset.uploadTotal)) {
            window.alert(sizeLimitMessage()); return;
        }
        files.forEach(file => {
            const card = document.createElement('div'); card.className = 'lesson-upload-card';
            const preview = document.createElement('div'); preview.className = 'lesson-upload-preview'; const url = URL.createObjectURL(file);
            if (file.type.startsWith('image/')) { const el = document.createElement('img'); el.src = url; el.alt = file.name; preview.append(el); }
            else if (file.type.startsWith('video/')) { const el = document.createElement('video'); el.src = url; el.controls = true; el.preload = 'metadata'; preview.append(el); } else preview.textContent = 'ملف';
            const details = document.createElement('div'); const title = document.createElement('strong'); title.className = 'lesson-upload-name'; title.textContent = file.name;
            const status = document.createElement('span'); status.className = 'lesson-upload-status'; status.setAttribute('role', 'status');
            const progress = document.createElement('div'); progress.className = 'lesson-upload-progress'; progress.setAttribute('role', 'progressbar'); progress.setAttribute('aria-label', 'رفع ' + file.name); progress.setAttribute('aria-valuemin', '0'); progress.setAttribute('aria-valuemax', '100');
            const bar = document.createElement('i'); progress.append(bar);
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'lesson-upload-remove'; remove.textContent = 'إزالة';
            const retry = document.createElement('button'); retry.type = 'button'; retry.className = 'lesson-upload-action'; retry.textContent = 'استئناف';
            const pause = document.createElement('button'); pause.type = 'button'; pause.className = 'lesson-upload-action'; pause.textContent = 'إيقاف مؤقت';
            details.append(title, status, progress, pause, retry, remove); card.append(preview, details); uploadedFiles.append(card);
            const item = { file, card, status, bar, retry, pause, ready: false, busy: false };
            item.client = new window.LessonResumableUpload({ file, base: form.dataset.uploadBase, user: form.dataset.uploadUser,
                csrf: form.querySelector('[name="_token"]').value,
                onStatus: message => { status.textContent = message; },
                onProgress: (bytes, total) => {
                    const pct = Math.min(100, Math.floor(bytes / total * 100)); bar.style.width = pct + '%'; progress.setAttribute('aria-valuenow', String(pct));
                    status.textContent = pct + '% — ' + (bytes / 1048576).toFixed(1) + ' / ' + (total / 1048576).toFixed(1) + ' MiB';
                }
            });
            uploads.add(item);
            retry.addEventListener('click', () => { if (!item.busy) queueUpload(item); });
            pause.addEventListener('click', () => {
                item.client.pause();
            });
            remove.addEventListener('click', async () => {
                if (remove.disabled) return; remove.disabled = true;
                try { await item.client.cancel(); item.token?.remove(); uploads.delete(item); card.remove(); URL.revokeObjectURL(url); syncUploadState(); }
                catch (error) { status.textContent = error.message; remove.disabled = false; }
            });
            queueUpload(item);
        });
        fileSummary.textContent = 'يُرفع ملف واحد في كل مرة. عند الانقطاع اضغط استئناف، أو اختر الملف الأصلي بعد تحديث الصفحة.';
    });
    window.addEventListener('beforeunload', event => {
        if (Array.from(uploads).some(item => !item.ready)) { event.preventDefault(); event.returnValue = ''; }
    });
    form.addEventListener('submit', event => {
        content.value = editor.innerHTML;
        if (submitting) { event.preventDefault(); return; }
        if (uploadCount > 0 || uploadFailed) { event.preventDefault(); fileSummary.textContent = uploadCount > 0 ? 'انتظر حتى يكتمل رفع المرفقات.' : 'أعد اختيار الملفات التي تعذر رفعها قبل الحفظ.'; return; }
        const selected = eligibleRooms().filter(room => room.checked);
        if (selected.length === 0) {
            event.preventDefault();
            audienceError.textContent = hasBranchChoices() ? 'اختر شعبة واحدة على الأقل لهذا الدرس.' : 'اختر صفًا ومادة مسندين إليك لهذا الدرس.';
            audienceError.hidden = false;
            (branchFieldset.hidden ? subject.nextElementSibling?.querySelector('button') : eligibleRooms()[0] || allBranches)?.focus();
            return;
        }
        const files = Array.from(fileInput.files || []);
        const invalidFile = files.find(file => file.size > maximumFor(file));
        if (files.length > 10 || invalidFile || files.reduce((total, file) => total + file.size, 0) > 1100 * 1024 * 1024) {
            event.preventDefault();
            window.alert(files.length > 10 ? 'يمكن إرفاق 10 ملفات كحد أقصى.' : (invalidFile ? sizeLimitMessage() : 'يجب ألا يتجاوز مجموع المرفقات 1100 ميبيبايت.'));
            return;
        }
        if (event.submitter?.value === 'draft') {
            publishedAt.required = false;
        }
        submitting = true;
        if (event.submitter?.name) {
            const status = document.createElement('input');
            status.type = 'hidden';
            status.name = event.submitter.name;
            status.value = event.submitter.value;
            form.appendChild(status);
        }
        form.querySelectorAll('button[type="submit"]').forEach(button => {
            button.disabled = true;
            button.textContent = 'جارٍ الحفظ…';
        });
    });

    syncSubjectOptions();
    syncRooms();
    syncPublication();

    function labelSelectButtons() {
        [grade, subject].forEach(select => {
            const button = select.nextElementSibling?.querySelector('.select-ui__button');
            if (!button) return;
            button.id = select.id + '-button';
            button.setAttribute('aria-labelledby', select.id + '-label ' + button.id);
            select.closest('label').htmlFor = button.id;
            select.tabIndex = -1;
            select.setAttribute('aria-hidden', 'true');
            select.addEventListener('invalid', () => button.focus());
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', labelSelectButtons);
    else labelSelectButtons();
}());
