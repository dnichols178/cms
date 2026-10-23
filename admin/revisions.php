<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('create_content');

$node_id = (int)($_GET['id'] ?? 0);
$node = get_node_by_id($node_id);

if (!$node) {
    die('Content node not found.');
}

$message = '';
if (isset($_GET['action']) && $_GET['action'] === 'rollback' && isset($_GET['rev_id'])) {
    require_permission('edit_any_content');
    $rev_id = (int)$_GET['rev_id'];
    if (rollback_node_revision($rev_id, current_user()['id'])) {
        $message = "Successfully rolled back node to Revision #{$rev_id}.";
        $node = get_node_by_id($node_id); // Reload fresh state
    }
}

$revisions = get_node_revisions($node_id);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Revisions: <?= htmlspecialchars($node['title']) ?> - FRNP CMS</title>
    <link rel="stylesheet" href="/cms/assets/css/admin.css">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <h1>Revisions for: "<?= htmlspecialchars($node['title']) ?>"</h1>
            <p style="margin-bottom: 20px;"><a href="/cms/admin/node_edit.php?type=<?= urlencode($node['type']) ?>&id=<?= $node['id'] ?>" class="btn-secondary">&laquo; Back to Node Editor</a></p>

            <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>

            <section class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Revision #</th>
                            <th>Timestamp</th>
                            <th>Editor</th>
                            <th>Log Message</th>
                            <th>Saved Title</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($revisions as $index => $rev): ?>
                        <tr style="<?= $index === 0 ? 'background: #f7fafc; font-weight: 500;' : '' ?>">
                            <td>#<?= $rev['id'] ?> <?= $index === 0 ? '<span class="badge-status status-published" style="margin-left: 5px;">Current</span>' : '' ?></td>
                            <td><?= date('Y-m-d H:i:s', strtotime($rev['created_at'])) ?></td>
                            <td><?= htmlspecialchars($rev['username']) ?></td>
                            <td><?= htmlspecialchars($rev['log_message'] ?? 'No notes') ?></td>
                            <td><?= htmlspecialchars($rev['title']) ?></td>
                            <td>
                                <?php if ($index > 0): ?>
                                    <a href="/cms/admin/revisions.php?id=<?= $node['id'] ?>&action=rollback&rev_id=<?= $rev['id'] ?>" 
                                       onclick="return confirm('Roll back to this exact version? Current changes will be archived in revisions.');" 
                                       class="btn-secondary" style="font-size: 0.8rem; padding: 3px 8px;">Rollback</a>
                                <?php else: ?>
                                    <em style="color: #888;">Active Version</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>