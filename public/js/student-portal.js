const resetStudentActionForm = (form) => {
    form.removeAttribute('aria-busy');

    const button = form.querySelector('button[type="submit"]');
    if (!button) {
        return;
    }

    button.disabled = false;
    if (button.dataset.defaultLabel) {
        button.textContent = button.dataset.defaultLabel;
    }
};

document.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-student-action-form]');
    if (!form) {
        return;
    }

    if (form.getAttribute('aria-busy') === 'true') {
        event.preventDefault();

        return;
    }

    const button = form.querySelector('button[type="submit"]');
    form.setAttribute('aria-busy', 'true');

    if (button) {
        button.dataset.defaultLabel ||= button.textContent.trim();
        button.disabled = true;
        button.textContent = button.dataset.loadingText || 'جارٍ التنفيذ...';
    }
});

document.addEventListener('change', (event) => {
    const input = event.target.closest('[data-file-input]');
    if (!input) {
        return;
    }

    const fileName = input.closest('.file-upload-control')?.querySelector('[data-file-name]');
    if (fileName) {
        fileName.textContent = input.files?.[0]?.name || 'لم يتم اختيار ملف';
    }
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-student-action-form]').forEach(resetStudentActionForm);
});
