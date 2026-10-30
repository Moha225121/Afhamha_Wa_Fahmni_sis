export function safeReceiptUrl(value, origin) {
    try {
        const url = new URL(value, origin);
        return url.origin === origin && /^https?:$/.test(url.protocol)
            && /^\/teacher-message-receipts\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/delivered$/i.test(url.pathname)
            && /^[0-9a-f]{64}$/i.test(url.searchParams.get('signature') || '') ? url.href : null;
    } catch { return null; }
}

export function bootTeacherMessageReceipts(doc, options = {}) {
    const origin = options.origin ?? doc.defaultView?.location?.origin;
    const send = options.send ?? ((...args) => fetch(...args));
    const completed = new Set();
    const pending = new Map();
    const acknowledge = value => {
        const url = safeReceiptUrl(value, origin);
        if (!url || completed.has(url)) return Promise.resolve();
        if (pending.has(url)) return pending.get(url);
        const work = Promise.resolve().then(() => send(url, {
            method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json'},
        })).then(response => { if (response.ok) completed.add(url); }).catch(() => {}).finally(() => pending.delete(url));
        pending.set(url, work);
        return work;
    };
    const retry = () => Promise.all(Array.from(doc.querySelectorAll('[data-teacher-receipt-url]'))
        .map(element => acknowledge(element.getAttribute('data-teacher-receipt-url'))));
    doc.addEventListener('visibilitychange', () => { if (doc.visibilityState === 'visible') retry(); });
    doc.defaultView?.addEventListener('online', retry);
    retry();
    return {retry};
}
export function safeViewedUrl(value, origin) {
    try {
        const url = new URL(value, origin);
        return url.origin === origin && /^https?:$/.test(url.protocol)
            && /^\/teacher-messages\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/viewed$/i.test(url.pathname)
            && (!url.search || /^\?student=[1-9][0-9]*$/.test(url.search)) && !url.hash ? url.href : null;
    } catch { return null; }
}

export function bootTeacherMessageView(doc, options = {}) {
    const element = doc.querySelector?.('[data-teacher-viewed-url]');
    const origin = options.origin ?? doc.defaultView?.location?.origin;
    const url = element ? safeViewedUrl(element.getAttribute('data-teacher-viewed-url'), origin) : null;
    const token = doc.querySelector?.('meta[name="csrf-token"]')?.getAttribute('content');
    const send = options.send ?? ((...args) => fetch(...args));
    let completed = false;
    let pending;
    const retry = () => {
        if (!url || !token || completed || doc.visibilityState !== 'visible') return Promise.resolve();
        if (pending) return pending;
        pending = Promise.resolve().then(() => send(url, {
            method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json', 'X-CSRF-TOKEN': token},
        })).then(response => { if (response.ok) completed = true; }).catch(() => {}).finally(() => { pending = undefined; });
        return pending;
    };
    doc.addEventListener('visibilitychange', retry);
    doc.defaultView?.addEventListener('online', retry);
    retry();
    return {retry};
}

if (typeof document !== 'undefined') {
    bootTeacherMessageReceipts(document);
    bootTeacherMessageView(document);
}
