<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_permission('administer_site');

$db = get_db();

if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $del = $db->prepare('DELETE FROM contact_submissions WHERE id = :id');
    $del->execute(['id' => (int)$_GET['id']]);
    header('Location: /cms/admin/submissions.php?msg=deleted');
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'mark_read' && isset($_GET['id'])) {
    $up = $db->prepare('UPDATE contact_submissions SET status = "read" WHERE id = :id');
    $up->execute(['id' => (int)$_GET['id']]);
    header('Location: /cms/admin/submissions.php');
    exit;
}

$submissions = $db->query('SELECT * FROM contact_submissions ORDER BY created_at DESC')->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Contact Inquiries - FRNP CMS</title>
    <link rel="stylesheet" href="/cms/assets/css/admin.css">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <h1>Inbound Communications &raquo; Contact Submissions</h1>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?><div class="notice">Message record deleted.</div><?php endif; ?>

            <section class="card">
                <table>
                    <thead>
                        <tr><th>Status</th><th>Date</th><th>Sender</th><th>Subject</th><th>Message Excerpt</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($submissions)): ?>
                            <tr><td colspan="6" style="text-align:center; color:#777;">No messages received.</td></tr>
                        <?php else: ?>
                            <?php foreach ($submissions as $s): ?>
                            <tr style="<?= $s['status'] === 'unread' ? 'background: #f0f7ff; font-weight: 500;' : '' ?>">
                                <td>
                                    <span class="badge-status <?= $s['status'] === 'unread' ? 'status-draft' : 'status-published' ?>">
                                        <?= strtoupper($s['status']) ?>
                                    </span>
                                </td>
                                <td><?= date('Y-m-d H:i', strtotime($s['created_at'])) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($s['name']) ?></strong><br>
                                    <a href="mailto:<?= htmlspecialchars($s['email']) ?>" style="font-size: 0.8rem; color:#555;"><?= htmlspecialchars($s['email']) ?></a>
                                </td>
                                <td><?= htmlspecialchars($s['subject']) ?></td>
                                <td style="max-width: 320px; font-size: 0.85rem; color:#444;">
                                    <?= nl2br(htmlspecialchars($s['message'])) ?>
                                </td>
                                <td>
                                    <?php if ($s['status'] === 'unread'): ?>
                                        <a href="/cms/admin/submissions.php?action=mark_read&id=<?= $s['id'] ?>" class="btn-secondary" style="font-size:0.75rem; padding:3px 6px;">Mark Read</a>
                                    <?php endif; ?>
                                    <a href="/cms/admin/submissions.php?action=delete&id=<?= $s['id'] ?>" onclick="return confirm('Delete message?');" style="color:#c00; margin-left: 6px; font-size: 0.8rem;">Delete</a>
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