<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('create_content');

$db = get_db();

// Handle deletion if requested and permitted
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    require_permission('delete_any_content');
    $del = $db->prepare('DELETE FROM nodes WHERE id = :id');
    $del->execute(['id' => (int)$_GET['id']]);
    header('Location: /cms/admin/nodes.php?msg=deleted');
    exit;
}

$stmt = $db->query('
    SELECT n.id, n.type, n.title, n.slug, n.status, n.created_at, ct.name AS content_type_label, u.username
    FROM nodes n
    JOIN content_types ct ON ct.type = n.type
    JOIN users u ON u.id = n.uid
    ORDER BY n.updated_at DESC
');
$nodes = $stmt->fetchAll();
$types = get_content_types();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Content Manager - FRNP CMS</title>
    <link rel="stylesheet" href="/cms/assets/css/admin.css">
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
<?php require __DIR__ . '/nav.php'; ?>
        </aside>
        <main class="admin-main">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h1>Content Repository</h1>
                <div class="dropdown-add">
                    <span>+ Add Content: </span>
                    <?php foreach ($types as $t): ?>
                        <a href="/cms/admin/node_edit.php?type=<?= urlencode($t['type']) ?>" class="btn-primary" style="margin-left: 6px; padding: 6px 12px; font-size: 0.85rem;">
                            <?= htmlspecialchars($t['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
                <div class="notice">Content node removed successfully.</div>
            <?php endif; ?>

            <section class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Author</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($nodes)): ?>
                            <tr><td colspan="6" style="text-align:center; color:#777;">No content nodes found. Create one above.</td></tr>
                        <?php else: ?>
                            <?php foreach ($nodes as $n): ?>
                            <tr>
                                <td>
                                    <strong><a href="/cms/<?= htmlspecialchars($n['slug']) ?>" target="_blank"><?= htmlspecialchars($n['title']) ?></a></strong>
                                    <div style="font-size: 0.8rem; color: #666;">/<?= htmlspecialchars($n['slug']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($n['content_type_label']) ?></td>
                                <td><?= htmlspecialchars($n['username']) ?></td>
                                <td>
    <?php 
        $badgeClass = match($n['status']) {
            'published' => 'status-published',
            'in_review'  => 'status-review',
            'archived'   => 'status-archived',
            default      => 'status-draft',
        };
    ?>
    <span class="badge-status <?= $badgeClass ?>">
        <?= htmlspecialchars(strtoupper(str_replace('_', ' ', $n['status']))) ?>
    </span>
</td>
                                <td><?= date('M j, Y', strtotime($n['created_at'])) ?></td>
                                <td>
                                    <a href="/cms/admin/node_edit.php?id=<?= $n['id'] ?>">Edit</a>
                                    <?php if (user_has_permission('delete_any_content')): ?>
                                        | <a href="/cms/admin/nodes.php?action=delete&id=<?= $n['id'] ?>" onclick="return confirm('Permanently delete this node?');" style="color: #c00;">Delete</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>