(() => {
    let activeRequest;

    async function updateCatalog(url, { historyMode = 'push', focusIndex = null } = {}) {
        activeRequest?.abort();
        const controller = new AbortController();
        activeRequest = controller;

        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (!response.ok || response.redirected) {
                window.location.assign(url);
                return;
            }

            const html = await response.text();
            const nextDocument = new DOMParser().parseFromString(html, 'text/html');
            const currentCatalog = document.querySelector('.library-catalog');
            const nextCatalog = nextDocument.querySelector('.library-catalog');
            if (!currentCatalog || !nextCatalog) {
                window.location.assign(url);
                return;
            }

            currentCatalog.replaceWith(nextCatalog);
            document.title = nextDocument.title || document.title;
            if (historyMode === 'push') history.pushState({ libraryCatalog: true }, '', url);

            if (focusIndex !== null) {
                const chips = [...nextCatalog.querySelectorAll('.lib-filter-chip')];
                const focusTarget = chips[Math.min(focusIndex, chips.length - 1)]
                    || nextCatalog.querySelector('#library-results-heading');
                if (focusTarget) {
                    if (!focusTarget.hasAttribute('tabindex')) focusTarget.setAttribute('tabindex', '-1');
                    focusTarget.focus({ preventScroll: true });
                }
            }
        } catch (error) {
            if (error.name !== 'AbortError') window.location.assign(url);
        } finally {
            if (activeRequest === controller) activeRequest = null;
        }
    }

    document.addEventListener('click', event => {
        const link = event.target.closest('.library-catalog .lib-filter-chip, .library-catalog .lib-clear-link');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (link.origin !== window.location.origin) return;

        event.preventDefault();
        const chips = [...link.closest('.lib-active-filters').querySelectorAll('.lib-filter-chip')];
        const index = chips.indexOf(link);
        void updateCatalog(link.href, { focusIndex: index < 0 ? 0 : index });
    });

    window.addEventListener('popstate', () => {
        void updateCatalog(window.location.href, { historyMode: 'none' });
    });
})();
