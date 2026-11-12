// Keep the live form and selected files after a failed transport. A retry uses
// the same server-side request key; files are resent, not resumed byte by byte.
(() => {
    const form = document.querySelector('[data-lesson-save]');
    if (!form || !window.fetch || !window.FormData) return;
    const status = form.querySelector('[data-save-status]');
    const buttons = [...form.querySelectorAll('button[type="submit"]')];
    const controls = [...form.querySelectorAll('input, select, textarea, button[type="button"]')];
    const editor = form.querySelector('[contenteditable]');
    let sending = false;
    let pending = null;
    let originalSubmitter = null;
    let refreshToken = false;
    const lockFields = locked => {
        controls.forEach(control => control.disabled = locked);
        if (editor) editor.contentEditable = locked ? 'false' : 'true';
    };
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (sending) return;
        const files = [...(form.querySelector('[name="attachments[]"]')?.files || [])];
        if (files.some(file => file.size > 20 * 1024 * 1024) || files.reduce((sum, file) => sum + file.size, 0) > 22 * 1024 * 1024) {
            status.textContent = 'الحد الأقصى 20 ميجابايت للملف و22 ميبيبايت لمجموع المرفقات.';
            return;
        }
        if (!pending) {
            pending = new FormData(form);
            originalSubmitter = event.submitter;
            if (originalSubmitter?.name) pending.set(originalSubmitter.name, originalSubmitter.value);
        }
        const data = pending;
        sending = true;
        lockFields(true);
        buttons.forEach(button => button.disabled = true);
        form.setAttribute('aria-busy', 'true');
        status.textContent = 'جارٍ حفظ الدرس ورفع المرفقات…';
        try {
            if (refreshToken) {
                const page = await fetch(location.href, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
                const document = new DOMParser().parseFromString(await page.text(), 'text/html');
                const token = document.querySelector('[data-lesson-save] [name="_token"]')?.value;
                if (!page.ok || !token) throw new Error('سجّل الدخول في تبويب آخر أولًا، ثم أعد المحاولة هنا. الطلب والمرفقات محفوظان في هذه الصفحة.');
                data.set('_token', token);
                form.querySelector('[name="_token"]').value = token;
                refreshToken = false;
            }
            const response = await fetch(form.action, {
                method: 'POST', body: data, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const result = response.headers.get('content-type')?.includes('application/json') ? await response.json() : null;
            if (!response.ok) {
                const errors = result?.errors ? Object.values(result.errors).flat().join(' ') : null;
                if (response.status === 422 || response.status === 413) {
                    pending = null;
                    lockFields(false);
                }
                if (response.status === 419 || response.status === 401) refreshToken = true;
                throw new Error(errors || (response.status === 419 || response.status === 401
                    ? 'انتهت الجلسة. افتح تسجيل الدخول في تبويب آخر ثم أعد المحاولة هنا للحفاظ على المرفقات.'
                    : response.status === 413 ? 'حجم الطلب أكبر من حد الخادم. قلل حجم المرفقات ثم أعد المحاولة.'
                    : 'تعذّر تأكيد الحفظ. احتفظ بهذه الصفحة ثم أعد المحاولة.'));
            }
            const destination = result?.redirect ? new URL(result.redirect, location.href) : null;
            if (!destination || destination.origin !== location.origin) throw new Error('تعذّر تأكيد الحفظ. أعد المحاولة من هذه الصفحة.');
            location.assign(destination.href);
        } catch (error) {
            status.textContent = error instanceof TypeError
                ? 'انقطع الاتصال. بيانات الدرس والمرفقات ما زالت هنا. بعد عودة الاتصال اضغط الحفظ لإعادة إرسال نفس الطلب بأمان. التعديل متاح بعد تأكيد الحفظ.'
                : error.message;
            sending = false;
            form.removeAttribute('aria-busy');
            buttons.forEach(button => button.disabled = pending !== null && button !== originalSubmitter);
        }
    });
})();
