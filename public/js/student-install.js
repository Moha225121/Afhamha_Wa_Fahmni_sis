(() => {
    const buttons = [...document.querySelectorAll('[data-install-app]')];
    const dialog = document.getElementById('student-install-dialog');
    if (!buttons.length || !dialog) return;

    const message = dialog.querySelector('[data-install-message]');
    const steps = dialog.querySelector('[data-install-steps]');
    const displayMode = window.matchMedia('(display-mode: standalone)');
    let deferredPrompt = null;
    let installed = false;
    let prompting = false;

    const isStandalone = () => displayMode.matches || navigator.standalone === true;
    const updateButtons = () => buttons.forEach(button => {
        button.hidden = installed || isStandalone();
        button.disabled = prompting;
    });

    const showHelp = () => {
        const ios = /iphone|ipad|ipod/i.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const android = /android/i.test(navigator.userAgent);
        message.textContent = ios
            ? 'لتثبيت تطبيق الطالب على iPhone أو iPad:'
            : 'لم يعرض المتصفح نافذة التثبيت التلقائي. يمكنك تثبيته يدويًا:';
        const instructions = ios
            ? ['افتح هذه الصفحة في Safari.', 'اضغط زر المشاركة.', 'اختر «إضافة إلى الشاشة الرئيسية»، ثم اضغط «إضافة».']
            : android
                ? ['افتح قائمة المتصفح ⋮.', 'اختر «تثبيت التطبيق» أو «إضافة إلى الشاشة الرئيسية».', 'أكد التثبيت.']
                : ['افتح قائمة المتصفح في Chrome أو Edge.', 'اختر «تثبيت افهمها وفهمني - الطالب» أو Apps ثم Install.', 'أكد التثبيت ليظهر تطبيق الطالب على سطح المكتب.'];
        steps.replaceChildren(...instructions.map(text => {
            const item = document.createElement('li');
            item.textContent = text;
            return item;
        }));
        if (!dialog.open) dialog.showModal();
    };

    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();
        deferredPrompt = event;
        updateButtons();
    });

    buttons.forEach(button => button.addEventListener('click', async () => {
        if (installed || isStandalone()) { updateButtons(); return; }
        if (prompting) return;
        if (!deferredPrompt) { showHelp(); return; }

        const prompt = deferredPrompt;
        deferredPrompt = null; // A browser install prompt can only be used once.
        prompting = true;
        updateButtons();
        try {
            await prompt.prompt();
            const choice = await prompt.userChoice;
            if (choice.outcome === 'accepted') installed = true;
        } catch {
            showHelp();
        } finally {
            prompting = false;
            updateButtons();
        }
    }));

    window.addEventListener('appinstalled', () => {
        installed = true;
        deferredPrompt = null;
        if (dialog.open) dialog.close();
        updateButtons();
    });
    displayMode.addEventListener('change', updateButtons);
    window.addEventListener('pageshow', updateButtons);
    updateButtons();

    if ('serviceWorker' in navigator && window.isSecureContext) {
        const register = () => navigator.serviceWorker.register(dialog.dataset.serviceWorkerUrl, {
            scope: dialog.dataset.serviceWorkerScope,
        }).catch(() => {});
        if (document.readyState === 'complete') register();
        else window.addEventListener('load', register, {once: true});
    }
})();
