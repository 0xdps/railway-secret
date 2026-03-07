<?php
/**
 * Documentation View - uses shared layout
 * 
 * Required variables (from public/index.php):
 * @var string $csrfToken
 * @var array $groupedServices
 * @var array $timeConfig
 */

$pageScript = 'docs.js';

$extraHead = <<<'HTML'
    <script src="/js/dashboard.js" defer></script>
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
HTML;

$serviceId = null;
$section = 'docs';
$viewTitle = 'Docs';

$contentCallback = function() {
    include __DIR__ . '/components/docs-content.php';
};

include __DIR__ . '/layout.php';
