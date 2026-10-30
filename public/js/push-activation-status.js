// Keep login reminders and actionable load errors available even if Vite cannot run.
(() => {
    if (window.schoolPushActivation) return;
    const loadError = 'تعذر تحميل برنامج تفعيل الإشعارات. اضغط إعادة تحميل الصفحة للمحاولة، وإذا استمرت المشكلة فأبلغ إدارة المدرسة.';
    const blocked = 'إشعارات هذا الموقع محظورة. افتح إعدادات الموقع في المتصفح، واسمح بالإشعارات، ثم اضغط إعادة المحاولة.';
    let config;
    let reminder;
    let activate;
    let watchdog;
    let ready = false;
    let clientLoaded = false;
    let started = false;
    let reloadNeeded = false;
    let waitingClick = false;
    let lastMessage = '';
    let pendingProblem = false;
    let registered = false;
    const say = (message) => {
        lastMessage = message;
        document.querySelectorAll('[data-push-status]').forEach((status) => { status.textContent = message; });
    };
    const show = (message) => {
        say(message);
        if (!started || registered) return;
        if (reminder) return;
        if (!config?.loginId || !config?.userId) return;
        const key = `school-push-reminder:${config.userId}:${config.loginId}`;
        try {
            if (localStorage.getItem(key)) return;
            localStorage.setItem(key, 'shown');
        } catch { /* Still explain the problem when browser storage is unavailable. */ }
        reminder = document.createElement('aside');
        reminder.className = 'device-push-settings';
        reminder.dataset.pushReminder = '';
        reminder.setAttribute('role', 'region');
        reminder.setAttribute('aria-label', 'تفعيل الإشعارات');
        reminder.innerHTML = '<button type="button" class="push-reminder-close" data-push-dismiss aria-label="إغلاق تذكير الإشعارات">×</button><strong>إشعارات الجهاز</strong><p data-push-status role="status" aria-live="polite"></p><button type="button" data-push-reminder-enable>تفعيل الإشعارات</button>';
        reminder.querySelector('[data-push-status]').textContent = message;
        reminder.querySelector('[data-push-dismiss]').addEventListener('click', () => { reminder.hidden = true; });
        reminder.querySelector('[data-push-reminder-enable]').addEventListener('click', () => {
            if (activate) { void activate(true); return; }
            if (reloadNeeded) { location.reload(); return; }
            waitingClick = true;
            say('جارٍ تحميل برنامج تفعيل الإشعارات. انتظر اكتمال التحميل ثم اضغط التفعيل مجددًا.');
        });
        document.body.append(reminder);
        if (reloadNeeded) reminder.querySelector('[data-push-reminder-enable]').textContent = 'إعادة تحميل الصفحة';
        else if (window.Notification?.permission === 'denied') reminder.querySelector('[data-push-reminder-enable]').textContent = 'إعادة المحاولة';
    };
    const api = window.schoolPushActivation = {
        loaded() {
            clientLoaded = true;
        },
        bind(callback) {
            api.loaded();
            ready = true;
            clearTimeout(watchdog);
            activate = callback;
            reloadNeeded = false;
            if (reminder) reminder.querySelector('[data-push-reminder-enable]').textContent = window.Notification?.permission === 'denied' ? 'إعادة المحاولة' : 'تفعيل الإشعارات';
            if (waitingClick) say('اكتمل تحميل برنامج التفعيل. اضغط تفعيل الإشعارات للمتابعة.');
        },
        progress(message) { say(message); },
        failure(message, reload = false) {
            registered = false;
            pendingProblem = true;
            reloadNeeded = reload;
            show(message);
            if (reminder) reminder.querySelector('[data-push-reminder-enable]').textContent = reload ? 'إعادة تحميل الصفحة' : 'إعادة المحاولة';
        },
        registered() {
            registered = true;
            pendingProblem = false;
            if (reminder) reminder.hidden = true;
        },
    };
    const start = () => {
        started = true;
        const element = document.getElementById('school-push-config');
        try { config = element ? JSON.parse(element.textContent) : null; } catch { say('إعدادات الإشعارات غير صالحة. يرجى مراجعة إدارة المدرسة.'); }
        document.querySelectorAll('[data-push-panel]').forEach((panel) => {
            panel.querySelector('[data-push-enable]')?.addEventListener('click', () => {
                if (panel.dataset.pushInitialized) return;
                if (reloadNeeded) { location.reload(); return; }
                if (!element) { api.failure('إعدادات الإشعارات غير متاحة لهذا الحساب حاليًا. يرجى مراجعة إدارة المدرسة.', true); return; }
                waitingClick = true;
                say('جارٍ تحميل برنامج تفعيل الإشعارات. انتظر اكتمال التحميل ثم اضغط التفعيل مجددًا.');
            });
        });
        if (registered) return;
        if (lastMessage && pendingProblem) show(lastMessage);
        else if (window.Notification && Notification.permission !== 'granted') {
            show(Notification.permission === 'denied' ? blocked : 'فعّل الإشعارات لتصلك تنبيهات الواجبات والاختبارات والنتائج.');
        }
        if (!ready && config) watchdog = setTimeout(() => {
            if (!ready && !pendingProblem) api.failure(clientLoaded ? 'تعذر بدء برنامج تفعيل الإشعارات. أعد تحميل الصفحة للمحاولة.' : loadError, true);
        }, 15000);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
