document.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-tutor-form]');

    if (!form) {
        return;
    }

    if (form.getAttribute('aria-busy') === 'true') {
        event.preventDefault();

        return;
    }

    const button = form.querySelector('button[type="submit"]');
    const status = form.querySelector('.sending-status');

    form.setAttribute('aria-busy', 'true');

    if (button) {
        button.dataset.submitLabel ||= button.textContent.trim();
        button.disabled = true;
        button.textContent = button.dataset.sendingLabel || 'جارٍ الإرسال...';
    }

    if (status) {
        status.textContent = form.dataset.sendingMessage || 'يتم إرسال الطلب بأمان عبر الخادم.';
    }
});

window.addEventListener('pageshow', (event) => {
    if (event.persisted && document.querySelector('[data-tutor-form]')) {
        window.location.reload();

        return;
    }

    document.querySelectorAll('[data-tutor-form]').forEach((form) => {
        form.removeAttribute('aria-busy');

        const button = form.querySelector('button[type="submit"]');
        const status = form.querySelector('.sending-status');

        if (button) {
            button.disabled = false;
            button.textContent = button.dataset.submitLabel || button.textContent;
        }

        if (status) {
            status.textContent = '';
        }
    });
});

document.addEventListener('click', (event) => {
    const retryButton = event.target.closest('[data-tutor-retry]');

    if (!retryButton) {
        return;
    }

    const message = retryButton.closest('.chat-message')?.querySelector('[data-tutor-message-content]')?.textContent;
    const composer = document.querySelector('.chat-composer[data-tutor-form]');
    const textarea = composer?.querySelector('textarea[name="message"]');
    const requestId = composer?.querySelector('input[name="request_id"]');
    const status = composer?.querySelector('.sending-status');

    if (!textarea || !requestId || typeof message !== 'string') {
        return;
    }

    textarea.value = message;
    requestId.value = retryButton.dataset.tutorRequestId || requestId.dataset.newRequestId || requestId.value;
    textarea.focus();

    if (status) {
        status.textContent = retryButton.dataset.tutorRequestId
            ? 'تم استعادة السؤال ومعرّف طلبه نفسه لمنع الإرسال المزدوج.'
            : 'تم استعادة السؤال. سيُرسل بمعرّف طلب جديد عند الضغط على زر الإرسال.';
    }
});
