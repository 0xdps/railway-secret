<?php
/**
 * About Page Component
 *
 * @var string $viewTitle
 * @var string $csrfToken
 */
global $appConfig;
$dev  = $appConfig['dev'];
$repo = $appConfig['repo'];
?>
<div id="mainContent" class="main-content"
     data-service-id=""
     data-section="about"
     data-view-title="About"
     data-cache-fetched-at="0">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-left">
            <h1>About Railway Secrets</h1>
            <p>The origin, the developer, and how you can help.</p>
        </div>
    </div>

    <!-- Page Body -->
    <div class="page-body page-body--docs">
        <div class="docs-layout">

            <!-- TOC -->
            <nav class="docs-toc">
                <div class="docs-toc-label">On this page</div>
                <a href="#story">The Story</a>
                <a href="#features">What it does</a>
                <a href="#developer">The Developer</a>
                <a href="#support">Support the Project</a>
            </nav>

        <div class="about-page">

            <!-- ── Origin Story ─────────────────────────────────────── -->
            <section class="about-section" id="story">
                <div class="about-section-eyebrow">The Story</div>
                <h2 class="about-section-title">It started with a security audit we didn't expect to fail.</h2>

                <div class="about-prose">
                    <p>
                        You've heard a version of this story before. It might have been a leaked API key in a public
                        repo, a database password unchanged since day one, or a token copy-pasted across five services
                        and never touched again. The names change. The shape of the disaster doesn't. Almost every
                        serious security incident starts the same way: something low-priority, quietly forgotten,
                        sitting in plaintext until the day it isn't.
                    </p>
                    <p>
                        December 2025. A routine security review on a Railway project — three services, about a year
                        old. The auditor flags a <code>SESSION_SECRET</code> that hadn't been rotated since initial
                        deploy. Same value, still live. Then they find it committed in a <code>docker-compose.yml</code>
                        in an old public repo branch. That branch had been merged and forgotten ten months earlier.
                    </p>
                    <p>
                        No breach confirmed. But the key had been sitting in plaintext in a public git history for
                        almost a year. The audit report listed six more variables in the same state. Every single one
                        needed to be rotated, immediately.
                    </p>
                    <p>
                        So we did it the Railway way: open the dashboard, navigate to each service, find the variable,
                        click Edit, type the new value, save, wait for the deploy. Times three services. Times seven
                        variables. Twenty-one manual edits. Twenty-one redeployment cycles. An entire afternoon, gone
                        — clicking through the same four screens over and over like some kind of penance.
                    </p>
                    <p>
                        Somewhere around the fifteenth edit, a thought: <em>this is exactly why people skip rotation.
                        The tooling makes the secure thing feel like punishment.</em> If rotating secrets were as
                        fast as changing a setting, everyone would do it. Instead, the friction wins every time.
                    </p>
                    <p>
                        That evening: one PHP file, one table, one button per secret. One click → one Railway GraphQL
                        call → one redeploy. It worked on the first run. The next weekend — scheduled rotation and
                        audit history. The weekend after — multi-service support, batch redeployments so rotating
                        ten keys in one service only triggers a single deploy instead of ten.
                    </p>
                    <p>
                        By January 2026 it was running on every personal Railway project and had silently rotated
                        over 200 secrets without a single manual touch. By March 2026 it had survived two more
                        security reviews, both clean.
                    </p>
                    <p>
                        That's the whole story. A bad afternoon, a one-evening prototype, and a tool that turned
                        a twenty-one-step chore into a scheduled background job. If you're reading this —
                        I hope it gives you your afternoon back too.
                    </p>
                </div>
            </section>

            <!-- ── Features callout ────────────────────────────────── -->
            <section class="about-section" id="features">
                <div class="about-section-eyebrow">What it does</div>
                <div class="about-features-grid">
                    <div class="about-feature-card">
                        <div class="about-feature-icon"><i data-lucide="layers" style="width:16px;height:16px;"></i></div>
                        <div class="about-feature-title">All variables, one place</div>
                        <div class="about-feature-body">Browse and search every environment variable across all your Railway services without leaving a single screen.</div>
                    </div>
                    <div class="about-feature-card">
                        <div class="about-feature-icon"><i data-lucide="rotate-cw" style="width:16px;height:16px;"></i></div>
                        <div class="about-feature-title">One-click & scheduled rotation</div>
                        <div class="about-feature-body">Rotate any secret instantly, or configure an interval and let the built-in cron handle it while you sleep.</div>
                    </div>
                    <div class="about-feature-card">
                        <div class="about-feature-icon"><i data-lucide="history" style="width:16px;height:16px;"></i></div>
                        <div class="about-feature-title">Full audit trail</div>
                        <div class="about-feature-body">Every rotation is logged with trigger type, timestamp, old value, and new value — encrypted at rest.</div>
                    </div>
                    <div class="about-feature-card">
                        <div class="about-feature-icon"><i data-lucide="zap" style="width:16px;height:16px;"></i></div>
                        <div class="about-feature-title">Single redeploy per service</div>
                        <div class="about-feature-body">When cron rotates multiple keys in one service, they're batched into a single Railway API call — one redeploy, not ten.</div>
                    </div>
                    <div class="about-feature-card">
                        <div class="about-feature-icon"><i data-lucide="lock" style="width:16px;height:16px;"></i></div>
                        <div class="about-feature-title">Self-hosted &amp; private</div>
                        <div class="about-feature-body">Runs entirely inside your own Railway project. No third-party servers, no SaaS subscriptions, no data leaves your account.</div>
                    </div>
                    <div class="about-feature-card">
                        <div class="about-feature-icon"><i data-lucide="package" style="width:16px;height:16px;"></i></div>
                        <div class="about-feature-title">One-click deploy</div>
                        <div class="about-feature-body">Deploy directly from the Railway template marketplace. Add your token, mount a volume, you're done.</div>
                    </div>
                </div>
            </section>

            <!-- ── Developer ───────────────────────────────────────── -->
            <section class="about-section" id="developer">
                <div class="about-section-eyebrow">The Developer</div>
                <div class="about-dev-card">
                    <img
                        src="<?= htmlspecialchars($dev['avatar'], ENT_QUOTES, 'UTF-8') ?>"
                        alt="<?= htmlspecialchars($dev['name'], ENT_QUOTES, 'UTF-8') ?>"
                        class="about-dev-avatar"
                        width="64"
                        height="64"
                        loading="lazy"
                    >
                    <div class="about-dev-info">
                        <div class="about-dev-name"><?= htmlspecialchars($dev['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="about-dev-handle"><?= htmlspecialchars($dev['handle'], ENT_QUOTES, 'UTF-8') ?></div>
                        <p class="about-dev-bio"><?= htmlspecialchars($dev['bio'], ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="about-dev-links">
                            <a href="<?= htmlspecialchars($dev['portfolio'], ENT_QUOTES, 'UTF-8') ?>"
                               class="about-dev-link"
                               target="_blank"
                               rel="noopener noreferrer">
                                <i data-lucide="globe" style="width:13px;height:13px;"></i>
                                Portfolio
                            </a>
                            <a href="<?= htmlspecialchars($dev['github'], ENT_QUOTES, 'UTF-8') ?>"
                               class="about-dev-link"
                               target="_blank"
                               rel="noopener noreferrer">
                                <i data-lucide="github" style="width:13px;height:13px;"></i>
                                GitHub
                            </a>
                            <a href="<?= htmlspecialchars($repo['source'], ENT_QUOTES, 'UTF-8') ?>"
                               class="about-dev-link"
                               target="_blank"
                               rel="noopener noreferrer">
                                <i data-lucide="code-2" style="width:13px;height:13px;"></i>
                                Source Code
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ── Support ─────────────────────────────────────────── -->
            <section class="about-section" id="support">
                <div class="about-section-eyebrow">Support the Project</div>
                <h2 class="about-section-title">If this saved you time, pay it forward.</h2>
                <div class="about-prose" style="margin-bottom: 24px;">
                    <p>
                        Railway Secrets is free, open-source, and will stay that way. If it's saving you
                        time or keeping your services more secure, the best things you can do are:
                    </p>
                </div>
                <div class="about-support-grid">
                    <a href="<?= htmlspecialchars($repo['star'], ENT_QUOTES, 'UTF-8') ?>"
                       class="about-support-card"
                       target="_blank"
                       rel="noopener noreferrer">
                        <div class="about-support-icon">
                            <i data-lucide="star" style="width:20px;height:20px;"></i>
                        </div>
                        <div class="about-support-title">Star on GitHub</div>
                        <div class="about-support-body">
                            A star helps others discover the project and shows that it's worth maintaining.
                            It's the single highest-value thing you can do.
                        </div>
                        <div class="about-support-action">Star the repo ↗</div>
                    </a>
                    <a href="<?= htmlspecialchars($dev['support'], ENT_QUOTES, 'UTF-8') ?>"
                       class="about-support-card"
                       target="_blank"
                       rel="noopener noreferrer">
                        <div class="about-support-icon">
                            <i data-lucide="coffee" style="width:20px;height:20px;"></i>
                        </div>
                        <div class="about-support-title">Buy Me a Coffee</div>
                        <div class="about-support-body">
                            If this tool saved you time or a bad afternoon, buy me a coffee.
                            It directly funds time spent on features, fixes, and keeping things up to date.
                        </div>
                        <div class="about-support-action">buymeacoffee.com/0xdps ↗</div>
                    </a>
                    <div class="about-support-card about-support-card-muted">
                        <div class="about-support-icon">
                            <i data-lucide="message-circle" style="width:20px;height:20px;"></i>
                        </div>
                        <div class="about-support-title">Spread the Word</div>
                        <div class="about-support-body">
                            Know someone building on Railway? Send them the template link. Word of mouth
                            from people who actually use it is worth more than any ad.
                        </div>
                        <div class="about-support-action" style="color: var(--text-muted);">Share with a friend</div>
                    </div>
                </div>
            </section>

            <!-- ── Version note ────────────────────────────────────── -->
            <div class="about-version-note">
                <i data-lucide="terminal" style="width:12px;height:12px;"></i>
                Railway Secrets &nbsp;·&nbsp; Open-source, self-hosted &nbsp;·&nbsp;
                <a href="<?= htmlspecialchars($repo['license'], ENT_QUOTES, 'UTF-8') ?>" class="footer-link" target="_blank" rel="noopener noreferrer">MIT License</a>
            </div>

        </div><!-- /about-page -->
        </div><!-- /docs-layout -->
    </div><!-- /page-body -->

</div><!-- /mainContent -->
