(() => {
    let deferredPrompt = null;
    const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const buttons = () => [...document.querySelectorAll('[data-pwa-install]')];
    const ensureButton = () => {
        if (buttons().length || isStandalone()) return;
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'pwa-install-button'; button.dataset.pwaInstall = '';
        button.innerHTML = '<span aria-hidden="true">⇩</span><span>تثبيت التطبيق</span>';
        document.body.append(button);
    };
    const hide = () => buttons().forEach(button => { button.hidden = true; });
    const show = () => { if (!isStandalone()) buttons().forEach(button => { button.hidden = false; }); };

    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();
        deferredPrompt = event;
        show();
    });
    window.addEventListener('DOMContentLoaded', ensureButton);
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-pwa-install]');
        if (!button) return;
        if (isStandalone()) return hide();
        if (!deferredPrompt) {
            const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
            const message = ios
                ? 'في Safari اضغط «مشاركة» ثم «إضافة إلى الشاشة الرئيسية».'
                : 'افتح قائمة المتصفح واختر «تثبيت التطبيق» أو «إضافة إلى الشاشة الرئيسية».';
            window.alert(message);
            return;
        }
        deferredPrompt.prompt();
        await deferredPrompt.userChoice;
        deferredPrompt = null;
        hide();
    });
    window.addEventListener('appinstalled', () => { deferredPrompt = null; hide(); });
    if (isStandalone()) hide();
    window.addEventListener('load', () => {
        if ('serviceWorker' in navigator) navigator.serviceWorker.register('/app-sw.js', { scope: '/' }).catch(() => {});
    });
})();
