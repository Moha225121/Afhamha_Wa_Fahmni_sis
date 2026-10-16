(() => {
    const bell = document.querySelector('[data-notification-bell]');
    const initial = document.getElementById('parent-notification-initial');
    if (!bell || !initial) return;

    const trigger = bell.querySelector('summary');
    const preview = bell.querySelector('[data-notification-preview]');
    const error = bell.querySelector('[data-notification-error]');
    const live = document.querySelector('[data-notification-live]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const icon = bell.querySelector('.parent-notification-icon svg, .parent-notification-empty svg')?.cloneNode(true);
    let state = JSON.parse(initial.textContent);
    let filter = 'all';
    let pending = null;
    let writing = false;
    let rendered = '';

    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };

    function internalUrl(value) {
        const url = new URL(value, window.location.origin);
        if (url.origin !== window.location.origin || !url.pathname.startsWith('/parent/')) throw new Error('Invalid destination');
        return url.href;
    }

    function updateCount(count, announce = false) {
        const previous = state.unread_count;
        state.unread_count = count;
        document.querySelectorAll('[data-parent-unread]').forEach(badge => {
            badge.hidden = count === 0;
            badge.textContent = count > 99 ? '99+' : String(count);
        });
        trigger.setAttribute('aria-label', `الإشعارات، ${count} غير مقروءة`);
        bell.querySelector('[data-unread-description]').textContent = count ? `لديك ${count} إشعارات غير مقروءة` : 'أنت مطّلع على كل جديد';
        bell.querySelector('[data-notification-all]').hidden = count === 0;
        if (announce && count > previous) live.textContent = `لديك ${count} إشعارات غير مقروءة`;
    }

    function updateFollowupCounts() {
        document.querySelectorAll('[data-followup-unread]').forEach(badge => {
            const count = state.unread_by_category?.[badge.dataset.followupUnread] || 0;
            badge.hidden = count === 0;
            badge.textContent = count > 99 ? '99+' : String(count);
        });
    }

    function render(items, force = false) {
        const signature = JSON.stringify([filter, items]);
        if (signature === rendered || (!force && preview.contains(document.activeElement))) return;
        const focusedAction = preview.contains(document.activeElement)
            ? document.activeElement.closest('form')?.action : null;
        rendered = signature;
        const fragment = document.createDocumentFragment();
        items.forEach(item => {
            const form = element('form');
            form.method = 'post';
            form.action = internalUrl(item.read_url);
            form.dataset.notificationRead = '';
            const token = element('input');
            token.type = 'hidden'; token.name = '_token'; token.value = csrf;
            const method = element('input');
            method.type = 'hidden'; method.name = '_method'; method.value = 'patch';
            const button = element('button', `parent-notification-row${item.is_read ? '' : ' is-unread'}`);
            button.type = 'submit';
            const symbol = element('span', 'parent-notification-icon');
            symbol.setAttribute('aria-hidden', 'true');
            if (icon) symbol.append(icon.cloneNode(true));
            const copy = element('span', 'parent-notification-copy');
            const time = element('time', '', item.time_label);
            time.dateTime = item.created_at;
            copy.append(element('strong', '', item.title), element('span', '', item.body), time);
            button.append(symbol, copy);
            if (!item.is_read) {
                const dot = element('span', 'parent-notification-dot');
                dot.setAttribute('aria-label', 'غير مقروء');
                button.append(dot);
            }
            form.append(token, method, button);
            fragment.append(form);
        });
        if (!items.length) {
            const empty = element('div', 'parent-notification-empty');
            const symbol = element('span');
            symbol.setAttribute('aria-hidden', 'true');
            if (icon) symbol.append(icon.cloneNode(true));
            empty.append(symbol, element('strong', '', filter === 'unread' ? 'أنت مطّلع على كل جديد' : 'لا توجد إشعارات بعد'), element('p', '', filter === 'unread' ? 'لا توجد إشعارات غير مقروءة.' : 'ستظهر هنا إعلانات المدرسة وملاحظات الطالب والاستدعاءات.'));
            fragment.append(empty);
        }
        preview.replaceChildren(fragment);
        if (focusedAction) {
            const restored = [...preview.querySelectorAll('form')].find(form => form.action === focusedAction);
            (restored?.querySelector('button') || bell.querySelector('[data-notification-filter].is-active')).focus({preventScroll: true});
        }
    }

    async function refresh(userInitiated = false) {
        if (document.hidden || writing) return;
        pending?.abort();
        const request = new AbortController();
        pending = request;
        try {
            const url = new URL(bell.dataset.summaryUrl, window.location.origin);
            if (filter === 'unread') url.searchParams.set('unread', '1');
            const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store', signal: request.signal});
            if (!response.ok) throw new Error('Unable to refresh');
            const result = await response.json();
            if (pending !== request) return;
            updateCount(result.unread_count, true);
            state = result;
            updateFollowupCounts();
            render(result.notifications, userInitiated);
            error.hidden = true;
        } catch (failure) {
            if (failure.name !== 'AbortError' && userInitiated) {
                error.textContent = 'تعذّر تحديث الإشعارات. أغلق القائمة وافتحها للمحاولة مجددًا.';
                error.hidden = false;
            }
        } finally {
            if (pending === request) pending = null;
        }
    }

    bell.addEventListener('toggle', () => {
        trigger.setAttribute('aria-expanded', String(bell.open));
        if (bell.open) refresh(true);
    });
    bell.querySelector('[data-notification-close]').addEventListener('click', () => {
        bell.open = false;
        trigger.focus();
    });
    document.addEventListener('click', event => {
        if (bell.open && !bell.contains(event.target)) bell.open = false;
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && bell.open) {
            bell.open = false;
            trigger.focus();
        }
    });
    bell.addEventListener('focusout', event => {
        if (event.relatedTarget && !bell.contains(event.relatedTarget)) bell.open = false;
    });
    bell.querySelectorAll('[data-notification-filter]').forEach(button => {
        button.addEventListener('click', () => {
            filter = button.dataset.notificationFilter;
            bell.querySelectorAll('[data-notification-filter]').forEach(tab => {
                const selected = tab === button;
                tab.classList.toggle('is-active', selected);
                tab.setAttribute('aria-pressed', String(selected));
            });
            refresh(true);
        });
    });
    bell.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('[data-notification-read], [data-notification-all]')) return;
        event.preventDefault();
        if (writing) return;
        pending?.abort();
        pending = null;
        writing = true;
        const button = form.querySelector('button');
        button.disabled = true;
        error.hidden = true;
        try {
            const response = await fetch(internalUrl(form.action), {method: 'PATCH', credentials: 'same-origin', headers: {Accept: 'application/json', 'X-CSRF-TOKEN': csrf}});
            if (!response.ok) throw new Error('Unable to mark as read');
            const result = await response.json();
            updateCount(result.unread_count);
            if (form.matches('[data-notification-read]')) {
                window.location.assign(internalUrl(result.url));
            } else {
                live.textContent = 'تم تعليم جميع الإشعارات كمقروءة.';
                if (document.querySelector('.parent-notification-feed')) window.location.reload();
                else {
                    writing = false;
                    await refresh(true);
                }
            }
        } catch {
            error.textContent = 'تعذّر حفظ القراءة. حاول مرة أخرى.';
            error.hidden = false;
        } finally {
            writing = false;
            button.disabled = false;
        }
    });

    updateCount(state.unread_count);
    updateFollowupCounts();
    trigger.setAttribute('aria-expanded', String(bell.open));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    let interval;
    const startPolling = () => {
        window.clearInterval(interval);
        interval = window.setInterval(() => refresh(), 30000);
    };
    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            writing = false;
            refresh(true);
            startPolling();
        }
    });
    startPolling();
    window.addEventListener('pagehide', () => { pending?.abort(); window.clearInterval(interval); });
})();
