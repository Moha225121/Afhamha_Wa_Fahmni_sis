(() => {
    const reader = document.querySelector('[data-library-reader-canvas]');
    if (!reader) return;

    const viewportElement = reader.querySelector('[data-pdf-viewport]');
    const stage = reader.querySelector('[data-pdf-stage]');
    const loading = reader.querySelector('[data-pdf-loading]');
    const loadingLabel = reader.querySelector('[data-pdf-loading-label]');
    const loadingDetail = reader.querySelector('[data-pdf-loading-detail]');
    const errorPanel = reader.querySelector('[data-pdf-error]');
    const errorMessage = reader.querySelector('[data-pdf-error-message]');
    const previous = reader.querySelector('[data-pdf-previous]');
    const next = reader.querySelector('[data-pdf-next]');
    const pageInput = reader.querySelector('[data-pdf-page]');
    const pageCount = reader.querySelector('[data-pdf-page-count]');
    const jump = reader.querySelector('[data-pdf-jump]');
    const zoom = reader.querySelector('[data-pdf-zoom]');
    const status = reader.querySelector('[data-pdf-status]');
    const fullscreen = document.querySelector('[data-library-fullscreen]');
    const exitFullscreen = reader.querySelector('[data-pdf-exit-fullscreen]');
    const assets = reader.dataset.pdfAssets;
    let library;
    let documentTask;
    let pdf;
    let currentPage = 1;
    let zoomFactor = 1;
    let renderTask;
    let renderVersion = 0;
    let displayedPage;
    let initializing = false;
    let disposed = false;

    function controls() {
        previous.disabled = !pdf || currentPage <= 1;
        next.disabled = !pdf || currentPage >= pdf.numPages;
        pageInput.disabled = !pdf;
        jump.querySelector('button').disabled = !pdf;
        zoom.disabled = !pdf;
        pageInput.value = currentPage;
        pageInput.max = pdf?.numPages || 1;
        pageCount.textContent = pdf?.numPages || '—';
    }

    function setLoading(message) {
        loading.hidden = false;
        errorPanel.hidden = true;
        loadingLabel.textContent = message;
        loadingDetail.textContent = 'تُحمّل الصفحات المطلوبة عند قراءتها.';
        viewportElement.setAttribute('aria-busy', 'true');
    }

    function showError(error) {
        loading.hidden = true;
        viewportElement.setAttribute('aria-busy', 'false');
        errorPanel.hidden = false;
        let message = 'تعذّر تحميل هذه الصفحة. تحقق من الاتصال وأعد المحاولة، أو نزّل الكتاب.';
        if (error?.name === 'PasswordException') message = 'هذا الملف محمي بكلمة مرور. نزّله لفتحه بالتطبيق المناسب.';
        if (error?.name === 'InvalidPDFException') message = 'لم يتمكن القارئ من فتح هذا الملف. يمكنك تنزيله والتحقق منه.';
        if (error?.status === 401 || error?.status === 403) message = 'انتهت الجلسة أو لا تتوفر صلاحية عرض الملف. أعد تسجيل الدخول.';
        if (error?.status === 404) message = 'الملف غير متاح حاليًا. ارجع إلى المكتبة أو تواصل مع الإدارة.';
        errorMessage.textContent = message;
        status.textContent = 'تعذّر عرض الصفحة';
    }

    async function renderPage() {
        if (!pdf || disposed) return;
        const version = ++renderVersion;
        const number = currentPage;
        renderTask?.cancel();
        setLoading(`جارٍ عرض الصفحة ${number}…`);
        controls();
        try {
            const page = await pdf.getPage(number);
            if (version !== renderVersion || disposed) return;
            const natural = page.getViewport({ scale: 1 });
            const availableWidth = Math.max(160, viewportElement.clientWidth - 32);
            const scale = availableWidth / natural.width * zoomFactor;
            const viewport = page.getViewport({ scale });
            // Keep only one visible page and bound HiDPI bitmap memory on large pages.
            const outputScale = Math.min(window.devicePixelRatio || 1, 2, Math.sqrt(16000000 / (viewport.width * viewport.height)));
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.ceil(viewport.width * outputScale));
            canvas.height = Math.max(1, Math.ceil(viewport.height * outputScale));
            canvas.style.width = `${Math.floor(viewport.width)}px`;
            canvas.style.height = `${Math.floor(viewport.height)}px`;
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', `الصفحة ${number} من ${pdf.numPages}`);
            const context = canvas.getContext('2d', { alpha: false });
            if (!context) throw new Error('Canvas unavailable');
            const pending = page.render({
                canvasContext: context,
                viewport,
                transform: outputScale === 1 ? null : [outputScale, 0, 0, outputScale, 0, 0],
                background: 'rgb(255, 255, 255)',
            });
            renderTask = pending;
            await pending.promise;
            if (version !== renderVersion || disposed) return;
            stage.replaceChildren(canvas);
            if (displayedPage && displayedPage !== page) displayedPage.cleanup();
            displayedPage = page;
            loading.hidden = true;
            viewportElement.setAttribute('aria-busy', 'false');
            viewportElement.scrollTop = 0;
            status.textContent = `الصفحة ${number} من ${pdf.numPages}`;
            renderTask = null;
        } catch (error) {
            if (version !== renderVersion || disposed || error?.name === 'RenderingCancelledException') return;
            showError(error);
        }
    }

    async function openBook() {
        if (initializing || disposed) return;
        initializing = true;
        setLoading('جارٍ فتح الكتاب…');
        try {
            library ||= await import(`${assets}pdf.mjs`);
            library.GlobalWorkerOptions.workerSrc = `${assets}pdf.worker.mjs`;
            if (documentTask) await documentTask.destroy();
            documentTask = library.getDocument({
                url: reader.dataset.pdfUrl,
                withCredentials: true,
                cMapUrl: `${assets}cmaps/`,
                cMapPacked: true,
                iccUrl: `${assets}iccs/`,
                standardFontDataUrl: `${assets}standard_fonts/`,
                wasmUrl: `${assets}wasm/`,
                disableAutoFetch: true,
                disableStream: true,
                disableRange: false,
                rangeChunkSize: 256 * 1024,
                isEvalSupported: false,
            });
            documentTask.onProgress = ({ loaded }) => {
                if (!pdf && loaded > 0) loadingDetail.textContent = `تم جلب ${(loaded / 1048576).toFixed(1)} MB من الملف`;
            };
            pdf = await documentTask.promise;
            if (disposed) return;
            currentPage = Math.min(currentPage, pdf.numPages);
            controls();
            await renderPage();
        } catch (error) {
            if (!disposed) showError(error);
        } finally {
            initializing = false;
        }
    }

    function goTo(number) {
        if (!pdf) return;
        const target = Math.max(1, Math.min(pdf.numPages, Math.trunc(Number(number)) || 1));
        if (target === currentPage) {
            pageInput.value = currentPage;
            return;
        }
        currentPage = target;
        void renderPage();
    }

    previous.addEventListener('click', () => goTo(currentPage - 1));
    next.addEventListener('click', () => goTo(currentPage + 1));
    jump.addEventListener('submit', event => { event.preventDefault(); goTo(pageInput.value); });
    zoom.addEventListener('change', () => { zoomFactor = Number(zoom.value) || 1; void renderPage(); });
    reader.querySelector('[data-pdf-retry]').addEventListener('click', () => pdf ? void renderPage() : void openBook());
    reader.addEventListener('keydown', event => {
        if (event.target.closest('input, select, button, a') || event.altKey || event.ctrlKey || event.metaKey) return;
        if (event.key === 'ArrowLeft' || event.key === 'PageDown') { event.preventDefault(); goTo(currentPage + 1); }
        if (event.key === 'ArrowRight' || event.key === 'PageUp') { event.preventDefault(); goTo(currentPage - 1); }
    });

    if (fullscreen && document.fullscreenEnabled && reader.requestFullscreen) {
        fullscreen.hidden = false;
        fullscreen.addEventListener('click', async () => {
            try { await reader.requestFullscreen(); } catch (_) { fullscreen.hidden = true; }
        });
        exitFullscreen.addEventListener('click', () => document.exitFullscreen().catch(() => {}));
        document.addEventListener('fullscreenchange', () => { exitFullscreen.hidden = document.fullscreenElement !== reader; });
    }

    let resizeTimer;
    let lastWidth = viewportElement.clientWidth;
    const observer = window.ResizeObserver ? new ResizeObserver(() => {
        if (Math.abs(viewportElement.clientWidth - lastWidth) < 2) return;
        lastWidth = viewportElement.clientWidth;
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => { if (pdf) void renderPage(); }, 180);
    }) : null;
    observer?.observe(viewportElement);
    window.addEventListener('pagehide', event => {
        if (event.persisted) return;
        disposed = true;
        observer?.disconnect();
        clearTimeout(resizeTimer);
        renderTask?.cancel();
        void documentTask?.destroy();
    });
    void openBook();
})();
