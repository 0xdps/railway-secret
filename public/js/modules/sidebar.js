// Sidebar active state and group collapse

const COLLAPSED_KEY = 'rs_collapsed_groups';

function getCollapsedGroups() {
    try { return new Set(JSON.parse(window.localStorage.getItem(COLLAPSED_KEY) || '[]')); }
    catch { return new Set(); }
}

function saveCollapsedGroups(set) {
    try { window.localStorage.setItem(COLLAPSED_KEY, JSON.stringify([...set])); } catch {}
}

export function syncGroupCollapseState(currentServiceId) {
    const groups = document.querySelectorAll('.js-sidebar-group');
    if (!groups.length) return;

    const collapsed = getCollapsedGroups();
    groups.forEach((group) => {
        const slug = group.dataset.groupSlug || '';
        const hasActive = Boolean(
            currentServiceId &&
            group.querySelector(`.js-grouped-service[data-service-id="${CSS.escape(currentServiceId)}"]`)
        );
        const shouldOpen = hasActive || !collapsed.has(slug);
        group.classList.toggle('is-open', shouldOpen);
        const toggleBtn = group.querySelector('.sidebar-group-toggle');
        if (toggleBtn) toggleBtn.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    });
}

export function updateSidebarActive() {
    const mainContent      = document.getElementById('mainContent');
    const currentServiceId = mainContent ? (mainContent.dataset.serviceId || '') : '';
    const currentSection   = mainContent ? (mainContent.dataset.section   || 'secrets') : 'secrets';

    document.querySelectorAll('.js-overview-nav').forEach((l) => l.classList.toggle('active', currentSection === 'overview'));
    document.querySelectorAll('.js-scope-nav').forEach((l) => {
        l.classList.toggle('active', currentSection === 'secrets' && (l.dataset.serviceId || '') === currentServiceId);
    });
    document.querySelectorAll('.js-history-nav').forEach((l) => l.classList.toggle('active', currentSection === 'history'));
    document.querySelectorAll('.js-managed-nav').forEach((l) => l.classList.toggle('active', currentSection === 'managed'));
    document.querySelectorAll('.js-docs-nav').forEach((l)    => l.classList.toggle('active', currentSection === 'docs'));
    document.querySelectorAll('.js-about-nav').forEach((l)   => l.classList.toggle('active', currentSection === 'about'));

    syncGroupCollapseState(currentServiceId);
}

export function initSidebarHandlers() {
    document.body.addEventListener('click', (event) => {
        const toggleBtn = event.target.closest('.js-group-toggle');
        if (!toggleBtn) return;

        const group = toggleBtn.closest('.js-sidebar-group');
        if (!group) return;

        const slug    = group.dataset.groupSlug || '';
        const opening = !group.classList.contains('is-open');
        group.classList.toggle('is-open', opening);
        toggleBtn.setAttribute('aria-expanded', opening ? 'true' : 'false');

        const collapsed = getCollapsedGroups();
        if (opening) { collapsed.delete(slug); } else { collapsed.add(slug); }
        saveCollapsedGroups(collapsed);
    });
}
