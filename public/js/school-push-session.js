(() => {
    if (!('serviceWorker' in navigator) || !globalThis.SchoolPushPrivacy) return;
    const currentUser = document.currentScript?.dataset.userId || null;
    const clean = async (userId) => {
        const registrations = await navigator.serviceWorker.getRegistrations();
        await Promise.all(registrations.filter(registration => {
            const scope = new URL(registration.scope);
            return scope.origin === location.origin && ['/parent/', '/firebase-cloud-messaging-push-scope/'].includes(scope.pathname);
        }).map(registration => SchoolPushPrivacy.closeStale(registration, userId)));
    };
    clean(currentUser).catch(() => {});
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const action = new URL(form.action, location.origin);
        if (action.origin !== location.origin || action.pathname !== '/logout') return;
        event.preventDefault();
        // Bound the cleanup so an unavailable browser API cannot block logout.
        Promise.race([clean(null).catch(() => {}), new Promise(resolve => setTimeout(resolve, 1000))])
            .finally(() => HTMLFormElement.prototype.submit.call(form));
    });
})();
