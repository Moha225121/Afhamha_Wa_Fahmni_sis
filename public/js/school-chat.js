(() => {
    document.querySelectorAll('[data-chat-search]').forEach(search => {
        const container = search.closest('.school-chat');
        search.addEventListener('input', () => {
            const query = search.value.trim().toLocaleLowerCase('ar');
            let matches = 0;
            container.querySelectorAll('[data-chat-contact]').forEach(contact => {
                contact.hidden = !contact.dataset.chatSearchText.toLocaleLowerCase('ar').includes(query);
                if (!contact.hidden) matches++;
            });
            container.querySelector('[data-chat-no-match]').hidden = matches > 0 || !query;
        });
    });

    const room = document.querySelector('[data-chat-room]');
    if (!room) return;
    const history = room.querySelector('[data-chat-history]');
    const form = room.querySelector('[data-chat-form]');
    const input = room.querySelector('[data-chat-body]');
    const older = room.querySelector('[data-chat-older]');
    const jump = room.querySelector('[data-chat-jump]');
    const error = room.querySelector('[data-chat-error]');
    const live = room.querySelector('[data-chat-live]');
    const records = new Map(JSON.parse(document.getElementById('chat-initial-messages').textContent).map(item => [item.id, item]));
    let lastPolledId = Math.max(0, ...records.keys());
    let polling = false;
    let sending = false;
    let followBottom = true;
    let timer;
    let controller;

    const nearBottom = () => history.scrollHeight - history.scrollTop - history.clientHeight < 100;
    const bottom = () => { history.scrollTop = history.scrollHeight; jump.hidden = true; followBottom = true; };
    const make = (tag, className, text) => { const element = document.createElement(tag); element.className = className; if (text !== undefined) element.textContent = text; return element; };
    const endpoint = (value) => { const url = new URL(value, location.origin); if (url.origin !== location.origin) throw new Error('Invalid destination'); return url; };

    function render(stick, prepend = false) {
        const top = history.scrollTop;
        const height = history.scrollHeight;
        const fragment = document.createDocumentFragment();
        let day = null;
        [...records.values()].sort((a, b) => a.id - b.id).forEach(item => {
            if (item.day !== day) {
                const date = new Date(`${item.day}T12:00:00`);
                fragment.append(make('p', 'chat-day', new Intl.DateTimeFormat('ar-LY', {dateStyle: 'long'}).format(date)));
                day = item.day;
            }
            const bubble = make('article', `chat-bubble${item.is_mine ? ' is-mine' : ''}`);
            bubble.dataset.messageId = item.id;
            const body = make('p', '', item.body);
            body.dir = 'auto';
            const time = make('time', '', item.time);
            time.dateTime = item.created_at;
            bubble.append(body, time);
            fragment.append(bubble);
        });
        if (!records.size) fragment.append(make('p', 'chat-start', 'ابدأ المحادثة بكتابة رسالتك في الأسفل.'));
        history.replaceChildren(fragment);
        if (stick) bottom();
        else history.scrollTop = prepend ? top + history.scrollHeight - height : top;
    }

    function resizeRoom() {
        const nav = document.querySelector('.bottom-nav');
        const height = window.visualViewport?.height || innerHeight;
        const available = height - room.getBoundingClientRect().top - (nav?.getBoundingClientRect().height || 0) - 14;
        room.classList.toggle('is-compact', available < 260);
        room.style.height = `${Math.max(120, available)}px`;
        if (followBottom) requestAnimationFrame(bottom);
    }

    async function refresh() {
        if (document.hidden || polling || sending) return;
        polling = true;
        controller = new AbortController();
        try {
            const stick = nearBottom();
            const url = endpoint(room.dataset.pollUrl);
            url.searchParams.set('after', lastPolledId);
            if (stick) url.searchParams.set('read', '1');
            const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
            if (!response.ok) throw new Error('Refresh failed');
            const result = await response.json();
            if (error.dataset.source === 'refresh') error.hidden = true;
            const stayAtBottom = nearBottom();
            let added = 0;
            result.messages.forEach(item => { if (!records.has(item.id)) added++; records.set(item.id, item); });
            lastPolledId = Math.max(lastPolledId, ...result.messages.map(item => item.id));
            if (added) {
                render(stayAtBottom);
                if (!stayAtBottom) jump.hidden = false;
                live.textContent = 'وصلت رسائل جديدة إلى المحادثة.';
            }
            if (form) form.hidden = result.status !== 'open';
            room.querySelector('[data-chat-closed]').hidden = result.status === 'open';
        } catch (failure) {
            if (failure.name !== 'AbortError') { error.dataset.source = 'refresh'; error.textContent = 'تعذّر تحديث الرسائل. سنحاول مجددًا.'; error.hidden = false; }
        } finally { polling = false; }
    }

    form?.addEventListener('submit', async event => {
        event.preventDefault();
        if (sending || !input.value.trim()) return;
        sending = true;
        input.readOnly = true;
        const button = form.querySelector('button');
        button.disabled = true;
        error.hidden = true;
        error.dataset.source = 'send';
        try {
            const response = await fetch(endpoint(form.action), {method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json'}, body: new FormData(form)});
            const result = await response.json();
            if (!response.ok) {
                error.textContent = response.status === 422 ? (result.errors?.body?.[0] || 'يرجى التحقق من نص الرسالة.') : 'لم ترسل الرسالة. يرجى تحديث المحادثة والمحاولة مجددًا.';
                error.hidden = false;
                return;
            }
            records.set(result.message.id, result.message);
            input.value = '';
            input.style.height = '';
            render(true);
            live.textContent = 'تم إرسال الرسالة.';
            input.focus({preventScroll: true});
        } catch {
            error.textContent = 'تعذّر التأكد من إرسال الرسالة. حدّث المحادثة قبل المحاولة مجددًا.';
            error.hidden = false;
        } finally { sending = false; input.readOnly = false; button.disabled = false; }
    });

    input?.addEventListener('input', () => { input.style.height = 'auto'; input.style.height = `${Math.min(130, input.scrollHeight)}px`; });
    input?.addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && !matchMedia('(pointer: coarse)').matches) { event.preventDefault(); form.requestSubmit(); }
    });
    older.addEventListener('click', async () => {
        older.disabled = true;
        try {
            const url = endpoint(room.dataset.pollUrl);
            url.searchParams.set('before', Math.min(...records.keys()));
            const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok) throw new Error('History failed');
            const result = await response.json();
            result.messages.forEach(item => records.set(item.id, item));
            render(false, true);
            older.hidden = !result.has_older;
        } catch { error.textContent = 'تعذّر تحميل الرسائل الأقدم. حاول مرة أخرى.'; error.hidden = false; }
        finally { older.disabled = false; }
    });
    jump.addEventListener('click', () => { bottom(); refresh(); });
    history.addEventListener('scroll', () => { followBottom = nearBottom(); if (followBottom) jump.hidden = true; });
    window.addEventListener('resize', resizeRoom);
    window.visualViewport?.addEventListener('resize', resizeRoom);
    room.addEventListener('animationend', resizeRoom);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    const start = () => { clearInterval(timer); timer = setInterval(refresh, 7000); };
    window.addEventListener('pagehide', () => { clearInterval(timer); controller?.abort(); });
    window.addEventListener('pageshow', event => { if (event.persisted) { polling = false; resizeRoom(); start(); refresh(); } });
    resizeRoom();
    bottom();
    start();
})();
