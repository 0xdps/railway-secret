<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title>Docs — Railway Secrets</title>
    <link rel="stylesheet" href="/style.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js" defer></script>
    <script src="/js/docs.js" defer></script>
    <style>
        .docs-layout { display: flex; gap: 40px; }
        .docs-toc {
            width: 180px;
            flex-shrink: 0;
            position: sticky;
            top: 76px;
            align-self: flex-start;
        }
        .docs-toc-label {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .docs-toc a {
            display: block;
            font-size: 12px;
            color: var(--text-secondary);
            text-decoration: none;
            padding: 4px 0;
            border-left: 2px solid transparent;
            padding-left: 10px;
            transition: color 0.15s, border-color 0.15s;
        }
        .docs-toc a:hover { color: var(--text-primary); border-left-color: var(--border); }
        .docs-toc a.active { color: var(--accent); border-left-color: var(--accent); }

        .docs-body { flex: 1; max-width: 680px; }
        .docs-body h2 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 32px 0 10px;
            padding-top: 8px;
            letter-spacing: -0.01em;
        }
        .docs-body h2:first-child { margin-top: 0; }
        .docs-body h3 {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 20px 0 6px;
        }
        .docs-body p, .docs-body li {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.65;
        }
        .docs-body ul { padding-left: 1.2em; margin: 6px 0; }
        .docs-body li { margin-bottom: 4px; }
        .docs-body code {
            font-family: 'SFMono-Regular', 'Cascadia Code', 'Fira Code', monospace;
            font-size: 11.5px;
            background: var(--bg-overlay);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 1px 5px;
            color: var(--text-primary);
        }
        .docs-body pre {
            background: var(--bg-overlay);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            overflow-x: auto;
            margin: 10px 0;
        }
        .docs-body pre code {
            background: none;
            border: none;
            padding: 0;
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.6;
        }
        .docs-divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 28px 0;
        }
        .callout {
            display: flex;
            gap: 10px;
            padding: 12px 14px;
            border-radius: var(--radius-sm);
            margin: 14px 0;
            font-size: 12.5px;
            line-height: 1.6;
        }
        .callout svg { flex-shrink: 0; margin-top: 1px; }
        .callout-info { background: var(--bg-active); border: 1px solid rgba(99,102,241,0.2); color: #a5b4fc; }
        .callout-warn { background: rgba(245,158,11,0.08); border: 1px solid rgba(245,158,11,0.2); color: #fcd34d; }

        .env-table { width: 100%; border-collapse: collapse; font-size: 12px; margin: 10px 0; }
        .env-table th {
            text-align: left;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--text-muted);
            padding: 6px 10px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-overlay);
        }
        .env-table td {
            padding: 8px 10px;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        .env-table td:first-child { color: var(--text-primary); font-family: monospace; font-size: 11.5px; }
        .env-table tr:last-child td { border-bottom: none; }
    </style>
</head>
<body>
<div class="app-layout">

    <!-- Sidebar (same as dashboard) -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="/favicon.svg" alt="" class="brand-mark" width="16" height="16">
            Railway Secrets
        </div>

        <div class="sidebar-section-label">Project</div>
        <nav>
            <a href="/" class="nav-link">
                <i data-lucide="layers" style="width:14px;height:14px;"></i>
                Global Variables
            </a>
        </nav>

        <?php if (!empty($groupedServices)): ?>
        <div style="overflow-y:auto;max-height:42vh;">
            <?php foreach ($groupedServices as $groupName => $groupItems): ?>
                <div class="sidebar-section-label" style="margin-top:12px;"><?= htmlspecialchars($groupName) ?></div>
                <nav>
                    <?php foreach ($groupItems as $svc): ?>
                        <a href="/?serviceId=<?= urlencode($svc['id']) ?>" class="nav-link">
                            <i data-lucide="box" style="width:14px;height:14px;"></i>
                            <?= htmlspecialchars($svc['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- <div class="sidebar-spacer"></div> -->
        <div class="sidebar-footer">
            <a href="/docs" class="nav-link active">
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

    <div class="main-content">
        <div class="page-header">
            <div class="page-header-left">
                <h1>Documentation</h1>
                <p>How to configure, deploy, and operate Railway Secrets</p>
            </div>
        </div>

        <div class="page-body" style="padding-bottom: 60vh;">
            <div class="docs-layout">

                <!-- TOC -->
                <nav class="docs-toc">
                    <div class="docs-toc-label">On this page</div>
                    <a href="#how-it-works">How it works</a>
                    <a href="#env-vars">Environment variables</a>
                    <a href="#rotation">Rotation modes</a>
                    <a href="#deployment">Deployment</a>
                    <a href="#security">Security</a>
                </nav>

                <!-- Body -->
                <div class="docs-body">

                    <h2 id="how-it-works">How it works</h2>
                    <p>
                        Railway Secrets manages Railway environment secrets from a single dashboard.
                        You configure each secret once — setting its length, encoding, and
                        auto-rotate interval — and Railway Secrets handles both scheduled and
                        on-demand rotation from that same configuration.
                    </p>
                    <ul>
                        <li>The dashboard lets you <strong>rotate any secret immediately</strong> with one click.</li>
                        <li>The cron job reads the same configuration and <strong>rotates automatically</strong> when the interval has elapsed.</li>
                        <li>Each rotation saves the <em>previous</em> value in an AES-256-GCM encrypted SQLite database, so you can roll back if needed.</li>
                    </ul>

                    <hr class="docs-divider">

                    <h2 id="env-vars">Environment variables</h2>
                    <p>Set these in your Railway service before deploying.</p>

                    <div class="panel" style="overflow:hidden;">
                        <table class="env-table">
                            <thead>
                                <tr><th>Variable</th><th>Description</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>RAILWAY_TOKEN</td>
                                    <td>Railway API token with project-level write access.</td>
                                </tr>
                                <tr>
                                    <td>RAILWAY_PROJECT_ID</td>
                                    <td>Auto-injected by Railway for the running service. Fallback <code>PROJECT_ID</code> is also supported by this app.</td>
                                </tr>
                                <tr>
                                    <td>RAILWAY_ENVIRONMENT_ID</td>
                                    <td>Auto-injected by Railway for the running service. Fallback <code>ENVIRONMENT_ID</code> is also supported by this app.</td>
                                </tr>
                                <tr>
                                    <td>ADMIN_KEY</td>
                                    <td>Password for dashboard login. Use a strong random string.</td>
                                </tr>
                                <tr>
                                    <td>SESSION_SECRET</td>
                                    <td>Signs the browser session cookie. Rotate if compromised.</td>
                                </tr>
                                <tr>
                                    <td>MASTER_KEY</td>
                                    <td>Encrypts the secret history at rest. <strong>Never lose this</strong> — history becomes unreadable without it.</td>
                                </tr>
                                <tr>
                                    <td>TRUSTED_PROXY_IPS</td>
                                    <td>Optional comma-separated proxy IPs to trust for forwarded client IP headers.</td>
                                </tr>
                                <tr>
                                    <td>ROTATION_TIME_UNIT</td>
                                    <td>Time unit for rotation intervals: <code>day</code>, <code>hour</code>, or <code>minute</code>. Defaults to <code>day</code>. Use <code>minute</code> for testing.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <hr class="docs-divider">

                    <h2 id="rotation">Rotation modes</h2>
                    <p>Every secret can be rotated in two ways — and both use the same config you set in the dashboard.</p>

                    <h3>On-demand (dashboard)</h3>
                    <p>
                        Click <strong>Rotate</strong> on any managed secret. Railway Secrets will immediately:
                    </p>
                    <ul>
                        <li>Generate a new secret with the configured length &amp; encoding.</li>
                        <li>Push it to Railway via the API.</li>
                        <li>Railway auto-redeploys any service that uses the variable.</li>
                        <li>Store the previous value encrypted in SQLite.</li>
                    </ul>

                    <h3>Scheduled (cron)</h3>
                    <p>
                        The cron job runs <code>cron.php</code> on a schedule you define.
                        It reads the same managed secrets from the database and rotates any
                        secret whose <strong>interval has elapsed</strong> since its last rotation.
                    </p>
                    <div class="callout callout-info">
                        <i data-lucide="info" style="width:14px;height:14px;"></i>
                        <span>Set <strong>Rotation Interval</strong> to <code>0</code> in the dashboard to make a secret manual-only. The cron job will skip it.</span>
                    </div>

                    <h3>Railway Cron Service setup</h3>
                    <p>Create a separate <strong>Cron Service</strong> in Railway pointing to the same repository:</p>
                    <pre><code># Command
php /var/www/html/cron.php

# Schedule examples
0 3 * * *      # Daily at 3 AM
0 3 * * 0      # Weekly on Sunday at 3 AM
0 3 1 * *      # Monthly on the 1st at 3 AM</code></pre>

                    <div class="callout callout-warn">
                        <i data-lucide="alert-triangle" style="width:14px;height:14px;"></i>
                        <span>Mount the same Railway Volume to <code>/var/www/html/storage</code> in <em>both</em> the dashboard service and the cron service so they share the same SQLite database.</span>
                    </div>

                    <hr class="docs-divider">

                    <h2 id="deployment">Deployment</h2>
                    <ol style="padding-left:1.2em; font-size:13px; color:var(--text-secondary); line-height:1.8;">
                        <li>Push this repository to GitHub.</li>
                        <li>Create a new Railway service from the repo.</li>
                        <li>Set all the environment variables listed above.</li>
                        <li>Create a Railway Volume and mount it to <code>/var/www/html/storage</code>.</li>
                        <li>Create a second <strong>Cron Service</strong> from the same repo, set the cron command and schedule.</li>
                        <li>Mount the <strong>same volume</strong> to the cron service at the same path.</li>
                        <li>Open the dashboard URL, log in, and start configuring secrets.</li>
                    </ol>

                    <hr class="docs-divider">

                    <h2 id="security">Security model</h2>
                    <ul>
                        <li><strong>No active secrets stored</strong> — Railway Secrets only saves the <em>previous</em> value after a rotation. The live secret only exists in Railway.</li>
                        <li><strong>AES-256-GCM encryption</strong> — every entry in the history table is encrypted with your <code>MASTER_KEY</code> before being written to disk.</li>
                        <li><strong>Signed sessions</strong> — the browser cookie is both encrypted and HMAC-signed using <code>SESSION_SECRET</code>.</li>
                        <li><strong>CLI-only cron</strong> — <code>cron.php</code> refuses to run outside of the PHP CLI environment.</li>
                    </ul>

                </div><!-- /docs-body -->
            </div><!-- /docs-layout -->
        </div><!-- /page-body -->
    </div><!-- /main-content -->
</div>

</body>
</html>
