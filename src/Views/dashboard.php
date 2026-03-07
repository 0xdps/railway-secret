<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title><?= htmlspecialchars($viewTitle) ?> — Rotator</title>
    <link rel="stylesheet" href="/style.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js" defer></script>
    <script src="/js/dashboard.js" defer></script>
</head>
<body data-current-service-id="<?= htmlspecialchars((string)$serviceId, ENT_QUOTES, 'UTF-8') ?>" data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
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
                    <input type="checkbox" id="toggleRailway">
                    <span class="toggle-label">Show Railway Vars</span>
                    <div class="toggle-switch"></div>
                </label>
                <button class="btn btn-ghost btn-sm" id="refreshBtn" type="button">
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
                    <colgroup>
                        <col style="width: 52%;">
                        <col style="width: 19%;">
                        <col style="width: 21%;">
                        <col style="width: 8%;">
                    </colgroup>
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
                                        <button class="btn-icon js-toggle-secret" type="button" title="Toggle visibility">
                                            <i data-lucide="eye" style="width:12px;height:12px;"></i>
                                        </button>
                                        <button class="btn-icon js-copy-secret" type="button" data-secret-value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" title="Copy">
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
                                        <button class="btn-icon js-open-config" type="button" title="Rotate & Configure"
                                            data-key="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                                            data-config='<?= htmlspecialchars(json_encode($config, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>'>
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
            <button class="modal-close" id="closeConfigModalBtn" type="button">
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
                    id="deleteConfigBtn"
                    style="background:none;border:none;font-size:11px;color:var(--text-muted);cursor:pointer;text-decoration:underline;text-underline-offset:2px;"
                    Remove from managed secrets
                </button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
