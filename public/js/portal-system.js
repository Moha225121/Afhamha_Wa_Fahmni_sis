document.addEventListener('DOMContentLoaded', () => {
    const content = document.querySelector('main');
    const backButton = document.createElement('button');
    backButton.type = 'button';
    backButton.className = 'portal-back-button';
    backButton.setAttribute('aria-label', 'الرجوع إلى الصفحة السابقة');
    backButton.innerHTML = '<span aria-hidden="true">↩</span><span>رجوع</span>';
    backButton.addEventListener('click', () => {
        if (window.history.length > 1) {
            window.history.back();
            return;
        }

        const referrer = document.referrer;
        if (referrer) {
            try {
                if (new URL(referrer).origin === window.location.origin) {
                    window.location.assign(referrer);
                    return;
                }
            } catch (_) {
                // Fall through to the site root when the referrer is not a valid URL.
            }
        }

        window.location.assign('/');
    });
    (content || document.body).prepend(backButton);

    const menu = document.getElementById('menu');
    const sidebar = document.getElementById('sidebar');
    if (menu && sidebar) {
        const backdrop = document.createElement('button');
        backdrop.className = 'nav-backdrop';
        backdrop.type = 'button';
        backdrop.hidden = true;
        backdrop.setAttribute('aria-label', 'إغلاق القائمة');
        document.body.append(backdrop);
        menu.setAttribute('aria-controls', 'sidebar');
        menu.setAttribute('aria-expanded', 'false');
        menu.setAttribute('aria-label', 'فتح القائمة');
        const mobile = window.matchMedia('(max-width: 768px)');
        const toggle = (open, restoreFocus = false) => {
            sidebar.classList.toggle('open', open);
            document.body.classList.toggle('nav-open', open && mobile.matches);
            backdrop.hidden = !open || !mobile.matches;
            menu.setAttribute('aria-expanded', String(open));
            menu.setAttribute('aria-label', open ? 'إغلاق القائمة' : 'فتح القائمة');
            sidebar.inert = mobile.matches && !open;
            if (restoreFocus) menu.focus();
        };
        menu.onclick = () => toggle(!sidebar.classList.contains('open'));
        backdrop.onclick = () => toggle(false, true);
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && sidebar.classList.contains('open')) toggle(false, true);
        });
        mobile.addEventListener('change', () => toggle(false));
        toggle(false);
    }
    document.addEventListener('click', event => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-confirm]') : null;
        if (!trigger) return;
        const message = trigger.dataset.confirm || 'هل تريد المتابعة؟';
        event.stopImmediatePropagation();
        if (!window.confirm(message)) {
            event.preventDefault();
            return;
        }
        trigger.dataset.confirmHandled = 'true';
    }, true);
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const method = (form.querySelector('input[name="_method"]')?.value || form.method).toLowerCase();
        const submitter = event.submitter;
        const message = submitter?.dataset.confirm || form.dataset.confirm
            || (method === 'delete' ? 'هذا الإجراء يحذف العنصر. هل تريد المتابعة؟' : null);
        if (!message) return;
        if (submitter?.dataset.confirmHandled === 'true') {
            delete submitter.dataset.confirmHandled;
            return;
        }
        if (!window.confirm(message)) event.preventDefault();
    }, true);
    document.querySelectorAll('nav a.active').forEach(link => link.setAttribute('aria-current', 'page'));
    document.querySelectorAll('main table').forEach(table => {
        if (table.closest('.table-wrap, .portal-table-scroll') || table.closest('[style*="overflow"]')) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'portal-table-scroll';
        table.before(wrapper);
        wrapper.append(table);
    });
    document.querySelectorAll('.table-wrap, .portal-table-scroll').forEach(wrapper => {
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute('aria-label', 'جدول البيانات، يمكن تمريره أفقيًا');
    });
    document.querySelectorAll('.alert.success').forEach(alert => alert.setAttribute('role', 'status'));
    document.querySelectorAll('.alert.error').forEach(alert => alert.setAttribute('role', 'alert'));
});
