(() => {
    const panel = document.getElementById('library-upload');
    document.querySelectorAll('[data-library-open-upload]').forEach(link => {
        link.addEventListener('click', () => {
            if (!panel) return;
            panel.open = true;
            document.getElementById('library-file')?.focus({ preventScroll: true });
        });
    });

    const form = document.querySelector('[data-library-upload]');
    if (!form) return;

    const fileInput = form.querySelector('input[type="file"]');
    const submit = form.querySelector('[data-library-submit]');
    const cancel = form.querySelector('[data-library-cancel]');
    const progress = form.querySelector('[data-library-progress]');
    const meter = progress.querySelector('progress');
    const progressLabel = form.querySelector('[data-library-progress-label]');
    const progressValue = form.querySelector('[data-library-progress-value]');
    const progressDetail = form.querySelector('[data-library-progress-detail]');
    const errorBox = form.querySelector('[data-library-upload-error]');
    const bookType = form.querySelector('[data-library-book-type]');
    const audience = form.querySelector('[data-library-audience]');
    const csrf = form.querySelector('[name="_token"]').value;
    let active = null;

    function syncAudience() {
        const teachersOnly = ['teacher_guide', 'assessment'].includes(bookType.value);
        if (teachersOnly) audience.value = 'teachers';
        audience.querySelector('option[value="all"]').disabled = teachersOnly;
    }
    bookType.addEventListener('change', syncAudience);
    syncAudience();

    function showError(message) {
        errorBox.textContent = message;
        errorBox.hidden = false;
    }

    function formatSize(bytes) {
        if (bytes < 1048576) return `${Math.max(1, Math.ceil(bytes / 1024))} KB`;
        return `${(bytes / 1048576).toFixed(1)} MB`;
    }

    async function request(url, options, signal) {
        let response;
        try {
            response = await fetch(url, {
                credentials: 'same-origin',
                ...options,
                signal,
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...options.headers },
            });
        } catch (error) {
            if (error.name === 'AbortError') throw error;
            const unavailable = new Error('تعذّر الاتصال بالخادم. تحقق من الاتصال وحاول مجددًا.');
            unavailable.retryable = true;
            throw unavailable;
        }
        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            let message = Object.values(body.errors || {}).flat().join(' ') || body.message || 'تعذّر رفع الملف. حاول مجددًا.';
            if (response.status === 419 || response.status === 401) message = 'انتهت جلسة الدخول. حدّث الصفحة ثم أعد المحاولة.';
            if (response.status === 413) message = 'رفض الخادم حجم جزء الرفع. راجع إعدادات الرفع لدى مسؤول النظام.';
            const error = new Error(message);
            error.retryable = response.status >= 500 || response.status === 429;
            throw error;
        }
        return body;
    }

    async function removeUpload(uploadId) {
        if (!uploadId) return;
        try {
            await request(form.dataset.cancelUrl.replace('__UPLOAD__', encodeURIComponent(uploadId)), { method: 'DELETE' });
        } catch (_) {
            // Temporary uploads also expire on the server if cleanup cannot reach it.
        }
    }

    cancel.addEventListener('click', () => active?.controller.abort());
    window.addEventListener('beforeunload', event => {
        if (!active || active.committing) return;
        event.preventDefault();
        event.returnValue = '';
    });

    form.addEventListener('submit', async event => {
        if (!window.fetch || !window.AbortController || !window.FormData) return;
        event.preventDefault();
        if (active || !form.reportValidity()) return;
        const file = fileInput.files[0];
        errorBox.hidden = true;
        if (!file || file.size === 0) {
            showError('اختر ملفًا غير فارغ قبل بدء الرفع.');
            fileInput.focus();
            return;
        }

        const task = { controller: new AbortController(), uploadId: null, committing: false };
        active = task;
        submit.disabled = true;
        fileInput.disabled = true;
        cancel.hidden = false;
        progress.hidden = false;
        meter.value = 0;
        progressValue.textContent = '0%';
        progressLabel.textContent = 'جارٍ تجهيز الرفع…';
        progressDetail.textContent = `${file.name} · ${formatSize(file.size)}`;

        try {
            const started = await request(form.dataset.startUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: file.name, size: file.size }),
            }, task.controller.signal);
            task.uploadId = started.upload_id;
            const chunkSize = Number(started.chunk_size);
            if (!task.uploadId || !Number.isSafeInteger(chunkSize) || chunkSize <= 0) throw new Error('تعذّر بدء الرفع. حاول مجددًا.');
            const chunkCount = Math.ceil(file.size / chunkSize);
            const chunkUrl = form.dataset.chunkUrl.replace('__UPLOAD__', encodeURIComponent(task.uploadId));

            for (let index = 0; index < chunkCount; index++) {
                task.controller.signal.throwIfAborted();
                progressLabel.textContent = `جارٍ رفع الملف — الجزء ${index + 1} من ${chunkCount}`;
                const body = new FormData();
                body.append('index', String(index));
                body.append('chunk', file.slice(index * chunkSize, Math.min(file.size, (index + 1) * chunkSize)), 'chunk.bin');
                let completed;
                for (let attempt = 0; attempt < 3; attempt++) {
                    try {
                        completed = await request(chunkUrl, { method: 'POST', body }, task.controller.signal);
                        break;
                    } catch (error) {
                        if (!error.retryable || attempt === 2 || task.controller.signal.aborted) throw error;
                        progressLabel.textContent = 'انقطع الاتصال مؤقتًا. جارٍ إعادة محاولة الجزء الحالي…';
                        await new Promise(resolve => setTimeout(resolve, 700 * (attempt + 1)));
                    }
                }
                const received = Math.min(file.size, Number(completed.received_bytes) || (index + 1) * chunkSize);
                const percentage = Math.min(100, Math.floor(received / file.size * 100));
                meter.value = percentage;
                progressValue.textContent = `${percentage}%`;
                progressDetail.textContent = `${formatSize(received)} / ${formatSize(file.size)}`;
            }

            task.controller.signal.throwIfAborted();
            const uploadId = document.createElement('input');
            uploadId.type = 'hidden';
            uploadId.name = 'upload_id';
            uploadId.value = task.uploadId;
            form.append(uploadId);
            progressLabel.textContent = 'اكتمل الرفع. جارٍ حفظ الكتاب…';
            meter.value = 100;
            progressValue.textContent = '100%';
            cancel.hidden = true;
            task.committing = true;
            HTMLFormElement.prototype.submit.call(form);
        } catch (error) {
            showError(task.controller.signal.aborted ? 'أُلغي الرفع. يمكنك اختيار ملف والمحاولة من جديد.' : error.message);
            progress.hidden = true;
            fileInput.disabled = false;
            submit.disabled = false;
            cancel.hidden = true;
            active = null;
            void removeUpload(task.uploadId);
        }
    });
})();
