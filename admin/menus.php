<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_site');

$db = get_db();
$message = '';
$error = '';
$menu_name = 'main';

// Ensure table has parent_id column
try {
    $col_check = $db->query("SHOW COLUMNS FROM menu_items LIKE 'parent_id'")->fetch();
    if (!$col_check) {
        $db->exec("ALTER TABLE menu_items ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL AFTER weight, ADD INDEX (parent_id)");
    }
} catch (\Throwable $e) {}

// 1. Handle Deleting a Menu Item
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    validate_csrf();
    $del_id = (int)$_GET['id'];

    // Re-parent any direct child items to root before deleting
    $reparent = $db->prepare('UPDATE menu_items SET parent_id = NULL WHERE parent_id = ?');
    $reparent->execute([$del_id]);

    $del = $db->prepare('DELETE FROM menu_items WHERE id = ?');
    $del->execute([$del_id]);

    if (function_exists('cache_clear')) {
        cache_clear();
    }

    watchdog('menu', "Deleted menu item ID: {$del_id}.", 'notice');
    header('Location: /cms/admin/menu.php?msg=deleted');
    exit;
}

// 2. Handle Updating Hierarchy & Ordering (Batch Save)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_hierarchy') {
    validate_csrf();
    $weights = $_POST['weights'] ?? [];
    $parents = $_POST['parents'] ?? [];
    $titles  = $_POST['titles'] ?? [];
    $urls    = $_POST['urls'] ?? [];

    $upd = $db->prepare('UPDATE menu_items SET title = :t, url = :u, parent_id = :p, weight = :w WHERE id = :id');

    foreach ($weights as $item_id => $weight) {
        $item_id = (int)$item_id;
        $parent_val = !empty($parents[$item_id]) ? (int)$parents[$item_id] : null;

        // Prevent self-parenting loop
        if ($parent_val === $item_id) {
            $parent_val = null;
        }

        $title_val = trim($titles[$item_id] ?? '');
        $url_val   = trim($urls[$item_id] ?? '');

        if ($title_val !== '' && $url_val !== '') {
            $upd->execute([
                't'  => $title_val,
                'u'  => $url_val,
                'p'  => $parent_val,
                'w'  => (int)$weight,
                'id' => $item_id
            ]);
        }
    }

    if (function_exists('cache_clear')) {
        cache_clear();
    }

    watchdog('menu', 'Updated main menu hierarchy and link orders.', 'notice');
    $message = 'Menu structure and ordering saved successfully.';
}

// 3. Handle Adding New Menu Link
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_link') {
    validate_csrf();

    $title  = trim($_POST['title'] ?? '');
    $url    = trim($_POST['url'] ?? '');
    $parent = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    $weight = (int)($_POST['weight'] ?? 0);

    if ($title === '' || $url === '') {
        $error = 'Both Link Title and URL Path are required.';
    } else {
        // Normalize internal path
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://') && !str_starts_with($url, '/')) {
            $url = '/cms/' . ltrim($url, '/');
        }

        $ins = $db->prepare('
            INSERT INTO menu_items (menu_name, title, url, parent_id, weight)
            VALUES (:m, :t, :u, :p, :w)
        ');
        $ins->execute([
            'm' => $menu_name,
            't' => $title,
            'u' => $url,
            'p' => $parent,
            'w' => $weight
        ]);

        if (function_exists('cache_clear')) {
            cache_clear();
        }

        watchdog('menu', "Added menu link '{$title}' -> {$url}", 'notice');
        $message = "Menu link '{$title}' added successfully.";
    }
}

// Fetch all menu items for the table and parent selectors
$raw_items = $db->query('SELECT * FROM menu_items WHERE menu_name = "main" ORDER BY weight ASC, id ASC')->fetchAll();

// Build hierarchical structure for tabular rendering
$items_by_parent = [];
$top_level_items = [];

foreach ($raw_items as $item) {
    $pid = $item['parent_id'] ? (int)$item['parent_id'] : 0;
    $items_by_parent[$pid][] = $item;
    if ($pid === 0) {
        $top_level_items[] = $item;
    }
}

// Flatten into ordered hierarchical list for display
$flattened_menu = [];
function flatten_menu_branch(int $parent_id, int $depth, array $items_by_parent, array &$output): void {
    if (empty($items_by_parent[$parent_id])) {
        return;
    }
    foreach ($items_by_parent[$parent_id] as $child) {
        $child['depth'] = $depth;
        $output[] = $child;
        flatten_menu_branch((int)$child['id'], $depth + 1, $items_by_parent, $output);
    }
}
flatten_menu_branch(0, 0, $items_by_parent, $flattened_menu);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Menu Management - FRNP CMS</title>
    <link rel="stylesheet" href="/cms/assets/css/admin.css">
    <style>
        .grid-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.8fr) 360px;
            gap: 25px;
            align-items: start;
        }
        @media (max-width: 1080px) {
            .grid-layout { grid-template-columns: 1fr; }
        }
        .menu-table input[type="text"],
        .menu-table select,
        .menu-table input[type="number"] {
            padding: 6px 8px;
            font-size: 0.88rem;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .menu-table select { width: 100%; min-width: 160px; }
        .depth-0 { font-weight: 700; color: #0b2545; }
        .depth-1 { padding-left: 28px !important; color: #334155; }
        .depth-2 { padding-left: 50px !important; color: #64748b; }
        .badge-parent {
            display: inline-block;
            font-size: 0.72rem;
            padding: 2px 6px;
            background: #e0f2fe;
            color: #0369a1;
            border-radius: 3px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-child {
            display: inline-block;
            font-size: 0.72rem;
            padding: 2px 6px;
            background: #f1f5f9;
            color: #475569;
            border-radius: 3px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>

        <main class="admin-main" style="max-width: 1350px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h1>Structure &raquo; Main Navigation Menu</h1>
                <a href="/cms/home" target="_blank" class="btn-secondary" style="font-size:0.85rem;">View Live Site &raquo;</a>
            </div>

            <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?><div class="notice">Menu link removed. Any child links have been safely re-parented to the top level.</div><?php endif; ?>
            <?php if ($error): ?><div class="notice" style="background:#fee; border-color:#c00; color:#c00;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="grid-layout">
                <!-- Menu Structure Table -->
                <section class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                        <h3>Menu Links & Hierarchy</h3>
                        <small style="color:#64748b;">Set parents to create hover dropdowns on the front end.</small>
                    </div>

                    <?php if (empty($flattened_menu)): ?>
                        <div style="padding: 30px; text-align: center; color: #64748b; border: 1px dashed #cbd5e1; border-radius: 6px;">
                            No menu links configured. Use the form on the right to add your first navigation link.
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                            <input type="hidden" name="action" value="save_hierarchy">

                            <table class="menu-table">
                                <thead>
                                    <tr>
                                        <th>Link Title</th>
                                        <th>Target URL</th>
                                        <th style="width: 210px;">Parent Item</th>
                                        <th style="width: 80px; text-align:center;">Weight</th>
                                        <th style="width: 70px; text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($flattened_menu as $item): ?>
                                    <?php 
                                        $id = (int)$item['id'];
                                        $depth = (int)$item['depth'];
                                        $has_children = !empty($items_by_parent[$id]);
                                    ?>
                                    <tr style="<?= $depth > 0 ? 'background: #fafbfc;' : 'background: #ffffff;' ?>">
                                        <td class="depth-<?= min(2, $depth) ?>">
                                            <?php if ($depth > 0): ?>
                                                <span style="color: #94a3b8; font-weight: normal; margin-right: 4px;">↳</span>
                                            <?php endif; ?>
                                            <input type="text" name="titles[<?= $id ?>]" value="<?= htmlspecialchars($item['title']) ?>" style="font-weight: <?= $depth === 0 ? '700' : '500' ?>; width: calc(100% - 30px);">
                                            <?php if ($has_children): ?>
                                                <br><span class="badge-parent" style="margin-top: 4px;">Has Submenu</span>
                                            <?php elseif ($depth > 0): ?>
                                                <br><span class="badge-child" style="margin-top: 4px;">Dropdown Link</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <input type="text" name="urls[<?= $id ?>]" value="<?= htmlspecialchars($item['url']) ?>" style="width: 100%;">
                                        </td>
                                        <td>
                                            <select name="parents[<?= $id ?>]">
                                                <option value="">&lt;Top Level / Main Menu&gt;</option>
                                                <?php foreach ($top_level_items as $p): ?>
                                                    <?php if ((int)$p['id'] !== $id): ?>
                                                        <option value="<?= (int)$p['id'] ?>" <?= ((int)$item['parent_id'] === (int)$p['id']) ? 'selected' : '' ?>>
                                                            ↳ <?= htmlspecialchars($p['title']) ?>
                                                        </option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td style="text-align: center;">
                                            <input type="number" name="weights[<?= $id ?>]" value="<?= (int)$item['weight'] ?>" style="width: 65px; text-align: center;">
                                        </td>
                                        <td style="text-align: right;">
                                            <a href="/cms/admin/menu.php?action=delete&id=<?= $id ?>&csrf_token=<?= get_csrf_token() ?>" 
                                               onclick="return confirm('Remove &quot;<?= htmlspecialchars(addslashes($item['title'])) ?>&quot; from the menu? Any child links will become top-level links.');" 
                                               style="color: #c00; font-size: 0.82rem; text-decoration: none;">Delete</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid #e2e8f0;">
                                <button type="submit" class="btn-primary" style="padding: 10px 22px;">Save Menu Hierarchy</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>

                <!-- Add New Menu Link Form -->
                <section class="card">
                    <h3>Add Menu Link</h3>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="add_link">

                        <div class="form-row">
                            <label>Menu Link Title *</label>
                            <input type="text" name="title" required placeholder="e.g. Tour Reservations">
                        </div>

                        <div class="form-row">
                            <label>URL / Path *</label>
                            <input type="text" name="url" required placeholder="e.g. /cms/tours or https://...">
                            <small style="color:#64748b;">Internal paths will automatically prepend <code>/cms/</code>.</small>
                        </div>

                        <div class="form-row">
                            <label>Parent Menu Item</label>
                            <select name="parent_id" style="width: 100%; padding: 8px;">
                                <option value="">&lt;Top Level / Main Menu&gt;</option>
                                <?php foreach ($top_level_items as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>">
                                        ↳ <?= htmlspecialchars($p['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color:#64748b;">Select a parent to make this link a dropdown submenu item.</small>
                        </div>

                        <div class="form-row">
                            <label>Weight / Order</label>
                            <input type="number" name="weight" value="<?= count($raw_items) * 5 ?>" style="width: 90px;">
                            <small style="color:#64748b;">Lower numbers render first from left to right.</small>
                        </div>

                        <button type="submit" class="btn-primary" style="width: 100%; margin-top: 10px; padding: 10px;">Add Link to Menu</button>
                    </form>
                </section>
            </div>
        </main>
    </div>
</body>
</html>