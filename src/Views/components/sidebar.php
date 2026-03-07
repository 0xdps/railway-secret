<?php
/**
 * Sidebar Component
 * 
 * Required variables:
 * @var string|null $serviceId - Current service ID
 * @var string $section - Current section (secrets/history/etc)
 * @var array $groupedServices - Services organized by group
 * @var string $csrfToken - CSRF token
 */
$currentSection = $section ?? 'secrets';
?>
<aside class="sidebar">
    <div class="sidebar-brand">
        <img src="/favicon.svg" alt="" class="brand-mark" width="16" height="16">
        Railway Secrets
    </div>

    <div class="sidebar-section-label">Project</div>
    <nav>
          <a href="/?section=overview" class="nav-link js-overview-nav <?= $currentSection === 'overview' ? 'active' : '' ?>"
              hx-get="/?section=overview"
              hx-target="#mainContent"
              hx-swap="outerHTML"
              hx-push-url="true"
              hx-indicator="#mainContentLoading">
                <i data-lucide="layout-dashboard" style="width:14px;height:14px;"></i>
                Overview
          </a>
          <a href="/?section=secrets" class="nav-link js-scope-nav <?= ($currentSection === 'secrets' && !$serviceId) ? 'active' : '' ?>"
              hx-get="/?section=secrets"
           hx-target="#mainContent"
           hx-swap="outerHTML"
           hx-push-url="true"
           hx-indicator="#mainContentLoading"
           data-service-id="">
            <i data-lucide="layers" style="width:14px;height:14px;"></i>
            Global Variables
        </a>
        <?php $historyHref = $serviceId ? '/?serviceId=' . urlencode((string)$serviceId) . '&section=history' : '/?section=history'; ?>
        <a href="<?= htmlspecialchars($historyHref, ENT_QUOTES, 'UTF-8') ?>"
           class="nav-link js-history-nav <?= $currentSection === 'history' ? 'active' : '' ?>"
           hx-get="<?= htmlspecialchars($historyHref, ENT_QUOTES, 'UTF-8') ?>"
           hx-target="#mainContent"
           hx-swap="outerHTML"
           hx-push-url="true"
           hx-indicator="#mainContentLoading">
            <i data-lucide="history" style="width:14px;height:14px;"></i>
            Rotation History
        </a>
    </nav>

    <!-- Services grouped -->
    <?php if (!empty($groupedServices)): ?>

    <div class="sidebar-section-label">Groups</div>
    <div class="sidebar-section-actions">
        <button type="button" class="nav-link nav-link-btn js-create-group" title="Create a new group and assign services">
            <i data-lucide="folder-plus" style="width:14px;height:14px;"></i>
            Create Group
        </button>
    </div>
    <div style="overflow-y:auto;flex:1;">
        <?php foreach ($groupedServices as $groupName => $groupItems): ?>
            <?php $groupServiceIdsCsv = implode(',', array_map(static fn ($svc) => (string)($svc['id'] ?? ''), $groupItems)); ?>
            <div class="sidebar-group-header" style="margin-top:12px;">
                <div class="sidebar-section-label sidebar-group-title">
                    <?= htmlspecialchars($groupName) ?>
                </div>
                <button type="button"
                        class="group-edit-btn js-edit-group"
                        data-group-name="<?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?>"
                        data-service-ids="<?= htmlspecialchars($groupServiceIdsCsv, ENT_QUOTES, 'UTF-8') ?>"
                        title="Edit group">
                    <i data-lucide="pencil" style="width:11px;height:11px;"></i>
                </button>
            </div>
            <nav>
                <?php foreach ($groupItems as $svc): ?>
                    <a href="/?serviceId=<?= htmlspecialchars($svc['id'], ENT_QUOTES, 'UTF-8') ?>" 
                       class="nav-link js-scope-nav js-grouped-service <?= ($currentSection !== 'history' && $serviceId === $svc['id']) ? 'active' : '' ?>"
                       hx-get="/?serviceId=<?= urlencode($svc['id']) ?>"
                       hx-target="#mainContent"
                       hx-swap="outerHTML"
                       hx-push-url="true"
                       hx-indicator="#mainContentLoading"
                       data-service-id="<?= htmlspecialchars($svc['id'], ENT_QUOTES, 'UTF-8') ?>"
                       data-service-name="<?= htmlspecialchars($svc['name'], ENT_QUOTES, 'UTF-8') ?>"
                       data-group-name="<?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?>">
                        <i data-lucide="box" style="width:14px;height:14px;"></i>
                        <?= htmlspecialchars($svc['name']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="sidebar-footer">
        <a href="/docs" class="nav-link <?= ($currentSection === 'docs' || strpos($_SERVER['REQUEST_URI'] ?? '', '/docs') === 0) ? 'active' : '' ?>">
            <i data-lucide="book-open" style="width:14px;height:14px;"></i>
            Docs
        </a>
        <form method="POST" action="/logout" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="nav-link nav-link-btn danger">
                <i data-lucide="log-out" style="width:14px;height:14px;"></i>
                Logout
            </button>
        </form>
    </div>
</aside>
