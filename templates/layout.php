<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/auth.php';

$can_edit_current_node = !empty($node['id']) && (
    user_has_permission('create_content') || 
    user_has_permission('edit_any_content') ||
    (isset($_SESSION['user']['roles']) && in_array('administrator', $_SESSION['user']['roles'], true))
);

// Load hierarchical menu tree (or assemble parent/child relationship from menu items)
if (function_exists('get_menu_tree')) {
    $menu_tree = get_menu_tree('main');
} else {
    $raw_items = get_menu_items('main');
    $tree = [];
    $lookup = [];
    foreach ($raw_items as $item) {
        $item['children'] = [];
        $lookup[$item['id']] = $item;
    }
    foreach ($lookup as $id => &$item) {
        if (!empty($item['parent_id']) && isset($lookup[$item['parent_id']])) {
            $lookup[$item['parent_id']]['children'][] = &$item;
        } else {
            $tree[] = &$item;
        }
    }
    unset($item);
    $menu_tree = $tree;
}

$site_name = get_site_setting('site_name', 'Four Rivers Nuclear Partnership');
$site_slogan = get_site_setting('site_slogan', 'Paducah Deactivation Project');
$sub_tagline = get_site_setting('sub_tagline', 'A Department of Energy Cleanup Contractor');
$footer_copy = get_site_setting('footer_copyright', 'Four Rivers Nuclear Partnership, LLC (FRNP). All Rights Reserved.');
$footer_disc = get_site_setting('footer_disclaimer', 'FRNP manages the Deactivation Project at the Paducah Gaseous Diffusion Plant for the U.S. Department of Energy.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($node['keywords'])): ?>
        <meta name="keywords" content="<?= htmlspecialchars($node['keywords']) ?>">
    <?php endif; ?>
    <title><?= $page_title ?? (htmlspecialchars($node['title'] ?? 'Paducah Site') . ' | ' . htmlspecialchars($site_name)) ?></title>
    <link rel="stylesheet" href="/cms/assets/css/style.css">

    <style>
        /* Dropdown Navigation Menu Styles */
        .nav-menu {
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
        }
        .nav-item {
            position: relative;
            display: inline-flex;
            align-items: center;
        }
        .nav-item .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            padding: 8px 12px;
            font-weight: 600;
            color: inherit;
            transition: color 0.15s ease, background 0.15s ease;
            border-radius: 3px;
        }
        .nav-item:hover > .nav-link {
            color: var(--frnp-gold, #c59b27);
        }
        .nav-caret {
            font-size: 0.65rem;
            display: inline-block;
            transition: transform 0.2s ease;
            margin-left: 2px;
        }
        .nav-item:hover .nav-caret {
            transform: rotate(180deg);
        }
        .nav-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            min-width: 220px;
            background: #0b2545;
            border-top: 3px solid var(--frnp-gold, #c59b27);
            border-radius: 0 0 4px 4px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25);
            padding: 6px 0;
            z-index: 1000;
        }
        .nav-item:hover > .nav-dropdown {
            display: block;
        }
        .nav-dropdown-link {
            display: block !important;
            padding: 9px 16px !important;
            color: #f1f5f9 !important;
            font-size: 0.88rem !important;
            font-weight: 500 !important;
            text-decoration: none !important;
            text-transform: none !important;
            letter-spacing: normal !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.07);
            transition: all 0.15s ease !important;
            white-space: nowrap;
        }
        .nav-dropdown-link:last-child {
            border-bottom: none;
        }
        .nav-dropdown-link:hover {
            background: #133b6d !important;
            color: var(--frnp-gold, #c59b27) !important;
            padding-left: 20px !important;
        }
    </style>
</head>
<body>
    <!-- Top Utility Bar -->
    <div class="top-bar">
        <div class="container top-bar-content">
            <span class="doe-badge"><?= htmlspecialchars($site_slogan) ?></span>
            <span class="doe-tagline"><?= htmlspecialchars($sub_tagline) ?></span>
        </div>
    </div>

    <!-- Main Navigation Header -->
    <header class="site-header">
        <div class="container main-header">
            <div class="logo">
                <a href="/cms/home">
                    <span class="logo-bold">FOUR RIVERS</span>
                    <span class="logo-sub">NUCLEAR PARTNERSHIP, LLC</span>
                </a>
            </div>
            <nav class="nav-menu">
                <?php foreach ($menu_tree as $item): ?>
                    <?php $has_children = !empty($item['children']); ?>
                    <div class="nav-item <?= $has_children ? 'has-dropdown' : '' ?>">
                        <a href="<?= htmlspecialchars($item['url']) ?>" class="nav-link">
                            <?= htmlspecialchars($item['title']) ?>
                            <?php if ($has_children): ?>
                                <span class="nav-caret">▼</span>
                            <?php endif; ?>
                        </a>

                        <?php if ($has_children): ?>
                            <div class="nav-dropdown">
                                <?php foreach ($item['children'] as $sub): ?>
                                    <a href="<?= htmlspecialchars($sub['url']) ?>" class="nav-dropdown-link">
                                        <?= htmlspecialchars($sub['title']) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if (isset($_SESSION['user'])): ?>
                    <a href="/cms/admin/index.php" class="admin-link">Dashboard</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <!-- Hero Banner with Breadcrumbs -->
    <section class="hero-banner">
        <div class="container">
            <div class="breadcrumb">
                <a href="/cms/home">Home</a> &raquo;
                <?php if (!empty($node['slug']) && $node['slug'] !== 'home'): ?>
                    <span class="current-page"><?= htmlspecialchars($node['title'] ?? '') ?></span>
                <?php else: ?>
                    <span class="current-page">Overview</span>
                <?php endif; ?>
            </div>
            <h1><?= htmlspecialchars($node['title'] ?? 'Paducah Deactivation Project') ?></h1>
        </div>
    </section>

    <!-- Main Content & Sidebar Area -->
    <main class="container main-container">
        <div class="content-wrapper">
            <!-- Contextual Quick-Edit Toolbar for Logged-In Editors -->
            <?php if ($can_edit_current_node): ?>
                <div class="node-admin-toolbar">
                    <div class="node-admin-meta">
                        <span class="badge-status <?= ($node['status'] ?? 'published') === 'published' ? 'status-published' : 'status-draft' ?>">
                            <?= strtoupper(htmlspecialchars($node['status'] ?? 'PUBLISHED')) ?>
                        </span>
                        <span><strong>Node #<?= (int)$node['id'] ?></strong> &bull; <?= htmlspecialchars($node['type_name'] ?? $node['type'] ?? 'Page') ?></span>
                    </div>
                    <div class="node-admin-actions">
                        <a href="/cms/admin/node_edit.php?id=<?= (int)$node['id'] ?>" class="btn-contextual-edit">
                            ✏ Edit This Page
                        </a>
                        <a href="/cms/admin/revisions.php?id=<?= (int)$node['id'] ?>" class="btn-contextual-rev">
                            🕒 Revisions
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
                <div class="notice" style="background:#e8f5e9; border-left:4px solid #2e7d32; padding:10px 14px; margin-bottom:18px; color:#1b5e20; font-size:0.9rem;">
                    ✓ Content changes saved and published successfully.
                </div>
            <?php endif; ?>

            <?php if (isset($node['status']) && $node['status'] !== 'published'): ?>
                <div class="draft-preview-banner">
                    ⚠️ MODERATION PREVIEW (<?= strtoupper(htmlspecialchars($node['status'])) ?>) &mdash; This version is not visible to public visitors.
                </div>
            <?php endif; ?>

            <?= $content ?? '' ?>
        </div>

        <aside class="sidebar">
            <!-- Sidebar Search Widget -->
            <div class="sidebar-box">
                <h3>Search Site</h3>
                <form method="GET" action="/cms/search" class="search-form-sidebar">
                    <input type="text" name="keyword" placeholder="Search keywords..." required>
                    <button type="submit">Go</button>
                </form>
            </div>

            <!-- Dynamic Node Sidebar Fields (Fields placed into 'Sidebar Callout Box') -->
            <?php if (!empty($node)): ?>
                <?= render_custom_fields($node, 'sidebar') ?>
            <?php endif; ?>

            <!-- Dynamic Block Injection Region -->
            <?php render_region('sidebar', $node['slug'] ?? ''); ?>

            <!-- Default Resources Widget -->
            <div class="sidebar-box">
                <h3>Quick Resources</h3>
                <ul>
                    <li><a href="/cms/news">News & Media Releases</a></li>
                    <li><a href="/cms/program-areas">Program Areas & Scope</a></li>
                    <li><a href="/cms/about-us">About FRNP Leadership</a></li>
                    <li><a href="/cms/contact">Contact Communications</a></li>
                </ul>
            </div>
        </aside>
    </main>

    <!-- Multi-Column Site Footer -->
    <footer class="site-footer">
        <div class="container footer-top">
            <div class="footer-grid">
                <div class="footer-col">
                    <h4>About FRNP</h4>
                    <p><?= htmlspecialchars($footer_disc) ?></p>
                    <p>Conducting world-class environmental remediation and facility stabilization for the U.S. Department of Energy (DOE) Portsmouth/Paducah Project Office (PPPO).</p>
                </div>

                <div class="footer-col">
                    <h4>Navigation</h4>
                    <ul>
                        <li><a href="/cms/home">Home</a></li>
                        <li><a href="/cms/about-us">About Us</a></li>
                        <li><a href="/cms/program-areas">Program Areas</a></li>
                        <li><a href="/cms/news">News & Releases</a></li>
                        <li><a href="/cms/contact">Contact Us</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h4>Site Office</h4>
                    <p>
                        <strong>Four Rivers Nuclear Partnership, LLC</strong><br>
                        5511 Hobbs Road<br>
                        Kevil, KY 42053<br>
                        Paducah Site Operations
                    </p>
                    <p>
                        <a href="/cms/contact" class="btn-download" style="margin-top: 6px; font-size: 0.8rem; padding: 6px 12px;">Contact Inquiries &raquo;</a>
                    </p>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            <div class="container footer-bottom-content">
                <div>
                    &copy; <?= date('Y') ?> <?= htmlspecialchars($footer_copy) ?>
                </div>
                <div>
                    <a href="/cms/sitemap.xml">XML Sitemap</a>
                    <a href="/cms/contact">Site Help</a>
                    <?php if (!isset($_SESSION['user'])): ?>
                        <a href="/cms/admin/index.php">Staff Login</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>