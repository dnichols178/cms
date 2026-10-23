<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_site');

$db = get_db();
$message = '';

if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $del = $db->prepare('DELETE FROM blocks WHERE id = :id');
    $del->execute(['id' => (int)$_GET['id']]);
    header('Location: /cms/admin/blocks.php?msg=deleted');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_block') {
    $title      = trim($_POST['title'] ?? '');
    $region     = trim($_POST['region'] ?? 'sidebar');
    $body       = $_POST['body'] ?? '';
    $weight     = (int)($_POST['weight'] ?? 0);
    $status     = isset($_POST['status']) ? 1 : 0;
    $visibility = trim($_POST['visibility_pages'] ?? '');

    $ins = $db->prepare('
        INSERT INTO blocks (title, region, body, weight, status, visibility_pages) 
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $ins->execute([$title, $region, $body, $weight, $status, $visibility]);
    $message = "Block '{$title}' created successfully.";
}

$blocks = $db->query('SELECT * FROM blocks ORDER BY region ASC, weight ASC')->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Block Layout - FRNP CMS</title>
    <link rel="stylesheet" href="/cms/assets/css/admin.css">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <h1>Structure &raquo; Blocks & Regions</h1>
            <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?><div class="notice">Block removed.</div><?php endif; ?>

            <section class="card">
                <h3>Active Blocks</h3>
                <table>
                    <thead>
                        <tr><th>Title</th><th>Region</th><th>Weight</th><th>Page Visibility</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($blocks)): ?>
                            <tr><td colspan="6" style="text-align:center; color:#777;">No custom blocks created.</td></tr>
                        <?php else: ?>
                            <?php foreach ($blocks as $b): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($b['title']) ?></strong></td>
                                <td><code><?= htmlspecialchars($b['region']) ?></code></td>
                                <td><?= $b['weight'] ?></td>
                                <td><?= empty($b['visibility_pages']) ? '<em>All Pages</em>' : htmlspecialchars($b['visibility_pages']) ?></td>
                                <td>
                                    <span class="badge-status <?= $b['status'] ? 'status-published' : 'status-draft' ?>">
                                        <?= $b['status'] ? 'Active' : 'Disabled' ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="/cms/admin/blocks.php?action=delete&id=<?= $b['id'] ?>" onclick="return confirm('Delete this block?');" style="color:#c00;">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h3>Add New Block</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="save_block">
                    <div class="form-row">
                        <label>Block Title *</label>
                        <input type="text" name="title" required placeholder="e.g. Safety Hotline">
                    </div>
                    <div class="form-row">
                        <label>Placement Region *</label>
                        <select name="region">
                            <option value="sidebar">Sidebar (Right Column)</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label>Content (HTML permitted)</label>
                        <textarea name="body" rows="5" required placeholder="<p>Call the Paducah Site Operations Center...</p>"></textarea>
                    </div>
                    <div class="form-row">
                        <label>Page Visibility (comma-separated slugs; leave blank for all pages)</label>
                        <input type="text" name="visibility_pages" placeholder="e.g. home,about-us,contact">
                    </div>
                    <div class="form-row">
                        <label>Weight (Display Order)</label>
                        <input type="number" name="weight" value="10">
                    </div>
                    <div class="form-row">
                        <label><input type="checkbox" name="status" value="1" checked> Active</label>
                    </div>
                    <button type="submit" class="btn-primary">Save Block</button>
                </form>
            </section>
        </main>
    </div>
</body>
</html>