/* Shared by the FCM module, classic parent worker and authenticated pages. */
globalThis.SchoolPushPrivacy = (() => {
    const currentRecipient = async () => {
        try {
            const response = await fetch('/fcm-devices/status', {
                credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' },
            });
            if (!response.ok) return null;
            const state = await response.json();
            return state.user_id && /^(admin|teacher|student|parent)$/.test(state.role)
                ? { id: String(state.user_id), role: state.role } : null;
        } catch { return null; }
    };
    const closeStale = async (registration, recipientId) => {
        const notifications = await registration.getNotifications();
        for (const notification of notifications) {
            if (!recipientId || String(notification.data?.recipient_user_id || '') !== String(recipientId)) notification.close();
        }
    };
    const destination = (data, recipient, origin) => {
        try {
            const url = new URL(data.url || '/login', origin);
            if (recipient && String(data.recipient_user_id || '') === recipient.id && url.origin === origin
                && (url.pathname === '/' + recipient.role || url.pathname.startsWith('/' + recipient.role + '/'))) return url.href;
        } catch { /* Untrusted or obsolete destinations fall back to authentication. */ }
        return new URL('/login', origin).href;
    };
    const show = async (registration, data, defaults = {}) => {
        const recipient = await currentRecipient();
        await closeStale(registration, recipient?.id);
        if (!recipient || String(data.recipient_user_id || '') !== recipient.id) return false;
        await registration.showNotification(data.title || 'إشعار جديد', {
            ...defaults, body: data.body || '',
            ...(data.event_id ? { tag: 'school-event:' + recipient.id + ':' + data.event_id } : {}),
            data: { url: data.url || '/login', recipient_user_id: recipient.id },
        });
        return true;
    };
    return { currentRecipient, closeStale, destination, show };
})();
