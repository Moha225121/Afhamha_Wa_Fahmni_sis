(function () {
    let active = null;
    let counter = 0;

    function close(restoreFocus = false) {
        if (!active) return;
        const state = active;
        active = null;
        state.ui.classList.remove('select-ui--open');
        state.button.setAttribute('aria-expanded', 'false');
        state.menu.classList.remove('open');
        if (restoreFocus) state.button.focus();
    }

    function position(state) {
        const rect = state.button.getBoundingClientRect();
        const gap = 8;
        const below = window.innerHeight - rect.bottom - gap;
        const above = rect.top - gap;
        const openAbove = below < 220 && above > below;
        const height = Math.max(80, Math.min(340, (openAbove ? above : below) - gap));
        state.menu.style.width = `${Math.min(rect.width, window.innerWidth - gap * 2)}px`;
        state.menu.style.left = `${Math.max(gap, Math.min(rect.left, window.innerWidth - state.menu.offsetWidth - gap))}px`;
        state.menu.style.maxHeight = `${height}px`;
        state.menu.style.top = openAbove
            ? `${Math.max(gap, rect.top - state.menu.offsetHeight - gap)}px`
            : `${rect.bottom + gap}px`;
    }

    function normalize(value) {
        return value.normalize('NFKD').replace(/[\u064B-\u065F\u0670\u0640]/g, '')
            .replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').toLocaleLowerCase().trim();
    }

    function sync(state) {
        const names = Array.from(state.select.selectedOptions).map(option => option.textContent.trim());
        state.button.textContent = names.length ? names.join('، ') : state.select.dataset.placeholder;
        state.button.setAttribute('aria-label', `${state.title}: ${state.button.textContent}`);
        state.button.classList.toggle('is-placeholder', !names.length);
        state.button.disabled = state.select.disabled;
        state.items.forEach(({ option, row, checkbox }) => {
            checkbox.checked = option.selected;
            checkbox.disabled = state.select.disabled || option.disabled;
            row.classList.toggle('is-selected', option.selected);
        });
    }

    function filter(state) {
        const words = normalize(state.search.value).split(/\s+/).filter(Boolean);
        let count = 0;
        state.items.forEach(({ option, row }) => {
            row.hidden = option.hidden || !words.every(word => normalize(option.textContent).includes(word));
            if (!row.hidden) count++;
        });
        state.empty.hidden = count > 0;
        if (active === state) position(state);
    }

    function render(state) {
        state.list.replaceChildren();
        state.items = Array.from(state.select.options).map(option => {
            const row = document.createElement('label');
            const checkbox = document.createElement('input');
            const name = document.createElement('span');
            row.className = 'select-ui__option select-ui__checkbox-option';
            checkbox.type = 'checkbox';
            name.textContent = option.textContent;
            row.append(checkbox, name);
            checkbox.addEventListener('change', () => {
                option.selected = checkbox.checked;
                state.select.dispatchEvent(new Event('change', { bubbles: true }));
            });
            state.list.appendChild(row);
            return { option, row, checkbox };
        });
        sync(state);
        filter(state);
    }

    function open(state) {
        close();
        state.search.value = '';
        filter(state);
        sync(state);
        active = state;
        state.ui.classList.add('select-ui--open');
        state.button.setAttribute('aria-expanded', 'true');
        state.menu.classList.add('open');
        position(state);
        state.search.focus();
    }

    function enhance(select) {
        if (select.dataset.searchableEnhanced === 'true') return;
        const ui = document.createElement('span');
        const button = document.createElement('button');
        const menu = document.createElement('div');
        const search = document.createElement('input');
        const list = document.createElement('div');
        const empty = document.createElement('p');
        const id = `searchable-multiselect-${counter++}`;
        const label = select.closest('label');
        const title = label?.firstChild?.textContent.trim() || 'اختيار أسماء';
        const state = { select, ui, button, menu, search, list, empty, title, items: [] };

        ui.className = 'select-ui';
        button.type = 'button';
        button.className = 'select-ui__button';
        button.setAttribute('aria-label', title);
        button.setAttribute('aria-haspopup', 'dialog');
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-controls', id);
        menu.id = id;
        menu.className = 'select-ui__menu select-ui__menu--searchable';
        menu.setAttribute('role', 'dialog');
        menu.setAttribute('aria-label', title);
        search.type = 'search';
        search.className = 'select-ui__search';
        search.placeholder = 'ابحث بالاسم...';
        search.setAttribute('aria-label', `البحث في ${title}`);
        search.autocomplete = 'off';
        list.className = 'select-ui__checkbox-list';
        list.setAttribute('role', 'group');
        list.setAttribute('aria-label', title);
        empty.className = 'select-ui__empty';
        empty.setAttribute('role', 'status');
        empty.textContent = 'لا توجد أسماء مطابقة';
        menu.append(search, list, empty);
        ui.appendChild(button);
        select.insertAdjacentElement('afterend', ui);
        document.body.appendChild(menu);
        render(state);
        select.dataset.searchableEnhanced = 'true';
        select.classList.add('native-select-hidden');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');

        button.addEventListener('click', () => active === state ? close() : open(state));
        button.addEventListener('keydown', event => {
            if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                open(state);
            }
        });
        search.addEventListener('input', () => filter(state));
        menu.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(true);
                return;
            }
            const inSearch = event.target === search;
            const choices = state.items.filter(item => !item.row.hidden && !item.checkbox.disabled)
                .map(item => item.checkbox);
            if (event.key === 'Tab') {
                if (event.shiftKey && inSearch) {
                    event.preventDefault();
                    close(true);
                } else if (!event.shiftKey && (event.target === choices.at(-1) || (inSearch && !choices.length))) {
                    close(true);
                }
                return;
            }
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)
                || (inSearch && ['Home', 'End'].includes(event.key))) return;
            if (!choices.length) return;
            event.preventDefault();
            const index = choices.indexOf(document.activeElement);
            if (event.key === 'ArrowUp' && index === 0) {
                search.focus();
                return;
            }
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? choices.length - 1
                : event.key === 'ArrowUp' ? Math.max(0, index - 1) : Math.min(choices.length - 1, index + 1);
            choices[next].focus();
        });
        select.addEventListener('change', () => sync(state));
        select.form?.addEventListener('reset', () => setTimeout(() => { sync(state); close(); }, 0));
        new MutationObserver(() => {
            render(state);
            if (select.disabled && active === state) close();
        }).observe(select, { childList: true, subtree: true, attributes: true, characterData: true });
    }

    function init() {
        document.querySelectorAll('select[multiple][data-searchable]').forEach(enhance);
    }

    function closeOutside(event) {
        if (active && !active.ui.contains(event.target) && !active.menu.contains(event.target)) close();
    }
    document.addEventListener('click', closeOutside);
    document.addEventListener('focusin', closeOutside);
    document.addEventListener('enhanced-selects:init', init);
    window.addEventListener('resize', () => active && position(active));
    window.addEventListener('scroll', () => active && position(active), true);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
