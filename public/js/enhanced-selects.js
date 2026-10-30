(function () {
    const selector = 'select:not([multiple]):not([size]):not([data-native-select])';
    let active = null;
    let counter = 0;

    function selectedOption(select) {
        return select.options[select.selectedIndex] || select.options[0];
    }

    function optionLabel(state, option) {
        if (!option) return '';
        return state.unreadBadges
            ? (option.getAttribute('data-option-label') ?? option.textContent).trim()
            : option.textContent.trim();
    }

    function renderOptionLabel(state, target, option, trigger = false) {
        if (!state.unreadBadges) {
            target.textContent = option ? (trigger ? option.textContent.trim() : option.textContent) : '';
            return;
        }

        const label = optionLabel(state, option);
        const rawCount = option?.getAttribute('data-unread-count') ?? '0';
        const count = /^(?:0|[1-9][0-9]*)$/.test(rawCount) && Number.isSafeInteger(Number(rawCount)) ? Number(rawCount) : 0;
        const name = document.createElement('span');
        name.className = 'select-ui__option-label';
        name.textContent = label;
        target.replaceChildren(name);
        const prefix = trigger ? `${state.select.getAttribute('aria-label') || 'الابن'}: ` : '';
        target.setAttribute('aria-label', prefix + label + (count > 0 ? `، ${count} رسائل غير مقروءة` : '، لا توجد رسائل غير مقروءة'));

        if (count > 0) {
            const badge = document.createElement('span');
            badge.className = 'select-ui__unread-badge';
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.setAttribute('aria-hidden', 'true');
            target.appendChild(badge);
        }
    }

    function closeActive(restoreFocus) {
        if (!active) {
            return;
        }

        active.ui.classList.remove('select-ui--open');
        active.button.setAttribute('aria-expanded', 'false');
        active.menu.classList.remove('open');

        if (active.search) {
            active.search.value = '';
            syncMenu(active);
        }

        if (restoreFocus) {
            active.button.focus();
        }

        active = null;
    }

    function positionMenu(state) {
        const rect = state.button.getBoundingClientRect();
        const gap = 8;
        const availableBelow = window.innerHeight - rect.bottom - gap;
        const availableAbove = rect.top - gap;
        const openAbove = availableBelow < 180 && availableAbove > availableBelow;
        const maxHeight = Math.max(160, Math.min(280, openAbove ? availableAbove - gap : availableBelow - gap));

        state.menu.style.width = `${rect.width}px`;
        state.menu.style.left = `${rect.left}px`;
        state.menu.style.top = openAbove ? `${Math.max(gap, rect.top - maxHeight - gap)}px` : `${rect.bottom + gap}px`;
        state.menu.style.maxHeight = `${maxHeight}px`;
    }

    function syncButton(state) {
        const option = selectedOption(state.select);
        renderOptionLabel(state, state.button, option, true);
        state.button.disabled = state.select.disabled;
        state.button.classList.toggle('is-placeholder', !state.select.value);
    }

    function syncMenu(state) {
        const list = state.list || state.menu;
        const query = state.search ? state.search.value.trim().toLocaleLowerCase() : '';
        list.innerHTML = '';
        let visibleCount = 0;

        Array.from(state.select.options).forEach((option, index) => {
            if (option.hidden) {
                return;
            }

            if (query && !optionLabel(state, option).toLocaleLowerCase().includes(query)) {
                return;
            }

            const item = document.createElement('button');
            item.type = 'button';
            item.className = `select-ui__option${state.unreadBadges ? ' select-ui__option--unread-badges' : ''}`;
            renderOptionLabel(state, item, option);
            item.disabled = option.disabled;
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
            if (state.unreadBadges) item.setAttribute('data-select-option-index', String(index));

            if (option.selected) {
                item.classList.add('is-selected');
            }

            item.addEventListener('click', () => {
                if (option.disabled) {
                    return;
                }

                state.select.selectedIndex = index;
                syncButton(state);
                syncMenu(state);
                closeActive(false);
                state.select.dispatchEvent(new Event('change', { bubbles: true }));
                state.button.focus();
            });

            list.appendChild(item);
            visibleCount += 1;
        });

        if (state.search && visibleCount === 0) {
            const empty = document.createElement('p');
            empty.className = 'select-ui__empty';
            empty.textContent = 'لا توجد نتائج مطابقة';
            list.appendChild(empty);
        }
    }

    function focusOption(menu, direction) {
        const options = Array.from(menu.querySelectorAll('.select-ui__option:not(:disabled):not([hidden])'));
        if (!options.length) {
            return;
        }

        const currentIndex = options.indexOf(document.activeElement);
        let nextIndex = 0;

        if (direction === 'first') {
            nextIndex = 0;
        } else if (direction === 'last') {
            nextIndex = options.length - 1;
        } else if (currentIndex >= 0) {
            nextIndex = direction === 'previous'
                ? Math.max(0, currentIndex - 1)
                : Math.min(options.length - 1, currentIndex + 1);
        }

        options[nextIndex].focus();
    }

    function openSelect(state) {
        if (active && active !== state) {
            closeActive(false);
        }

        syncMenu(state);
        state.ui.classList.add('select-ui--open');
        state.button.setAttribute('aria-expanded', 'true');
        state.menu.classList.add('open');
        positionMenu(state);
        active = state;

        if (state.search) {
            state.search.focus();
            state.search.select();
            return;
        }

        const selected = state.menu.querySelector('.is-selected:not(:disabled)');
        (selected || state.menu.querySelector('.select-ui__option:not(:disabled)'))?.focus();
    }

    function enhance(select) {
        if (select.dataset.enhancedSelect === 'true') {
            return;
        }

        select.dataset.enhancedSelect = 'true';
        select.classList.add('native-select-hidden');

        const ui = document.createElement('span');
        const button = document.createElement('button');
        const menu = document.createElement('div');
        const id = `select-ui-${counter++}`;
        const state = { select, ui, button, menu, searchable: select.hasAttribute('data-searchable'), unreadBadges: select.hasAttribute('data-unread-badges'), search: null, list: null };

        ui.className = 'select-ui';
        button.type = 'button';
        button.className = `select-ui__button${state.unreadBadges ? ' select-ui__button--unread-badges' : ''}`;
        button.setAttribute('aria-haspopup', 'listbox');
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-controls', id);

        menu.id = id;
        menu.className = `select-ui__menu${state.searchable ? ' select-ui__menu--searchable' : ''}`;
        menu.setAttribute('role', 'listbox');

        if (state.searchable) {
            state.search = document.createElement('input');
            state.search.type = 'search';
            state.search.className = 'select-ui__search';
            state.search.placeholder = 'اكتب للبحث...';
            state.search.setAttribute('aria-label', 'البحث في الخيارات');
            state.list = document.createElement('div');
            state.list.className = 'select-ui__checkbox-list';
            menu.append(state.search, state.list);
            state.search.addEventListener('input', () => syncMenu(state));
        }

        ui.appendChild(button);
        select.insertAdjacentElement('afterend', ui);
        document.body.appendChild(menu);

        syncButton(state);

        button.addEventListener('click', () => {
            if (select.disabled) {
                return;
            }

            if (active === state) {
                closeActive(false);
            } else {
                openSelect(state);
            }
        });

        button.addEventListener('keydown', (event) => {
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();
                openSelect(state);
            }
        });

        menu.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeActive(true);
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                focusOption(menu, 'next');
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                focusOption(menu, 'previous');
            }

            if (event.key === 'Home') {
                event.preventDefault();
                focusOption(menu, 'first');
            }

            if (event.key === 'End') {
                event.preventDefault();
                focusOption(menu, 'last');
            }
        });

        select.addEventListener('change', () => {
            syncButton(state);
            syncMenu(state);
        });

        new MutationObserver(() => {
            const focusedIndex = state.unreadBadges && active === state
                ? document.activeElement?.getAttribute('data-select-option-index') : null;
            syncButton(state);
            if (active === state) {
                syncMenu(state);
                positionMenu(state);
                if (focusedIndex !== null) state.menu.querySelector(`[data-select-option-index="${focusedIndex}"]`)?.focus();
            }
        }).observe(select, { childList: true, subtree: true, attributes: true });
    }

    function init() {
        document.querySelectorAll(selector).forEach(enhance);
    }

    document.addEventListener('click', (event) => {
        if (!active) {
            return;
        }

        if (!active.ui.contains(event.target) && !active.menu.contains(event.target)) {
            closeActive(false);
        }
    });

    document.addEventListener('enhanced-selects:init', init);

    window.addEventListener('resize', () => active && positionMenu(active));
    window.addEventListener('scroll', () => active && positionMenu(active), true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
