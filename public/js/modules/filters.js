// Table filtering, searching, sorting

const STORAGE_KEY_SHOW_RAILWAY = 'railwaySecrets.showRailwayVars';

export function applyFilters() {
    const toggleRailway = document.getElementById('toggleRailway');
    const secretSearch  = document.getElementById('secretSearch');
    const showRailway   = toggleRailway ? toggleRailway.checked : true;
    const query         = secretSearch ? secretSearch.value.trim().toLowerCase() : '';

    document.querySelectorAll('.secret-tr[data-is-railway="1"]').forEach((row) => {
        const name = (row.dataset.keyName || '').toLowerCase();
        const matchesRailway = showRailway || row.dataset.isRailway !== '1';
        const matchesSearch  = query === '' || name.includes(query);
        row.style.display = matchesRailway && matchesSearch ? '' : 'none';
    });

    document.querySelectorAll('.secret-tr[data-is-railway="0"]').forEach((row) => {
        const name = (row.dataset.keyName || '').toLowerCase();
        row.style.display = query === '' || name.includes(query) ? '' : 'none';
    });
}

export function initRailwayToggleState() {
    const toggleRailway = document.getElementById('toggleRailway');
    if (!toggleRailway) return;
    let saved = null;
    try { saved = window.localStorage.getItem(STORAGE_KEY_SHOW_RAILWAY); } catch { saved = null; }
    toggleRailway.checked = saved === null ? false : saved === '1';
}

export function applyManagedGroupFilter() {
    const filterEl = document.getElementById('managedGroupFilter');
    if (!filterEl) return;
    const value = filterEl.value || '';
    document.querySelectorAll('.managed-row').forEach((row) => {
        const rowGroup = row.dataset.syncGroup || '__none__';
        row.style.display = value === '' || rowGroup === value ? '' : 'none';
    });
}

export function sortTable(sortKey) {
    const tbody = document.getElementById('secrets-table-body');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('.secret-tr'));
    rows.sort((a, b) => {
        const nameA    = (a.dataset.keyName || '').toLowerCase();
        const nameB    = (b.dataset.keyName || '').toLowerCase();
        const managedA = a.dataset.isManaged === '1';
        const managedB = b.dataset.isManaged === '1';
        switch (sortKey) {
            case 'name-desc':       return nameB.localeCompare(nameA);
            case 'managed-first':   return managedA !== managedB ? (managedA ? -1 : 1) : nameA.localeCompare(nameB);
            case 'unmanaged-first': return managedA !== managedB ? (managedA ?  1 : -1) : nameA.localeCompare(nameB);
            default:                return nameA.localeCompare(nameB);
        }
    });
    rows.forEach((row) => tbody.appendChild(row));
}

export function initFilterHandlers() {
    document.body.addEventListener('change', (event) => {
        if (event.target && event.target.id === 'toggleRailway') {
            try { window.localStorage.setItem(STORAGE_KEY_SHOW_RAILWAY, event.target.checked ? '1' : '0'); } catch {}
            applyFilters();
            return;
        }
        if (event.target && event.target.id === 'managedGroupFilter') {
            applyManagedGroupFilter();
        }
    });

    document.body.addEventListener('input', (event) => {
        if (event.target && event.target.id === 'secretSearch') {
            applyFilters();
        }
    });
}

// Expose for any legacy non-module callers
window.filterRailwayVars = applyFilters;
