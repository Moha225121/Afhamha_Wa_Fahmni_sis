import {safeReceiptUrl, safeViewedUrl} from './teacher-message-receipts.js';

export function normalizeInboxSearch(value) {
    return String(value ?? '').normalize('NFKC').toLowerCase().replace(/[\u064b-\u065f\u0670\u0640]/g, '')
        .replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/\s+/g, ' ').trim();
}

export function matchesInboxSearch(value, query) {
    const text = normalizeInboxSearch(value);
    return normalizeInboxSearch(query).split(' ').filter(Boolean).every(part => text.includes(part));
}

export function safeConversationReadUrl(value, origin, role) {
    try {
        const url = new URL(value, origin);
        return ['parent', 'teacher'].includes(role) && url.origin === origin && /^https?:$/.test(url.protocol)
            && url.pathname === `/${role}/messages/read` && !url.hash
            && /^[a-zA-Z0-9_-]{1,100}$/.test(url.searchParams.get('thread') || '') ? url.href : null;
    } catch { return null; }
}

export function messengerViewportHeight(viewportHeight, shellTop, navigationHeight = 0) {
    return Math.max(220, Math.min(730, viewportHeight - Math.max(0, shellTop) - navigationHeight - 14));
}

export function syncChildUnreadBadges(doc, incomingPage) {
    const next = incomingPage.querySelector('[data-unread-badges]');
    if (!next) return;
    const counts = new Map(Array.from(next.querySelectorAll('option')).map(option => [option.getAttribute('value'), option]));
    for (const select of doc.querySelectorAll('[data-unread-badges]')) {
        for (const option of select.querySelectorAll('option')) {
            const incoming = counts.get(option.getAttribute('value'));
            if (!incoming) continue;
            for (const attribute of ['data-unread-count', 'data-option-label']) {
                const value = incoming.getAttribute(attribute) ?? '';
                if (option.getAttribute(attribute) !== value) option.setAttribute(attribute, value);
            }
            if (option.textContent !== incoming.textContent) option.textContent = incoming.textContent;
        }
    }
}

export function bootPortalMessenger(doc, options = {}) {
    const app = doc.querySelector('[data-messenger]');
    if (!app) return null;
    const win = doc.defaultView;
    const send = options.send ?? ((...args) => fetch(...args));
    const origin = options.origin ?? win.location.origin;
    const parser = options.parser ?? (html => new win.DOMParser().parseFromString(html, 'text/html'));
    const csrf = doc.querySelector('meta[name="messenger-csrf"]')?.getAttribute('content');
    const search = app.querySelector('[data-pm-search]');
    const connection = app.querySelector('[data-pm-connection]');
    const newMessages = app.querySelector('[data-pm-new]');
    const body = app.querySelector('[data-pm-body]');
    const completed = new Set();
    const pending = new Map();
    let refreshing = false;
    let submitting = false;
    let observer;
    let layoutObserver;
    let followLatest = true;
    let disposed = false;

    const post = (url, key, needsCsrf = false) => {
        if (!url || completed.has(key) || (needsCsrf && (!csrf || doc.visibilityState !== 'visible'))) return Promise.resolve();
        if (pending.has(key)) return pending.get(key);
        const work = Promise.resolve().then(() => {
            if (needsCsrf && (disposed || doc.visibilityState !== 'visible')) return null;
            return send(url, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: {Accept: 'application/json', ...(needsCsrf ? {'X-CSRF-TOKEN': csrf} : {})},
            });
        }).then(response => { if (response?.ok) completed.add(key); }).catch(() => {}).finally(() => pending.delete(key));
        pending.set(key, work);
        return work;
    };

    const stream = () => app.querySelector('[data-pm-stream]');
    const scrollToEnd = () => {
        const current = stream();
        if (current) current.scrollTop = current.scrollHeight;
        if (newMessages) newMessages.hidden = true;
        followLatest = true;
    };
    const applySearch = () => {
        let shown = 0;
        for (const contact of app.querySelectorAll('[data-pm-contact]')) {
            const match = matchesInboxSearch(contact.getAttribute('data-search'), search?.value);
            contact.hidden = !match;
            if (match) shown++;
        }
        const empty = app.querySelector('[data-pm-search-empty]');
        if (empty) empty.hidden = shown > 0 || !search?.value.trim();
    };

    const acknowledge = () => {
        for (const message of app.querySelectorAll('[data-pm-receipt]')) {
            const url = safeReceiptUrl(message.getAttribute('data-pm-receipt'), origin);
            post(url, `received:${message.getAttribute('data-message-key') || url}`);
        }
        if (doc.visibilityState !== 'visible') return;
        const current = stream();
        if (!current || !app.getAttribute('data-selected-key')) return;
        const readUrl = safeConversationReadUrl(current.getAttribute('data-read-action'), origin, app.getAttribute('data-role'));
        if (readUrl) post(readUrl, `read:${readUrl}:${current.getAttribute('data-last-key')}`, true);
        observer?.disconnect();
        const markBubble = bubble => {
            const url = safeViewedUrl(bubble.getAttribute('data-pm-viewed'), origin);
            if (doc.visibilityState === 'visible') post(url, `viewed:${url}`, true);
        };
        const bubbles = Array.from(current.querySelectorAll('[data-pm-viewed]'));
        if (win.IntersectionObserver) {
            observer = new win.IntersectionObserver(entries => {
                for (const entry of entries) if (entry.isIntersecting) markBubble(entry.target);
            }, {root: current, threshold: 0.05});
            bubbles.forEach(bubble => observer.observe(bubble));
        } else {
            const bounds = current.getBoundingClientRect();
            bubbles.forEach(bubble => {
                const rect = bubble.getBoundingClientRect();
                if (rect.bottom > bounds.top && rect.top < bounds.bottom) markBubble(bubble);
            });
        }
    };

    const refresh = async () => {
        if (disposed || refreshing || submitting || doc.visibilityState !== 'visible') return;
        refreshing = true;
        try {
            const url = new URL(win.location.href);
            if (url.origin !== origin) return;
            const child = app.getAttribute('data-student-id');
            if (app.getAttribute('data-role') === 'parent' && /^[1-9][0-9]*$/.test(child || '')) url.searchParams.set('student', child);
            const response = await send(url.href, {
                credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'text/html', 'X-Messenger-Refresh': '1'},
            });
            if (!response.ok) throw new Error('refresh');
            const page = parser(await response.text());
            const incoming = page.querySelector('[data-messenger]');
            if (!incoming || incoming.getAttribute('data-role') !== app.getAttribute('data-role')
                || incoming.getAttribute('data-selected-key') !== app.getAttribute('data-selected-key')
                || incoming.getAttribute('data-student-id') !== app.getAttribute('data-student-id')) throw new Error('session');
            if (disposed || doc.visibilityState !== 'visible') return;
            syncChildUnreadBadges(doc, page);
            const oldList = app.querySelector('[data-pm-threads]');
            const newList = incoming.querySelector('[data-pm-threads]');
            if (oldList && newList && oldList.innerHTML !== newList.innerHTML) {
                const position = oldList.scrollTop;
                oldList.replaceChildren(...Array.from(newList.childNodes).map(node => doc.importNode(node, true)));
                oldList.scrollTop = position;
                applySearch();
            }
            const oldTotal = app.querySelector('[data-pm-total]');
            const newTotal = incoming.querySelector('[data-pm-total]');
            if (oldTotal && newTotal) oldTotal.textContent = newTotal.textContent;
            const current = stream();
            const next = incoming.querySelector('[data-pm-stream]');
            if (current && next && current.innerHTML !== next.innerHTML) {
                const atEnd = current.scrollHeight - current.scrollTop - current.clientHeight < 90;
                const priorLast = current.getAttribute('data-last-key');
                const position = current.scrollTop;
                current.replaceChildren(...Array.from(next.childNodes).map(node => doc.importNode(node, true)));
                current.setAttribute('data-last-key', next.getAttribute('data-last-key') || '');
                current.setAttribute('data-read-action', next.getAttribute('data-read-action') || '');
                if (atEnd) scrollToEnd();
                else {
                    current.scrollTop = position;
                    if (newMessages && priorLast !== next.getAttribute('data-last-key')) newMessages.hidden = false;
                }
            }
            if (connection) connection.hidden = true;
            acknowledge();
        } catch {
            if (connection && doc.visibilityState === 'visible') connection.hidden = false;
        } finally { refreshing = false; }
    };

    const fitBody = () => {
        if (!body) return;
        body.style.height = '54px';
        body.style.height = `${Math.min(150, Math.max(54, body.scrollHeight + 2))}px`;
    };
    const fitShell = () => {
        const shell = app.querySelector('.pm-shell');
        if (!shell?.getBoundingClientRect || !win.innerHeight) return;
        const navigation = doc.querySelector('.bottom-nav');
        const bounds = navigation?.getBoundingClientRect?.();
        const fixedNavigation = bounds && bounds.width > bounds.height * 2 && bounds.height < 140
            && win.getComputedStyle?.(navigation).position === 'fixed';
        const height = messengerViewportHeight(win.visualViewport?.height ?? win.innerHeight,
            shell.getBoundingClientRect().top, fixedNavigation ? bounds.height : 0);
        shell.style.setProperty?.('--pm-available-height', `${height}px`);
        if (!app.getAttribute('data-selected-key')) {
            shell.style.height = 'auto';
            shell.style.minHeight = '0px';
            return;
        }
        shell.style.height = `${height}px`;
        shell.style.minHeight = '220px';
    };
    body?.addEventListener('input', fitBody);
    search?.addEventListener('input', applySearch);
    newMessages?.addEventListener('click', scrollToEnd);
    app.querySelector('[data-pm-refresh]')?.addEventListener('click', refresh);
    app.querySelector('[data-pm-composer]')?.addEventListener('submit', event => {
        const feedback = app.querySelector('[data-pm-feedback]');
        if (submitting || !body?.value.trim()) {
            event.preventDefault();
            if (feedback && !submitting) { feedback.hidden = false; feedback.textContent = 'اكتب نص الرسالة أولًا.'; }
            body?.focus();
            return;
        }
        submitting = true;
        const button = app.querySelector('[data-pm-send]');
        if (button) button.disabled = true;
    });
    const onVisibility = () => {
        if (doc.visibilityState === 'visible') { acknowledge(); refresh(); }
        else observer?.disconnect();
    };
    doc.addEventListener('visibilitychange', onVisibility);
    win.addEventListener('online', onVisibility);
    win.addEventListener('resize', fitShell);
    win.visualViewport?.addEventListener('resize', fitShell);
    win.addEventListener('pageshow', () => {
        submitting = false;
        if (disposed) {
            disposed = false;
            interval = win.setInterval(refresh, options.pollInterval ?? 15000);
            if (layoutObserver) {
                layoutObserver.observe(app);
                if (stream()) layoutObserver.observe(stream());
            }
            onVisibility();
        }
        const button = app.querySelector('[data-pm-send]');
        if (button) button.disabled = false;
    });
    let interval = win.setInterval(refresh, options.pollInterval ?? 15000);
    const dispose = () => { disposed = true; win.clearInterval(interval); observer?.disconnect(); layoutObserver?.disconnect(); };
    win.addEventListener('pagehide', dispose);
    fitShell();
    fitBody();
    applySearch();
    scrollToEnd();
    stream()?.addEventListener('scroll', () => {
        const current = stream();
        followLatest = current.scrollHeight - current.scrollTop - current.clientHeight < 90;
    });
    if (win.ResizeObserver) {
        layoutObserver = new win.ResizeObserver(() => {
            if (!disposed) { fitShell(); if (followLatest) scrollToEnd(); }
        });
        if (stream()) layoutObserver.observe(stream());
        layoutObserver.observe(app);
    }
    doc.fonts?.ready?.then(() => {
        if (!disposed) { fitShell(); if (followLatest) scrollToEnd(); }
    }).catch(() => {});
    acknowledge();
    return {refresh, acknowledge, applySearch, dispose};
}

if (typeof document !== 'undefined') bootPortalMessenger(document);
