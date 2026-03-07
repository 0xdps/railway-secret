<?php
/**
 * Docs Content Component
 * Renders the main documentation content area
 */
?>
<div id="mainContent" class="main-content" data-service-id="" data-section="docs" data-view-title="Docs" data-cache-fetched-at="0">
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
