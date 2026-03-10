<?php
/**
 * Docs Content Component — terminal-noir redesign
 * Rewritten from scratch.
 */
?>
<div id="mainContent" class="main-content" data-service-id="" data-section="docs" data-view-title="Docs" data-cache-fetched-at="0">
    <div class="page-header">
        <div class="page-header-left">
            <h1>Documentation</h1>
            <p>Configuration, deployment, and operational reference for Railway Secrets.</p>
        </div>
    </div>

    <div class="page-body page-body--docs">
        <div class="docs-layout">

            <!-- TOC -->
            <nav class="docs-toc">
                <div class="docs-toc-label">On this page</div>
                <a href="#how-it-works">How it works</a>
                <a href="#env-vars">Environment variables</a>
                <a href="#rotation">Rotation modes</a>
                <a href="#groups">Service groups</a>
                <a href="#deployment">Deployment</a>
                <a href="#security">Security model</a>
            </nav>

            <!-- Body -->
            <div class="docs-body">

                <h2 id="how-it-works">How it works</h2>
                <p>
                    Railway Secrets is a self-hosted dashboard that sits on top of the
                    Railway GraphQL API. You define how each environment variable should
                    be rotated &mdash; its byte length, encoding, and schedule &mdash; and Railway
                    Secrets handles the rest: generating new values, pushing them to
                    Railway, triggering service redeploys, and archiving the previous
                    value in an encrypted SQLite database.
                </p>
                <p style="margin-top: 10px;">
                    There are two actors that drive rotation: the <strong>dashboard</strong>
                    (you, on demand) and the <strong>built-in cron</strong> (automated,
                    on schedule). Both share the same configuration and the same
                    encrypted storage.
                </p>
                <ul style="margin-top: 10px;">
                    <li><strong>Click Rotate</strong> on any managed secret to rotate instantly.</li>
                    <li>The <strong>built-in cron</strong> runs <code>cron.php</code> every minute inside the container and rotates every secret whose interval has elapsed since its last rotation.</li>
                    <li>Every rotation writes the <em>previous</em> value &mdash; AES-256-GCM encrypted &mdash; to SQLite. You can inspect or copy it from Rotation History.</li>
                    <li>Unmanaged secrets (no config yet) are listed but never rotated automatically.</li>
                </ul>

                <hr class="docs-divider">

                <h2 id="env-vars">Environment variables</h2>
                <p>Set the following variables in your Railway service before deploying.</p>

                <div class="panel" style="overflow: hidden; margin-top: 14px;">
                    <table class="env-table">
                        <thead>
                            <tr>
                                <th>Variable</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>RAILWAY_TOKEN</td>
                                <td>Railway API token with project-level write access. Generate one from <strong>Account &rarr; Tokens</strong> in the Railway dashboard.</td>
                            </tr>
                            <tr>
                                <td>RAILWAY_PROJECT_ID</td>
                                <td>Auto-injected by Railway for the running service. The fallback variable <code>PROJECT_ID</code> is also accepted.</td>
                            </tr>
                            <tr>
                                <td>RAILWAY_ENVIRONMENT_ID</td>
                                <td>Auto-injected by Railway for the running service. The fallback variable <code>ENVIRONMENT_ID</code> is also accepted.</td>
                            </tr>
                            <tr>
                                <td>ADMIN_KEY</td>
                                <td>Password for the dashboard login page. Use a strong random string &mdash; at least 32 characters.</td>
                            </tr>
                            <tr>
                                <td>SESSION_SECRET</td>
                                <td>Secret used to sign and encrypt the session cookie. Rotate this if you suspect it has been leaked.</td>
                            </tr>
                            <tr>
                                <td>MASTER_KEY</td>
                                <td>Master encryption key for the secret history database. <strong>Back this up.</strong> History entries are unreadable without it.</td>
                            </tr>
                            <tr>
                                <td>TRUSTED_PROXY_IPS</td>
                                <td>Optional. Comma-separated list of proxy IP addresses to trust for <code>X-Forwarded-For</code> headers. Leave empty if not behind a proxy.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <hr class="docs-divider">

                <h2 id="rotation">Rotation modes</h2>
                <p>
                    Every secret is configured independently. The configuration covers
                    three things: value length, encoding format, and rotation schedule.
                    Both rotation modes &mdash; manual and scheduled &mdash; use the exact same
                    configuration.
                </p>

                <h3>Manual (dashboard)</h3>
                <p>
                    Click <strong>Rotate</strong> on any managed secret row. Railway Secrets will:
                </p>
                <ol>
                    <li>Generate a cryptographically random value using the configured length and encoding.</li>
                    <li>Push the new value to Railway via the GraphQL API.</li>
                    <li>Railway automatically redeploys every service that references the variable.</li>
                    <li>Save the previous value, AES-256-GCM encrypted, to the SQLite history table.</li>
                </ol>

                <h3>Scheduled (cron)</h3>
                <p>
                    <code>crond</code> runs inside the container and executes <code>cron.php</code> every minute.
                    On each run it reads all managed secrets from the database and rotates
                    any secret whose <strong>Rotation Interval</strong> has elapsed since the
                    secret's last recorded rotation. No separate cron service is needed.
                </p>

                <div class="callout callout-info">
                    <i data-lucide="info" style="width:14px;height:14px;"></i>
                    <span>
                        The interval is configured per secret (in days, hours, or minutes). The cron process
                        runs every minute &mdash; your interval controls whether a secret is due on that tick.
                        Set it to <code>0</code> to make a secret manual-only; the cron job will skip it.
                    </span>
                </div>

                <hr class="docs-divider">

                <h2 id="groups">Service groups</h2>
                <p>
                    When a project grows beyond a handful of services, the sidebar can become hard to
                    scan. Service groups let you organise services into named, collapsible sections so
                    related services stay together.
                </p>

                <h3>Creating a group</h3>
                <p>
                    Click the <strong>+</strong> button next to the <em>Services</em> label in the sidebar.
                    A modal will appear where you give the group a name and select which services belong to it.
                    You can assign the same service to multiple groups.
                </p>

                <h3>Editing a group</h3>
                <p>
                    Hover a group header in the sidebar and click the <strong>pencil</strong> icon to rename
                    the group or change its members. To remove a group entirely, clear all its members and save
                    &mdash; the empty group is deleted automatically.
                </p>

                <h3>Ungrouped services</h3>
                <p>
                    Services not assigned to any custom group appear under an <em>Ungrouped</em> section.
                    This section disappears once every service belongs to a group. When no custom groups
                    exist at all, all services are shown under a single <em>Services</em> bucket which
                    cannot be renamed or deleted.
                </p>

                <h3>Collapse state</h3>
                <p>
                    The open/closed state of each group is persisted to <code>localStorage</code> and
                    restored on every page load. The group containing the currently-active service is
                    always expanded automatically.
                </p>

                <hr class="docs-divider">

                <h2 id="deployment">Deployment</h2>
                <ol>
                    <li>Push or fork this repository to your GitHub account.</li>
                    <li>In Railway, create a new service from the repository (select <strong>Deploy from GitHub</strong>).</li>
                    <li>Set all the environment variables listed above in the service settings.</li>
                    <li>Create a Railway <strong>Volume</strong> and mount it to <code>/var/www/html/storage</code> so the SQLite databases persist across redeploys.</li>
                    <li>Deploy the service. Open the dashboard URL, log in with your <code>ADMIN_KEY</code>, and start adding rotation configurations.</li>
                </ol>

                <div class="callout callout-info">
                    <i data-lucide="lightbulb" style="width:14px;height:14px;"></i>
                    <span>
                        After the first login, click <strong>Sync Cache</strong> from the secrets page
                        if Railway variables do not appear immediately. The cache warms automatically
                        on login, but a manual sync forces a fresh fetch from the Railway API.
                    </span>
                </div>

                <hr class="docs-divider">

                <h2 id="security">Security model</h2>

                <h3>What is stored</h3>
                <p>
                    Railway Secrets does <strong>not</strong> store the live value of any secret.
                    The only values written to disk are the <em>previous</em> values saved
                    after each rotation &mdash; and only if the rotation succeeds. The current live
                    value lives exclusively in Railway.
                </p>

                <h3>Encryption at rest</h3>
                <p>
                    Every history entry is encrypted on write using <strong>AES-256-GCM</strong>
                    with a unique random nonce per entry. The encryption key is derived from
                    your <code>MASTER_KEY</code> environment variable. The SQLite file on disk
                    contains no plaintext secret values.
                </p>
                <p style="margin-top: 8px;">
                    The service name and project cache (SQLite) is also encrypted at rest
                    with the same master key. The cache holds metadata only &mdash; no values.
                </p>

                <h3>Session security</h3>
                <p>
                    The browser session cookie is both HMAC-signed and AES-encrypted using
                    <code>SESSION_SECRET</code>. Tampered or expired cookies are rejected at
                    every request. There is no "remember me" &mdash; sessions expire on browser close.
                </p>

                <h3>CSRF protection</h3>
                <p>
                    Every state-changing request (rotation, config update, group management)
                    requires a valid CSRF token bound to the session. Tokens are verified on
                    the server before any mutation is applied.
                </p>

                <h3>Cron isolation</h3>
                <p>
                    <code>cron.php</code> checks <code>PHP_SAPI</code> at startup and aborts
                    immediately if it is not running under the CLI SAPI. The script cannot be
                    triggered via HTTP &mdash; it must be executed from the command line.
                </p>

                <h3>API token scope</h3>
                <p>
                    The <code>RAILWAY_TOKEN</code> only needs write access to the specific
                    project Railway Secrets is deployed into. Use the minimum required scope &mdash;
                    do not use an account-level token.
                </p>

            </div><!-- /docs-body -->
        </div><!-- /docs-layout -->
    </div><!-- /page-body -->
</div><!-- /main-content -->
