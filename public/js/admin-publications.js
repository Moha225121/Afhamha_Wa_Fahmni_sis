// Read-only monitoring: publication writes remain in the existing teacher workflows.
export function bootAdminPublications(doc = document, options = {}) {
    const monitor = doc.querySelector('[data-publication-monitor]') || doc.querySelector('[data-publication-detail]');
    if (!monitor) return null;
    const win = options.window || doc.defaultView || globalThis.window;
    const fetchFn = options.fetchFn || globalThis.fetch.bind(globalThis);
    const schedule = options.setIntervalFn || globalThis.setInterval.bind(globalThis);
    const unschedule = options.clearIntervalFn || globalThis.clearInterval.bind(globalThis);
    const results = doc.querySelector('[data-publication-results]');
    const form = doc.querySelector('[data-publication-filters]');
    const connection = doc.querySelector('[data-publication-connection]');
    const updated = doc.querySelector('[data-publication-updated]');
    const revision = doc.querySelector('[data-publication-revision]');
    const endpoint = monitor.dataset.refreshUrl || monitor.dataset.checkUrl;
    const currentUrl = () => String(win?.location?.href || '');
    const fields = () => JSON.stringify(Array.from(form?.elements || []).filter((field) => field.name && !['submit', 'button'].includes(field.type)).map((field) => [field.name, field.value, field.checked]));
    const originalUrl = currentUrl();
    const originalFields = fields();
    let version = (results || monitor).dataset.publicationVersion || '';
    let disposed = false;
    let pending = false;
    let aborter;
    let failures = 0;
    let nextTry = 0;
    let revisionShown = false;
    let suspended = false;

    function state(text, failed = false) {
        if (connection) {
            connection.textContent = text;
            connection.dataset.state = failed ? 'offline' : 'live';
        }
        monitor.dataset.connection = failed ? 'offline' : 'live';
    }

    async function refresh() {
        if (disposed || suspended || pending || doc.hidden || revisionShown || currentUrl() !== originalUrl || fields() !== originalFields || Date.now() < nextTry) return;
        pending = true;
        aborter = new AbortController();
        const requestFields = fields();
        try {
            const url = new URL(endpoint, originalUrl || 'http://localhost');
            if (win?.location?.origin && url.origin !== win.location.origin) throw new Error('Invalid monitoring endpoint');
            if (results) url.searchParams.set('version', version);
            const response = await fetchFn(url.toString(), {
                method: 'GET', credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: aborter.signal,
            });
            if (!response.ok || response.redirected) throw new Error('Monitoring request failed');
            const data = await response.json();
            if (disposed || doc.hidden || currentUrl() !== originalUrl || fields() !== requestFields || typeof data.version !== 'string') return;
            if (data.version !== version) {
                if (results && typeof data.html === 'string') {
                    // Only results are replaced: search controls and keyboard focus remain intact.
                    results.innerHTML = data.html;
                    results.dataset.publicationVersion = data.version;
                    results.dataset.fingerprint = data.version;
                    version = data.version;
                } else if (!results && revision) {
                    revision.hidden = false;
                    revisionShown = true;
                }
            }
            if (updated && data.checked_at) {
                const date = new Date(data.checked_at);
                if (!Number.isNaN(date.getTime())) {
                    updated.dateTime = data.checked_at;
                    updated.textContent = new Intl.DateTimeFormat('ar-LY', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'Africa/Tripoli' }).format(date);
                }
            }
            failures = 0;
            nextTry = 0;
            state('متابعة مباشرة');
        } catch (error) {
            if (!disposed && error?.name !== 'AbortError') {
                failures += 1;
                nextTry = Date.now() + Math.min(30000, 2000 * (2 ** Math.min(failures - 1, 4)));
                state('انقطع الاتصال — تُعاد المحاولة تلقائيًا', true);
            }
        } finally {
            pending = false;
        }
    }

    function onVisibility() {
        if (doc.hidden) aborter?.abort();
        else { nextTry = 0; void refresh(); }
    }
    function onPageHide(event) {
        if (!event?.persisted) { dispose(); return; }
        // A page kept in the browser's back/forward cache must resume its monitor on return.
        suspended = true;
        aborter?.abort();
    }
    function onPageShow(event) {
        if (!event?.persisted || disposed) return;
        suspended = false;
        nextTry = 0;
        void refresh();
    }
    function dispose() {
        if (disposed) return;
        disposed = true;
        aborter?.abort();
        unschedule(timer);
        doc.removeEventListener('visibilitychange', onVisibility);
        win?.removeEventListener?.('pagehide', onPageHide);
        win?.removeEventListener?.('pageshow', onPageShow);
    }
    const timer = schedule(() => { void refresh(); }, Math.max(1000, options.intervalMs || 2000));
    doc.addEventListener('visibilitychange', onVisibility);
    win?.addEventListener?.('pagehide', onPageHide);
    win?.addEventListener?.('pageshow', onPageShow);
    return { refresh, dispose };
}

if (typeof document !== 'undefined') bootAdminPublications(document);
