/* Shared by the two existing workers. This file does not register another worker. */
(() => {
    const fallback = () => self.location.origin + (self.registration.scope?.endsWith('/parent/') ? '/parent/notifications' : '/notifications');
    const safeUrl = (value) => {
        try {
            const url = new URL(value || fallback(), self.location.origin);
            return url.origin === self.location.origin && /^https?:$/.test(url.protocol) && url.pathname !== '/' ? url.href : fallback();
        } catch { return fallback(); }
    };

    // An atomic IDB insert also deduplicates concurrent foreground tabs and worker scopes.
    const claim = (id) => new Promise((resolve) => {
        if (!id || !self.indexedDB) return resolve(true);
        const open = indexedDB.open('school-push-receipts', 1);
        open.onupgradeneeded = () => open.result.createObjectStore('seen', { keyPath: 'id' }).createIndex('at', 'at');
        open.onerror = () => resolve(true);
        open.onblocked = () => resolve(true);
        open.onsuccess = () => {
            const db = open.result;
            const transaction = db.transaction('seen', 'readwrite');
            const store = transaction.objectStore('seen');
            let fresh = false;
            const cursor = store.index('at').openCursor(IDBKeyRange.upperBound(Date.now() - 86400000));
            cursor.onsuccess = () => { if (cursor.result) { cursor.result.delete(); cursor.result.continue(); } };
            const add = store.add({ id, at: Date.now() });
            add.onsuccess = () => { fresh = true; };
            add.onerror = (event) => { event.preventDefault(); event.stopPropagation(); };
            transaction.oncomplete = () => { db.close(); resolve(fresh); };
            transaction.onerror = () => { db.close(); resolve(true); };
            transaction.onabort = () => { db.close(); resolve(true); };
        };
    });

    self.schoolPush = {
        safeUrl,
        isFirebase: (payload) => Boolean(payload && payload.fcmMessageId),
        async show(payload) {
            if (!await claim(payload.notification_id)) return;
            await self.registration.showNotification(payload.title || 'النسبية', {
                body: payload.body || 'لديك إشعار جديد.',
                icon: '/school-branding/icon-192.png?v=20261029-nasbia',
                badge: '/school-branding/icon-192.png?v=20261029-nasbia',
                tag: payload.notification_id || undefined,
                data: { url: safeUrl(payload.url) },
                dir: 'rtl', lang: 'ar',
            });
            if (['teacher_notification', 'conversation_message'].includes(payload.category) && typeof payload.receipt_url === 'string') {
                try {
                    const receipt = new URL(payload.receipt_url, self.location.origin);
                    if (receipt.origin === self.location.origin && /^https?:$/.test(receipt.protocol)
                        && /^\/teacher-message-receipts\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/delivered$/i.test(receipt.pathname)
                        && /^[0-9a-f]{64}$/i.test(receipt.searchParams.get('signature') || '')) {
                        await self.fetch(receipt.href, {method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json'}});
                    }
                } catch (_) { /* A delayed acknowledgement must not interrupt notification display. */ }
            }
        },
    };
    // Register BEFORE the Firebase SDK: its listener otherwise consumes any JSON push,
    // including the legacy payload, and could display a second, empty notification.
    self.addEventListener('push', (event) => {
        let payload = {};
        try { payload = event.data ? event.data.json() : {}; } catch (_) {}
        if (self.schoolPush.isFirebase(payload)) return;
        event.stopImmediatePropagation();
        event.waitUntil(self.schoolPush.show({ url: fallback(), ...payload }));
    });
    self.addEventListener('message', (event) => {
        if (event.data?.type === 'SCHOOL_FOREGROUND_PUSH') event.waitUntil(self.schoolPush.show(event.data.payload || {}));
    });
    self.addEventListener('notificationclick', (event) => {
        if (event.notification.data?.FCM_MSG) return; // Let the SDK handle Console notification messages.
        event.notification.close();
        const target = safeUrl(event.notification.data?.url);
        event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const existing = windows.find((client) => client.url === target);
            return existing ? existing.focus() : self.clients.openWindow(target);
        }));
    });
})();
