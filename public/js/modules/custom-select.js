// Custom <select> replacement widget

let activeCustomSelect = null;

export function closeCustomSelect(selectRoot = null) {
    const target = selectRoot || activeCustomSelect;
    if (!target) return;
    target.classList.remove('open');
    target.setAttribute('aria-expanded', 'false');
    if (activeCustomSelect === target) activeCustomSelect = null;
}

export function closeAllCustomSelects() {
    document.querySelectorAll('.custom-select.open').forEach((el) => closeCustomSelect(el));
}

export function initCustomSelects(root = document) {
    root.querySelectorAll('select').forEach((selectEl) => {
        if (selectEl.dataset.customSelectReady === '1') return;

        selectEl.dataset.customSelectReady = '1';
        selectEl.classList.add('custom-select-source');

        const custom = document.createElement('div');
        custom.className = 'custom-select';
        custom.setAttribute('tabindex', '-1');
        custom.setAttribute('aria-expanded', 'false');

        if (selectEl.classList.contains('form-control-compact')) {
            custom.classList.add('custom-select-compact');
        }

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'custom-select-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');

        const label = document.createElement('span');
        const caret = document.createElement('span');
        caret.className = 'custom-select-caret';
        trigger.appendChild(label);
        trigger.appendChild(caret);

        const menu = document.createElement('div');
        menu.className = 'custom-select-menu';
        menu.setAttribute('role', 'listbox');

        const syncFromSelect = () => {
            const selected = selectEl.options[selectEl.selectedIndex];
            label.textContent = selected ? selected.textContent || '' : '';
            menu.querySelectorAll('.custom-select-option').forEach((btn) => {
                btn.classList.toggle('active', btn.dataset.value === selectEl.value);
            });
        };

        Array.from(selectEl.options).forEach((option) => {
            const optBtn = document.createElement('button');
            optBtn.type = 'button';
            optBtn.className = 'custom-select-option';
            optBtn.textContent = option.textContent || '';
            optBtn.dataset.value = option.value;
            if (option.disabled) optBtn.disabled = true;

            optBtn.addEventListener('click', () => {
                if (option.disabled) return;
                selectEl.value = option.value;
                selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                selectEl.dispatchEvent(new Event('input', { bubbles: true }));
                syncFromSelect();
                closeCustomSelect(custom);
            });

            menu.appendChild(optBtn);
        });

        trigger.addEventListener('click', () => {
            const willOpen = !custom.classList.contains('open');
            closeAllCustomSelects();
            if (!willOpen) return;
            custom.classList.add('open');
            custom.setAttribute('aria-expanded', 'true');
            activeCustomSelect = custom;
        });

        trigger.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') { closeCustomSelect(custom); return; }
            if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); trigger.click(); }
        });

        selectEl.addEventListener('change', syncFromSelect);

        custom.appendChild(trigger);
        custom.appendChild(menu);
        selectEl.insertAdjacentElement('afterend', custom);
        syncFromSelect();
    });
}
