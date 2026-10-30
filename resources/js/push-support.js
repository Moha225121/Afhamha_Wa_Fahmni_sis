export function backgroundPayload(message) {
    // Notification messages (for example Console campaigns) are already shown by Firebase.
    return !message.notification && message.data?.notification_id ? message.data : null;
}

export function workerFor(pathname) {
    return pathname === '/parent' || pathname.startsWith('/parent/')
        ? { script: '/parent-sw.js', scope: '/parent/' }
        : { script: '/app-sw.js', scope: '/' };
}

export function applicationKey(value) {
    const padded = value + '='.repeat((4 - value.length % 4) % 4);
    return Uint8Array.from(atob(padded.replace(/-/g, '+').replace(/_/g, '/')), (char) => char.charCodeAt(0));
}

export function keyMatches(subscription, publicKey) {
    if (!subscription?.options?.applicationServerKey) return true;
    const current = new Uint8Array(subscription.options.applicationServerKey);
    const requested = applicationKey(publicKey);
    return current.length === requested.length && current.every((value, i) => value === requested[i]);
}

export function waitForWorker(registration) {
    const worker = registration.installing || registration.waiting || registration.active;
    if (worker?.state === 'activated') return Promise.resolve(registration);
    return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => { cleanup(); reject(new Error('worker-unavailable')); }, 15000);
        const cleanup = () => { clearTimeout(timeout); worker?.removeEventListener('statechange', changed); };
        const changed = () => {
            if (worker?.state === 'activated') { cleanup(); resolve(registration); }
            if (worker?.state === 'redundant') { cleanup(); reject(new Error('worker-unavailable')); }
        };
        worker?.addEventListener('statechange', changed);
        changed();
    });
}
