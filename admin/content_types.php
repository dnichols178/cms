<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_content_types');

$db = get_db();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_type') {
    validate_csrf();
    $machine_name = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['type'] ?? '')));
    $label = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $has_body = isset($_POST['has_body']) ? 1 : 0;

    $field_names = $_POST['field_name'] ?? [];
    $field_labels = $_POST['field_label'] ?? [];
    $field_types = $_POST['field_type'] ?? [];
    $schema = [];

    for ($i = 0; $i < count($field_names); $i++) {
        $fname = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($field_names[$i])));
        if ($fname !== '') {
            if (!str_starts_with($fname, 'field_')) {
                $fname = 'field_' . $fname;
            }
            $schema[] = [
                'name'      => $fname,
                'label'     => trim($field_labels[$i] ?? $fname),
                'type'      => trim($field_types[$i] ?? 'text'),
                'help_text' => '',
                'required'  => 0,
                'options'   => []
            ];
        }
    }

    if ($machine_name && $label) {
        $stmt = $db->prepare('INSERT INTO content_types (type, name, description, has_body, fields_schema) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$machine_name, $label, $desc, $has_body, json_encode($schema)]);
        watchdog('content_type', "New content type '{$label}' ({$machine_name}) created.", 'notice');
        $message = "Content type '{$label}' created.";
    }
}

$types = get_content_types();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Content Types - FRNP CMS</title>
    <link rel="stylesheet" href="/cms/assets/css/admin.css">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <h1>Structure &raquo; Content Types</h1>
            <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>

            <section class="card">
                <h3>Existing Content Types</h3>
                <table>
                    <thead>
                        <tr><th>Name</th><th>Machine Name</th><th>Fields</th><th>Body Field</th><th>Description</th><th>Operations</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($types as $t): ?>
                        <?php 
                            $f_count = count(json_decode($t['fields_schema'] ?? '[]', true)); 
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
                            <td><code><?= htmlspecialchars($t['type']) ?></code></td>
                            <td><strong><?= $f_count ?></strong> custom field(s)</td>
                            <td><?= $t['has_body'] ? 'Yes' : 'No' ?></td>
                            <td style="color:#555; font-size:0.85rem;"><?= htmlspecialchars($t['description'] ?? '') ?></td>
                            <td>
                                <a href="/cms/admin/content_type_fields.php?type=<?= urlencode($t['type']) ?>" class="btn-secondary" style="font-size:0.8rem; padding: 4px 10px;">
                                    + Fields
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h3>Create New Content Type</h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="create_type">
                    <div class="form-row">
                        <label>Human Name *</label>
                        <input type="text" name="name" required placeholder="e.g. Safety Bulletin">
                    </div>
                    <div class="form-row">
                        <label>Machine Name (Type) *</label>
                        <input type="text" name="type" required placeholder="e.g. safety_bulletin">
                        <small style="color: #666;">Unique system identifier. Letters, numbers, and underscores only.</small>
                    </div>
                    <div class="form-row">
                        <label>Description</label>
                        <textarea name="description" rows="2" placeholder="Describe the purpose of this content type."></textarea>
                    </div>
                    <div class="form-row">
                        <label><input type="checkbox" name="has_body" value="1" checked> Enable default WYSIWYG Body Field</label>
                    </div>

                    <h4 style="margin-top: 20px; color:#0b2545;">Initial Fields (Optional)</h4>
                    <p style="font-size:0.85rem; color:#666; margin-bottom:10px;">You can also add, edit, or configure advanced field options anytime using "Manage Fields".</p>
                    <div id="fields-container">
                        <div class="field-item">
                            <input type="text" name="field_name[]" placeholder="field_machine_name">
                            <input type="text" name="field_label[]" placeholder="Field Label">
                            <select name="field_type[]">
                                <option value="text">Text (Single Line)</option>
                                <option value="textarea">Long Text</option>
                                <option value="number">Number</option>
                                <option value="date">Date</option>
                                <option value="file">File Attachment</option>
                                <option value="image">Image Upload</option>
                                <option value="boolean">Boolean Checkbox</option>
                            </select>
                        </div>
                    </div>
                    <button type="button" onclick="addFieldRow()" class="btn-secondary" style="margin-top:8px;">+ Add Another Field</button>

                    <div style="margin-top: 25px;">
                        <button type="submit" class="btn-primary">Save Content Type</button>
                    </div>
                </form>
            </section>
        </main>
    </div>
    <script>
        function addFieldRow() {
            const container = document.getElementById('fields-container');
            const row = document.createElement('div');
            row.className = 'field-item';
            row.style.marginTop = '8px';
            row.innerHTML = `
                <input type="text" name="field_name[]" placeholder="field_machine_name">
                <input type="text" name="field_label[]" placeholder="Field Label">
                <select name="field_type[]">
                    <option value="text">Text (Single Line)</option>
                    <option value="textarea">Long Text</option>
                    <option value="number">Number</option>
                    <option value="date">Date</option>
                    <option value="file">File Attachment</option>
                    <option value="image">Image Upload</option>
                    <option value="boolean">Boolean Checkbox</option>
                </select>
            `;
            container.appendChild(row);
        }
    </script>
</body>
</html>a