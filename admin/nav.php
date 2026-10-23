<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// Single source of truth for admin navigation links
$admin_menu_items = [
    [
        'title'      => 'Content',
        'url'        => '/cms/admin/nodes.php',
        'file'       => 'nodes.php',
        'permission' => 'create_content',
    ],
    [
        'title'      => 'Media & Files',
        'url'        => '/cms/admin/media.php',
        'file'       => 'media.php',
        'permission' => 'create_content',
    ],
    [
        'title'      => 'Taxonomy',
        'url'        => '/cms/admin/taxonomy.php',
        'file'       => 'taxonomy.php',
        'permission' => 'administer_site',
    ],
    [
        'title'      => 'Menus',
        'url'        => '/cms/admin/menus.php',
        'file'       => 'menus.php',
        'permission' => 'administer_site',
    ],
    [
        'title'      => 'Maintenance',
        'url'        => '/cms/admin/cron.php',
        'file'       => 'cron.php',
        'permission' => 'administer_site',
    ],
    [
        'title'      => 'Content Types',
        'url'        => '/cms/admin/content_types.php',
        'file'       => 'content_types.php',
        'permission' => 'administer_content_types',
    ],
    [
        'title'      => 'Permissions',
        'url'        => '/cms/admin/permissions.php',
        'file'       => 'permissions.php',
        'permission' => 'administer_site',
    ],
    [
        'title'      => 'Emails & Queue',
        'url'        => '/cms/admin/emails.php',
        'file'       => 'emails.php',
        'permission' => 'administer_site',
    ],
    [
        'title'      => 'Users',
        'url'        => '/cms/admin/users.php',
        'file'       => 'users.php',
        'permission' => 'administer_users',
    ],
    [
        'title'      => 'Redirects',
        'url'        => '/cms/admin/redirects.php',
        'file'       => 'redirects.php',
        'permission' => 'administer_site',
    ],
    [
        'title'      => 'Settings',
        'url'        => '/cms/admin/settings.php',
        'file'       => 'settings.php',
        'permission' => 'administer_site',
    ],
    [
        'title'      => 'Blocks',
        'url'        => '/cms/admin/blocks.php',
        'file'       => 'blocks.php',
        'permission' => 'administer_site',
    ],
    [
        'title'      => 'Custom Pages',
        'url'        => '/cms/admin/custom_pages.php',
        'file'       => 'custom_pages.php',
        'permission' => 'administer_site',
    ],
    [
        'title'      => 'Inquiries',
        'url'        => '/cms/admin/submissions.php',
        'file'       => 'submissions.php',
        'permission' => 'administer_site',
    ],
    [
        'title'      => 'Backup',
        'url'        => '/cms/admin/backup.php',
        'file'       => 'backup.php',
        'permission' => 'administer_site',
    ],
    [
        'title'      => 'Static Export',
        'url'        => '/cms/admin/static_export.php',
        'file'       => 'static_export.php',
        'permission' => 'administer_site',
    ],
    [
        'title'      => 'Watchdog Logs',
        'url'        => '/cms/admin/watchdog.php',
        'file'       => 'watchdog.php',
        'permission' => 'administer_site',
    ],
    [
        'title'      => 'Performance',
        'url'        => '/cms/admin/cache.php',
        'file'       => 'cache.php',
        'permission' => 'administer_site',
    ],
    [
        'title'      => 'Migrate',
        'url'        => '/cms/admin/migrate_drupal.php',
        'file'       => 'migrate.php',
        'permission' => 'administer_site',
    ],
];

// Determine the current running script filename (e.g. 'nodes.php')
$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
if ($current_script === 'node_edit.php') {
    $current_script = 'nodes.php'; // Highlight "Content" when editing nodes
}
?>
<aside class="admin-nav">
    <h2>FRNP Admin</h2>
    <a href="/cms/">View Site</a>
    <?php foreach ($admin_menu_items as $item): ?>
        <?php if ($item['permission'] === null || user_has_permission($item['permission'])): ?>
            <?php 
                $is_active = ($current_script === $item['file']);
            ?>
            <a href="<?= htmlspecialchars($item['url']) ?>" class="<?= $is_active ? 'active' : '' ?>">
                <?= htmlspecialchars($item['title']) ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>

    <a href="/cms/admin/logout.php" style="color: #ff8a80; border-top: 1px solid rgba(255,255,255,0.1); margin-top: 20px;">Log Out</a>
</aside>