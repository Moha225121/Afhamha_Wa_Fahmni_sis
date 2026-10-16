(() => {
    const page = document.querySelector('[data-attendance-page]');
    if (!page) return;

    const form = page.querySelector('[data-attendance-form]');
    const rows = [...page.querySelectorAll('[data-student-row]')];
    const search = page.querySelector('[data-student-search]');
    const clearSearch = page.querySelector('[data-clear-search]');
    const selectVisible = page.querySelector('[data-select-visible]');
    const markAllAbsent = page.querySelector('[data-mark-all-absent]');
    const undoMarkAllAbsent = page.querySelector('[data-undo-mark-all-absent]');
    const visibleCount = page.querySelector('[data-visible-count]');
    const emptyResults = page.querySelector('[data-no-results]');
    const filterButtons = [...page.querySelectorAll('[data-status-filter]')];
    let activeFilter = 'all';

    const normalize = (value) => value
        .toLocaleLowerCase()
        .replace(/[٠-٩۰-۹]/g, (digit) => String(digit.charCodeAt(0) % 16))
        .normalize('NFD')
        .replace(/[\u064B-\u065F\u0670\u0640]/g, '')
        .replace(/[أإآٱ]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .replace(/ؤ/g, 'و')
        .replace(/ئ/g, 'ي')
        .replace(/\s+/g, ' ')
        .trim();

    const rowStatusMatches = (status) => activeFilter === 'all' || status === activeFilter;

    const setStatus = (row, statusValue) => {
        const isAbsent = statusValue === 'absent' || statusValue === 'excused_absence';
        const isPresent = !isAbsent;
        const checkbox = row.querySelector('[data-student-check]');
        const status = row.querySelector('[data-status-value]');
        if (checkbox) checkbox.checked = isAbsent;
        if (status) status.value = statusValue;
        const label = row.querySelector('[data-presence-label]');
        if (label) label.textContent = isPresent ? 'حاضر' : 'غائب';
        row.dataset.status = isPresent ? 'present' : 'absent';
    };

    const updatePresence = (row, isPresent) => setStatus(row, isPresent ? 'present' : 'absent');

    const updateSummary = () => {
        const counts = { present: 0, absent: 0 };
        rows.forEach((row) => {
            const status = row.querySelector('[data-status-value]')?.value ?? 'present';
            if (status === 'absent' || status === 'excused_absence') counts.absent += 1;
            else counts.present += 1;
        });
        Object.entries(counts).forEach(([status, count]) => {
            const target = page.querySelector(`[data-summary-${status}]`);
            if (target) target.textContent = String(count);
        });
    };

    const applyFilters = () => {
        const terms = normalize(search?.value ?? '').split(' ').filter(Boolean);
        let visible = 0;
        rows.forEach((row) => {
            const searchable = normalize(row.dataset.search ?? row.textContent ?? '');
            const compact = searchable.replace(/[\s-]+/g, '');
            const matchesSearch = terms.every((term) => searchable.includes(term) || compact.includes(term.replace(/-/g, '')));
            const matchesStatus = rowStatusMatches(row.dataset.status ?? 'present');
            const shown = matchesSearch && matchesStatus;
            row.hidden = !shown;
            if (shown) visible += 1;
        });
        if (visibleCount) visibleCount.textContent = String(visible);
        if (emptyResults) emptyResults.hidden = visible !== 0 || rows.length === 0;
        if (clearSearch) clearSearch.hidden = !search?.value;
        updateSelection();
    };

    const updateSelection = () => {
        const visibleRows = rows.filter((row) => !row.hidden);
        if (selectVisible) {
            const selectedVisible = visibleRows.filter((row) => row.querySelector('[data-student-check]')?.checked).length;
            selectVisible.checked = visibleRows.length > 0 && selectedVisible === visibleRows.length;
            selectVisible.indeterminate = selectedVisible > 0 && selectedVisible < visibleRows.length;
            selectVisible.disabled = visibleRows.length === 0;
        }
    };

    rows.forEach((row) => {
        const check = row.querySelector('[data-student-check]');
        const status = row.querySelector('[data-status-value]')?.value;
        setStatus(row, status ?? 'present');
        check?.addEventListener('change', () => {
            updatePresence(row, !check.checked);
            updateSummary();
            applyFilters();
        });
    });

    search?.addEventListener('input', applyFilters);
    clearSearch?.addEventListener('click', () => {
        if (!search) return;
        search.value = '';
        search.focus();
        applyFilters();
    });

    selectVisible?.addEventListener('change', () => {
        rows.filter((row) => !row.hidden).forEach((row) => {
            updatePresence(row, !selectVisible.checked);
        });
        updateSummary();
        applyFilters();
        updateSelection();
    });

    let previousPresence = null;

    markAllAbsent?.addEventListener('click', () => {
        if (!rows.length || !window.confirm(`سيتم تغيير حالة جميع الطلاب (${rows.length}) إلى غائب. لن يُحفظ التغيير حتى تضغط «حفظ حضور اليوم». هل تريد المتابعة؟`)) return;
        previousPresence ??= new Map(rows.map((row) => [row.dataset.studentId, row.querySelector('[data-status-value]')?.value ?? 'present']));
        rows.forEach((row) => updatePresence(row, false));
        if (undoMarkAllAbsent) undoMarkAllAbsent.hidden = false;
        updateSummary();
        applyFilters();
    });

    undoMarkAllAbsent?.addEventListener('click', () => {
        if (!previousPresence) return;
        rows.forEach((row) => setStatus(row, previousPresence.get(row.dataset.studentId) ?? 'present'));
        previousPresence = null;
        undoMarkAllAbsent.hidden = true;
        updateSummary();
        applyFilters();
    });

    filterButtons.forEach((button) => button.addEventListener('click', () => {
        activeFilter = button.dataset.statusFilter ?? 'all';
        filterButtons.forEach((item) => {
            const active = item === button;
            item.classList.toggle('is-active', active);
            item.setAttribute('aria-pressed', String(active));
        });
        applyFilters();
    }));

    const scanner = page.querySelector('[data-quick-scan]');
    const scanInput = scanner?.querySelector('[data-scan-input]');
    const scanSubmit = scanner?.querySelector('[data-scan-submit]');
    const scanResult = scanner?.querySelector('[data-scan-result]');

    const scanStudentRow = (value) => {
        const inputValue = String(value ?? '').trim();
        const qrMatch = inputValue.match(/student-card\/(\d+)\/verify|[?&]student_id=(\d+)/i);
        if (qrMatch) return rows.find((row) => row.dataset.studentId === (qrMatch[1] ?? qrMatch[2]));
        const number = normalize(inputValue).replace(/[\s-]+/g, '');
        return number ? rows.find((row) => normalize(row.dataset.studentNumber ?? '').replace(/[\s-]+/g, '') === number) : null;
    };

    const showScanResult = (message, error = false) => {
        if (!scanResult) return;
        scanResult.textContent = message;
        scanResult.className = `attendance-scan-result ${error ? 'is-error' : 'is-success'}`;
    };

    const confirmScannedPresence = (value) => {
        const row = scanStudentRow(value);
        if (!row) {
            showScanResult('لم يُعثر على الطالب في الفصل المحدد. تأكد من البطاقة أو الرقم الدراسي واختر الفصل الصحيح.', true);
            return;
        }
        updatePresence(row, true);
        // Keep all changes, including notes, in the same draft until the roster is saved.
        previousPresence = null;
        if (undoMarkAllAbsent) undoMarkAllAbsent.hidden = true;
        updateSummary();
        applyFilters();
        const name = row.querySelector('.attendance-student-identity a')?.textContent.trim() ?? '';
        showScanResult(`✓ ${name} — حاضر. اضغط «حفظ حضور اليوم» لتسجيل النتيجة.`);
        if (scanInput) scanInput.value = '';
    };

    scanSubmit?.addEventListener('click', () => confirmScannedPresence(scanInput?.value));
    scanInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            confirmScannedPresence(scanInput.value);
        }
    });

    const cameraStart = scanner?.querySelector('[data-camera-start]');
    const cameraStop = scanner?.querySelector('[data-camera-stop]');
    const cameraPanel = scanner?.querySelector('[data-camera-panel]');
    const video = scanner?.querySelector('[data-camera-video]');
    const imageInput = scanner?.querySelector('[data-qr-image]');
    const canvas = document.createElement('canvas');
    let stream = null;
    let cameraSession = 0;
    let frame = null;
    let lastFrame = 0;
    let lastCode = '';
    let lastCodeTime = 0;

    const decodeImage = (source, width, height) => {
        if (!width || !height || typeof window.jsQR !== 'function') return null;
        const scale = Math.min(1, 960 / Math.max(width, height));
        canvas.width = Math.round(width * scale);
        canvas.height = Math.round(height * scale);
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (!context) return null;
        context.drawImage(source, 0, 0, canvas.width, canvas.height);
        const pixels = context.getImageData(0, 0, canvas.width, canvas.height);
        return window.jsQR(pixels.data, pixels.width, pixels.height, { inversionAttempts: 'attemptBoth' })?.data;
    };

    const stopCamera = () => {
        cameraSession += 1;
        if (frame !== null) cancelAnimationFrame(frame);
        frame = null;
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        if (video) video.srcObject = null;
        if (cameraPanel) cameraPanel.hidden = true;
        if (cameraStop) cameraStop.hidden = true;
        if (cameraStart) {
            cameraStart.disabled = false;
            cameraStart.setAttribute('aria-expanded', 'false');
        }
    };

    const scanFrame = (timestamp) => {
        if (!stream || !video) return;
        if (timestamp - lastFrame > 200 && video.readyState >= 2) {
            lastFrame = timestamp;
            try {
                const code = decodeImage(video, video.videoWidth, video.videoHeight);
                if (code && (code !== lastCode || timestamp - lastCodeTime > 2500)) {
                    lastCode = code;
                    lastCodeTime = timestamp;
                    confirmScannedPresence(code);
                }
            } catch {
                stopCamera();
                showScanResult('تعذرت قراءة الكاميرا. جرّب صورة QR أو أدخل الرقم الدراسي.', true);
                return;
            }
        }
        frame = requestAnimationFrame(scanFrame);
    };

    cameraStart?.addEventListener('click', async () => {
        if (!video || !cameraPanel || !navigator.mediaDevices?.getUserMedia || typeof window.jsQR !== 'function') {
            showScanResult('الكاميرا غير متاحة هنا. افتح الموقع عبر HTTPS، أو استخدم صورة QR أو الرقم الدراسي.', true);
            return;
        }
        const session = ++cameraSession;
        cameraStart.disabled = true;
        if (cameraStop) cameraStop.hidden = false;
        try {
            const acquired = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' } } });
            if (session !== cameraSession) {
                acquired.getTracks().forEach((track) => track.stop());
                return;
            }
            stream = acquired;
            video.srcObject = stream;
            cameraPanel.hidden = false;
            cameraStart.setAttribute('aria-expanded', 'true');
            await video.play();
            if (session !== cameraSession) return;
            lastCode = '';
            lastFrame = 0;
            frame = requestAnimationFrame(scanFrame);
        } catch (error) {
            if (session !== cameraSession) return;
            stopCamera();
            showScanResult(error?.name === 'NotAllowedError' ? 'اسمح باستخدام الكاميرا من إعدادات المتصفح ثم أعد المحاولة.' : 'تعذر تشغيل الكاميرا. جرّب صورة QR أو أدخل الرقم الدراسي.', true);
        }
    });
    cameraStop?.addEventListener('click', stopCamera);
    window.addEventListener('pagehide', stopCamera);
    document.addEventListener('visibilitychange', () => { if (document.hidden) stopCamera(); });

    imageInput?.addEventListener('change', async () => {
        const file = imageInput.files?.[0];
        if (!file) return;
        stopCamera();
        const url = URL.createObjectURL(file);
        try {
            const image = new Image();
            image.src = url;
            await image.decode();
            const code = decodeImage(image, image.naturalWidth, image.naturalHeight);
            if (code) confirmScannedPresence(code);
            else showScanResult('لم يُعثر على رمز QR واضح في الصورة. جرّب صورة أقرب أو أدخل الرقم الدراسي.', true);
        } catch {
            showScanResult('تعذرت قراءة الصورة. استخدم صورة واضحة للبطاقة.', true);
        } finally {
            URL.revokeObjectURL(url);
            imageInput.value = '';
        }
    });

    form?.addEventListener('submit', (event) => {
        if (!form.reportValidity()) event.preventDefault();
    });

    updateSummary();
    applyFilters();
})();
