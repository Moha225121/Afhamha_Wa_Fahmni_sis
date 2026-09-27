(function () {
    const selector = 'select:not([multiple]):not([size]):not([data-native-select])';
    let active = null;
    let counter = 0;

    function selectedOption(select) {
        return select.options[select.selectedIndex] || select.options[0];
    }

    function closeActive(restoreFocus) {
        if (!active) {
            return;
        }

        active.ui.classList.remove('select-ui--open');
        active.button.setAttribute('aria-expanded', 'false');
        active.menu.classList.remove('open');

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
        state.button.textContent = option ? option.textContent.trim() : '';
        state.button.disabled = state.select.disabled;
        state.button.classList.toggle('is-placeholder', !state.select.value);
    }

    function syncMenu(state) {
        state.menu.innerHTML = '';

        Array.from(state.select.options).forEach((option, index) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'select-ui__option';
            item.textContent = option.textContent;
            item.disabled = option.disabled;
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', option.selected ? 'true' : 'false');

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

            state.menu.appendChild(item);
        });
    }

    function focusOption(menu, direction) {
        const options = Array.from(menu.querySelectorAll('.select-ui__option:not(:disabled)'));
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
        const state = { select, ui, button, menu };

        ui.className = 'select-ui';
        button.type = 'button';
        button.className = 'select-ui__button';
        button.setAttribute('aria-haspopup', 'listbox');
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-controls', id);

        menu.id = id;
        menu.className = 'select-ui__menu';
        menu.setAttribute('role', 'listbox');

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
            syncButton(state);
            if (active === state) {
                syncMenu(state);
                positionMenu(state);
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

    window.addEventListener('resize', () => active && positionMenu(active));
    window.addEventListener('scroll', () => active && positionMenu(active), true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
