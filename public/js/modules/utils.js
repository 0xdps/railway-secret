// Shared utilities — no DOM dependencies at module load time

export function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = String(str ?? '');
    return div.innerHTML;
}

export function getCsrfToken() {
    return document.body.dataset.csrfToken || '';
}

export function refreshIcons() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
}

export function showToast(message, type = 'success') {
    if (!message) return;

    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type === 'error' ? 'error' : 'success'}`;
    toast.textContent = message;
    container.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('toast-hide');
        setTimeout(() => toast.remove(), 220);
    }, 2200);
}

export function formatRelativeAge(secondsAgo) {
    if (!Number.isFinite(secondsAgo) || secondsAgo < 0) return '--';
    if (secondsAgo < 5)     return 'just now';
    if (secondsAgo < 60)    return `${secondsAgo}s ago`;
    if (secondsAgo < 3600)  return `${Math.floor(secondsAgo / 60)}m ago`;
    if (secondsAgo < 86400) return `${Math.floor(secondsAgo / 3600)}h ago`;
    return `${Math.floor(secondsAgo / 86400)}d ago`;
}

export function relativeTime(dateStr) {
    if (!dateStr) return dateStr;
    const date = new Date(dateStr.replace(' ', 'T') + 'Z');
    if (isNaN(date.getTime())) return dateStr;
    const diffSec = Math.floor((Date.now() - date.getTime()) / 1000);
    if (diffSec < 60)  return 'just now';
    const diffMin = Math.floor(diffSec / 60);
    if (diffMin < 60)  return diffMin + 'm ago';
    const diffHr = Math.floor(diffMin / 60);
    if (diffHr < 24)   return diffHr + 'h ago';
    const diffDay = Math.floor(diffHr / 24);
    if (diffDay < 30)  return diffDay + 'd ago';
    return date.toLocaleDateString();
}

export function applyRelativeTimes() {
    document.querySelectorAll('.js-relative-time[data-timestamp]').forEach((el) => {
        const raw = el.dataset.timestamp;
        el.textContent = relativeTime(raw);
        el.title = raw;
    });
}
