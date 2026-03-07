<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title><?= htmlspecialchars($viewTitle) ?> — Rotator</title>
    <link rel="stylesheet" href="/style.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
</head>
<body>
<div class="app-layout">

    <!-- ── Sidebar ────────────────────────────────────────────────── -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <i data-lucide="shield-check" style="width:16px;height:16px;"></i>
            Rotator
        </div>

        <div class="sidebar-section-label">Project</div>
        <nav>
            <a href="/" class="nav-link <?= !$serviceId ? 'active' : '' ?>">
                <i data-lucide="layers" style="width:14px;height:14px;"></i>
                Global Variables
            </a>
        </nav>

        <?php if (!empty($services)): ?>
        <div class="sidebar-section-label" style="margin-top:12px;">Services</div>
        <nav style="overflow-y:auto;flex:1;">
            <?php foreach ($services as $svc): ?>
                <a href="/?serviceId=<?= $svc['id'] ?>" class="nav-link <?= $serviceId === $svc['id'] ? 'active' : '' ?>">
                    <i data-lucide="box" style="width:14px;height:14px;"></i>
                    <?= htmlspecialchars($svc['name']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>

        <div class="sidebar-spacer"></div>
        <div class="sidebar-footer">
            <a href="/docs" class="nav-link">
                <i data-lucide="book-open" style="width:14px;height:14px;"></i>
                Docs
            </a>
            <form method="POST" action="/logout" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <button type="submit" class="nav-link nav-link-btn danger">
                    <i data-lucide="log-out" style="width:14px;height:14px;"></i>
                    Logout
                </button>
            </form>
        </div>
    </aside>

    <!-- ── Main ───────────────────────────────────────────────────── -->
    <div class="main-content">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-left">
                <h1><?= htmlspecialchars($viewTitle) ?></h1>
                <p>Manage and rotate Railway environment secrets</p>
            </div>
            <div class="flex items-center gap-4">
                <label class="toggle-container" title="Show/Hide variables injected by Railway (prefixed with RAILWAY_)">
                    <input type="checkbox" id="toggleRailway" onchange="filterRailwayVars()">
                    <span class="toggle-label">Show Railway Vars</span>
                    <div class="toggle-switch"></div>
                </label>
                <button class="btn btn-ghost btn-sm" onclick="location.reload()">
                    <i data-lucide="refresh-cw" style="width:13px;height:13px;"></i>
                    Refresh
                </button>
            </div>
        </div>

        <!-- Page Body -->
        <div class="page-body">

            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i data-lucide="alert-circle" style="width:14px;height:14px;"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="panel">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Secret</th>
                            <th>Config</th>
                            <th>Schedule</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($variables)): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state">
                                        <i data-lucide="ghost" style="width:32px;height:32px;"></i>
                                        <p>No variables found in this scope.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else:
                        foreach ($variables as $name => $value):
                            $keyId  = ($serviceId ?: 'global') . ':' . $name;
                            $config = $managed[$keyId] ?? null;
                            $isManaged = (bool)$config;
                            $isRailway  = strpos($name, 'RAILWAY_') === 0;
                        ?>
                            <tr class="secret-tr" data-is-railway="<?= $isRailway ? '1' : '0' ?>">
                                <!-- Secret Name + Value -->
                                <td>
                                    <div class="key-name">
                                        <i data-lucide="<?= $isManaged ? 'shield-check' : 'shield-off' ?>"
                                           class="key-icon <?= $isManaged ? 'managed' : 'unmanaged' ?>"
                                           style="width:13px;height:13px;"></i>
                                        <span class="key-name-text"><?= htmlspecialchars($name) ?></span>
                                    </div>
                                    <div class="secret-row">
                                        <span class="secret-value"
                                              data-value="<?= htmlspecialchars($value) ?>"
                                              title="Click to view">••••••••••••</span>
                                        <button class="btn-icon" onclick="toggleSecret(this)" title="Toggle visibility">
                                            <i data-lucide="eye" style="width:12px;height:12px;"></i>
                                        </button>
                                        <button class="btn-icon" onclick="copySecret('<?= htmlspecialchars(addslashes($value)) ?>', this)" title="Copy">
                                            <i data-lucide="copy" style="width:12px;height:12px;"></i>
                                        </button>
                                    </div>
                                </td>

                                <!-- Config -->
                                <td>
                                    <?php if ($isManaged): ?>
                                        <span class="config-text"><?= (int)$config['length'] ?> chars · <?= htmlspecialchars($config['encoding']) ?></span>
                                    <?php else: ?>
                                        <span class="config-text empty">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Schedule -->
                                <td>
                                    <?php if ($isManaged && $config['interval_days'] > 0): ?>
                                        <span class="badge badge-schedule">
                                            <i data-lucide="clock" style="width:10px;height:10px;"></i>
                                            Every <?= (int)$config['interval_days'] ?>d
                                        </span>
                                    <?php elseif ($isManaged): ?>
                                        <span class="badge badge-manual">Manual</span>
                                    <?php else: ?>
                                        <span class="config-text empty">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <td>
                                    <div class="actions-cell">
                                        <button class="btn-icon" title="Rotate & Configure"
                                            onclick="openModal('<?= htmlspecialchars($name) ?>', <?= htmlspecialchars(json_encode($config)) ?>)">
                                            <i data-lucide="rotate-cw" style="width:13px;height:13px;"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

        </div><!-- /page-body -->
    </div><!-- /main-content -->
</div><!-- /app-layout -->

<!-- ── Confirm Dialog ────────────────────────────────────────────── -->
<div class="modal-overlay" id="confirmModal" style="z-index: 600;">
    <div class="modal-box" style="max-width:340px;">
        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:16px;">
            <div style="flex-shrink:0;width:34px;height:34px;border-radius:8px;background:var(--danger-bg);border:1px solid rgba(239,68,68,0.2);display:flex;align-items:center;justify-content:center;margin-top:1px;">
                <i data-lucide="alert-triangle" id="confirmIcon" style="width:16px;height:16px;color:var(--danger);"></i>
            </div>
            <div>
                <div class="modal-title" id="confirmTitle" style="margin-bottom:4px;">Are you sure?</div>
                <div id="confirmMessage" style="font-size:12px;color:var(--text-secondary);line-height:1.5;"></div>
            </div>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-ghost btn-md" style="flex:1;" id="confirmCancelBtn">Cancel</button>
            <button class="btn btn-danger btn-md" style="flex:1;" id="confirmOkBtn">Confirm</button>
        </div>
    </div>
</div>

<!-- ── Config Modal ───────────────────────────────────────────────── -->
<div class="modal-overlay" id="configModal">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title" id="modalTitle">Configure Rotation</span>
            <button class="modal-close" onclick="closeModal()">
                <i data-lucide="x" style="width:14px;height:14px;"></i>
            </button>
        </div>

        <form id="configForm">
            <input type="hidden" name="name" id="configKey">
            <input type="hidden" name="serviceId" value="<?= htmlspecialchars($serviceId) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Length</label>
                    <select name="length" class="form-control">
                        <option value="16">16 chars</option>
                        <option value="32" selected>32 chars</option>
                        <option value="64">64 chars</option>
                        <option value="128">128 chars</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Encoding</label>
                    <select name="encoding" class="form-control">
                        <option value="hex">Hexadecimal</option>
                        <option value="base64">Base64</option>
                        <option value="alphanumeric">Alphanumeric</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Auto-rotate interval</label>
                <div class="input-with-suffix">
                    <input type="number" name="interval" placeholder="0 = manual only" min="0" value="30">
                    <span class="suffix">days</span>
                </div>
                <div class="form-hint">Set to 0 to only rotate manually.</div>
            </div>

            <div class="form-group" id="manualValueGroup" style="display:none; border-top:1px solid var(--border); padding-top:16px;">
                <label class="form-label">Manual Secret (Optional)</label>
                <input type="text" name="manualValue" class="form-control" placeholder="Leave empty to auto-generate">
                <div class="form-hint">Paste a specific value here to rotate to it manually.</div>
            </div>

            <div class="modal-actions">
                <button type="submit" name="rotate" value="rotate-only" class="btn btn-primary btn-md" style="background:var(--bg-overlay); color:var(--text-primary); border:1px solid var(--border);">
                    Rotate Now
                </button>
                <button type="submit" name="rotate" value="1" class="btn btn-primary btn-md">
                    Save + Rotate
                </button>
                <button type="submit" name="rotate" value="0" class="btn btn-primary btn-md" style="background:var(--bg-overlay); color:var(--text-primary); border:1px solid var(--border);">
                    Save Config
                </button>
            </div>

            <!-- Danger zone: only shown for existing configs -->
            <div id="deleteZone" style="display:none; margin-top:12px; padding-top:12px; border-top:1px solid var(--border); text-align:center;">
                <button type="button"
                    style="background:none;border:none;font-size:11px;color:var(--text-muted);cursor:pointer;text-decoration:underline;text-underline-offset:2px;"
                    onclick="deleteConfig()">
                    Remove from managed secrets
                </button>
            </div>
        </form>
    </div>
</div>

<script>
lucide.createIcons();
const currentServiceId = <?= json_encode($serviceId) ?>;
const csrfToken = <?= json_encode($csrfToken) ?>;

// ── Confirm dialog ────────────────────────────────────────────────
function customConfirm({ title = 'Are you sure?', message = '', confirmText = 'Confirm', danger = true } = {}) {
    return new Promise((resolve) => {
        const overlay  = document.getElementById('confirmModal');
        const titleEl  = document.getElementById('confirmTitle');
        const msgEl    = document.getElementById('confirmMessage');
        const okBtn    = document.getElementById('confirmOkBtn');
        const cancelBtn = document.getElementById('confirmCancelBtn');
        const icon     = document.getElementById('confirmIcon');

        titleEl.textContent  = title;
        msgEl.textContent    = message;
        okBtn.textContent    = confirmText;

        okBtn.className = `btn ${danger ? 'btn-danger' : 'btn-primary'} btn-md`;
        okBtn.style.flex = '1';
        icon.style.color = danger ? 'var(--danger)' : 'var(--accent)';
        icon.closest('div').style.background  = danger ? 'var(--danger-bg)' : 'var(--bg-active)';
        icon.closest('div').style.borderColor = danger ? 'rgba(239,68,68,0.2)' : 'rgba(99,102,241,0.2)';

        overlay.classList.add('open');
        lucide.createIcons();

        function cleanup(result) {
            overlay.classList.remove('open');
            okBtn.removeEventListener('click', onOk);
            cancelBtn.removeEventListener('click', onCancel);
            resolve(result);
        }
        const onOk     = () => cleanup(true);
        const onCancel = () => cleanup(false);

        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) cleanup(false); }, { once: true });
    });
}

// ── Config Modal ──────────────────────────────────────────────────
function openModal(key, existingConfig) {
    document.getElementById('configKey').value = key;
    document.getElementById('modalTitle').innerText = 'Rotate & Configure: ' + key;
    
    const form       = document.getElementById('configForm');
    const deleteZone = document.getElementById('deleteZone');
    const manualGrp  = document.getElementById('manualValueGroup');

    // Always show manual input in this unified layout
    manualGrp.style.display = 'block';

    if (existingConfig) {
        form.elements['length'].value   = existingConfig.length;
        form.elements['encoding'].value = existingConfig.encoding;
        form.elements['interval'].value = existingConfig.interval_days;
        deleteZone.style.display = 'block';
    } else {
        form.reset();
        form.elements['name'].value = key;
        deleteZone.style.display = 'none';
        // Defaults
        form.elements['length'].value = "32";
        form.elements['encoding'].value = "hex";
        form.elements['interval'].value = "30";
    }
    document.getElementById('configModal').classList.add('open');
}

function closeModal() {
    document.getElementById('configModal').classList.remove('open');
}

document.getElementById('configModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// ── Form submit ────────────────────────────────────────────────────
document.getElementById('configForm').onsubmit = async (e) => {
    e.preventDefault();
    const submitter = e.submitter || document.activeElement;
    const action = submitter ? submitter.value : "0";
    const manualValue = e.target.elements['manualValue'] ? e.target.elements['manualValue'].value : '';

    const btns = e.target.querySelectorAll('button[type="submit"]');
    btns.forEach(b => b.disabled = true);
    
    const originalText = submitter ? submitter.textContent : '';
    if (submitter) submitter.textContent = 'Processing…';

    const formData = new FormData(e.target);

    try {
        // 1. If not 'rotate-only', save config first
        if (action !== "rotate-only") {
            const saveFormData = new FormData(e.target);
            saveFormData.append('action', 'save');
            const r = await fetch('/api/manage', { method: 'POST', body: saveFormData });
            const d = await r.json();
            
            if (!d.success) {
                alert(d.error);
                resetButtons();
                return;
            }
        }

        // 2. Rotate if requested
        if (action === "1" || action === "rotate-only") {
            const rotData = new FormData();
            rotData.append('key', formData.get('name'));
            rotData.append('serviceId', formData.get('serviceId'));
            rotData.append('length', formData.get('length'));
            rotData.append('encoding', formData.get('encoding'));
            rotData.append('csrf_token', csrfToken);
            if (manualValue) {
                rotData.append('manualValue', manualValue);
            }

            const rotR = await fetch('/api/rotate', { method: 'POST', body: rotData });
            const rotD = await rotR.json();
            if (!rotD.success) {
                alert("Action failed: " + rotD.error);
            }
        }

        location.reload();
    } catch (err) {
        alert(err.message);
        resetButtons();
    }

    function resetButtons() {
        btns.forEach(b => b.disabled = false);
        if (submitter) submitter.textContent = originalText;
    }
};

// ── Delete ───────────────────────────────────────────────────────
async function deleteConfig() {
    const ok = await customConfirm({
        title: 'Stop rotating?',
        message: 'This secret will no longer be auto-rotated. You can re-configure it at any time.',
        confirmText: 'Stop Rotating',
    });
    if (!ok) return;
    const key = document.getElementById('configKey').value;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('name', key);
    formData.append('csrf_token', csrfToken);
    if (currentServiceId) formData.append('serviceId', currentServiceId);

    try {
        const r = await fetch('/api/manage', { method: 'POST', body: formData });
        const d = await r.json();
        if (d.success) location.reload(); else alert(d.error);
    } catch (err) { alert(err.message); }
}

// ── Toggle secret visibility ───────────────────────────────────────
function toggleSecret(btn) {
    const row  = btn.closest('.secret-row');
    const span = row.querySelector('.secret-value');
    const icon = btn.querySelector('i');
    const isHidden = span.textContent.trim() === '••••••••••••';

    span.textContent = isHidden ? span.dataset.value : '••••••••••••';
    span.classList.toggle('revealed', isHidden);
    icon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
    lucide.createIcons();
}

// ── Copy ───────────────────────────────────────────────────────────
async function copySecret(val, btn) {
    await navigator.clipboard.writeText(val);
    const icon = btn.querySelector('i');
    icon.setAttribute('data-lucide', 'check');
    btn.classList.add('success');
    lucide.createIcons();
    setTimeout(() => {
        icon.setAttribute('data-lucide', 'copy');
        btn.classList.remove('success');
        lucide.createIcons();
    }, 2000);
}



// ── Global shortcuts ──────────────────────────────────────────────
window.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        // If config modal is open, close it
        const configModal = document.getElementById('configModal');
        if (configModal.classList.contains('open')) {
            closeModal();
        }
        
        // If confirm modal is open, cancel it (clicks the cancel btn)
        const confirmModal = document.getElementById('confirmModal');
        if (confirmModal.classList.contains('open')) {
            document.getElementById('confirmCancelBtn').click();
        }
    }
});
// ── Filter Railway Vars ───────────────────────────────────────────
function filterRailwayVars() {
    const show = document.getElementById('toggleRailway').checked;
    const rows = document.querySelectorAll('.secret-tr[data-is-railway="1"]');
    rows.forEach(row => {
        row.style.display = show ? '' : 'none';
    });
}
// Run on load
document.addEventListener('DOMContentLoaded', filterRailwayVars);
</script>
</body>
</html>
