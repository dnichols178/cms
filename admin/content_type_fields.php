<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_content_types');

$db = get_db();
$type_key = trim($_GET['type'] ?? '');
$content_type = get_content_type($type_key);

if (!$content_type) {
    die('Content type not found.');
}

$message = '';
$error = '';
$schema = $content_type['fields_schema'] ?? [];

// Ensure schema is ordered by weight on load
usort($schema, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));

// 1. Handle Field Deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['field'])) {
    validate_csrf();
    $field_to_delete = trim($_GET['field']);
    $new_schema = array_values(array_filter($schema, fn($f) => $f['name'] !== $field_to_delete));
    
    $upd = $db->prepare('UPDATE content_types SET fields_schema = ? WHERE type = ?');
    $upd->execute([json_encode($new_schema), $type_key]);
    watchdog('content_type', "Field '{$field_to_delete}' removed from '{$type_key}'.", 'notice');
    header("Location: /cms/admin/content_type_fields.php?type=" . urlencode($type_key) . "&msg=deleted");
    exit;
}

// 2. Handle Updating Display Settings & Arrow Order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_display') {
    validate_csrf();
    $placements = $_POST['placement'] ?? [];
    $label_displays = $_POST['label_display'] ?? [];
    $weights = $_POST['weight'] ?? [];

    foreach ($schema as &$field) {
        $fname = $field['name'];
        if (isset($placements[$fname])) {
            $field['placement'] = $placements[$fname];
        }
        if (isset($label_displays[$fname])) {
            $field['label_display'] = $label_displays[$fname];
        }
        if (isset($weights[$fname])) {
            $field['weight'] = (int)$weights[$fname];
        }
    }
    unset($field);

    // Sort schema by updated weight
    usort($schema, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));

    $upd = $db->prepare('UPDATE content_types SET fields_schema = ? WHERE type = ?');
    $upd->execute([json_encode($schema), $type_key]);
    
    watchdog('content_type', "Updated field display layout & order for '{$type_key}'.", 'notice');
    $message = "Field order and display settings saved successfully.";
    $content_type['fields_schema'] = $schema;
}

// 3. Handle Adding New Field
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_field') {
    validate_csrf();

    $label         = trim($_POST['field_label'] ?? '');
    $raw_name      = trim($_POST['field_name'] ?? '');
    $type          = trim($_POST['field_type'] ?? 'text');
    $placement     = trim($_POST['placement'] ?? 'above_body');
    $label_display = trim($_POST['label_display'] ?? 'inline');
    $help_text     = trim($_POST['help_text'] ?? '');
    $is_required   = isset($_POST['is_required']) ? 1 : 0;
    $raw_options   = trim($_POST['options'] ?? '');

    if ($label === '') {
        $error = 'Field Title / Label is required.';
    } else {
        if ($raw_name === '') {
            $clean_label = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace([' ', '-'], '_', $label)));
            $raw_name = 'field_' . ($clean_label !== '' ? $clean_label : bin2hex(random_bytes(3)));
        } else {
            $raw_name = preg_replace('/[^a-z0-9_]/', '', strtolower($raw_name));
            if (!str_starts_with($raw_name, 'field_')) {
                $raw_name = 'field_' . $raw_name;
            }
        }

        $exists = false;
        foreach ($schema as $f) {
            if ($f['name'] === $raw_name) {
                $exists = true;
                break;
            }
        }

        if ($exists) {
            $error = "A field with machine name '{$raw_name}' already exists in this content type.";
        } else {
            $parsed_options = [];
            if ($type === 'select' && $raw_options !== '') {
                foreach (explode("\n", str_replace("\r", "", $raw_options)) as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    if (str_contains($line, '|')) {
                        [$val, $opt_label] = explode('|', $line, 2);
                        $parsed_options[trim($val)] = trim($opt_label);
                    } else {
                        $parsed_options[$line] = $line;
                    }
                }
            }

            $new_field = [
                'name'          => $raw_name,
                'label'         => $label,
                'type'          => $type,
                'placement'     => $placement,
                'label_display' => $label_display,
                'weight'        => count($schema) * 10,
                'help_text'     => $help_text,
                'required'      => $is_required,
                'options'       => $parsed_options
            ];

            $schema[] = $new_field;
            usort($schema, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));

            $upd = $db->prepare('UPDATE content_types SET fields_schema = ? WHERE type = ?');
            $upd->execute([json_encode($schema), $type_key]);

            watchdog('content_type', "Field '{$label}' ({$raw_name}) added to '{$type_key}'.", 'notice');
            $message = "Field '{$label}' added and positioned in template.";
            $content_type['fields_schema'] = $schema;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Fields & Display: <?= htmlspecialchars($content_type['name']) ?> - FRNP CMS</title>
    <link rel="stylesheet" href="/cms/assets/css/admin.css">
    <style>
        .grid-layout { display: grid; grid-template-columns: 1.65fr 1fr; gap: 25px; }
        .type-badge { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; background: #e0e6ed; color: #0b2545; }
        .placement-select { font-size: 0.85rem; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 3px; background: #fff; }
        
        /* Up / Down Arrow Button Controls */
        .arrow-controls {
            display: inline-flex;
            gap: 4px;
            align-items: center;
        }
        .btn-arrow {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.72rem;
            color: #0b2545;
            transition: all 0.15s ease;
            user-select: none;
            line-height: 1;
        }
        .btn-arrow:hover:not(:disabled) {
            background: #0b2545;
            color: #ffffff;
            border-color: #0b2545;
        }
        .btn-arrow:disabled {
            opacity: 0.3;
            cursor: not-allowed;
            background: #f8fafc;
            color: #94a3b8;
            border-color: #e2e8f0;
        }
        .row-highlight {
            animation: flash-bg 0.5s ease-out;
        }
        @keyframes flash-bg {
            0% { background-color: #fef3c7; }
            100% { background-color: transparent; }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <div style="font-size: 0.85rem; color: #666; margin-bottom: 8px;">
                <a href="/cms/admin/content_types.php" style="color: #0b2545; text-decoration: none;">&laquo; Content Types</a> &raquo;
                <span><?= htmlspecialchars($content_type['name']) ?></span>
            </div>
            <h1>Manage Fields & Display: <?= htmlspecialchars($content_type['name']) ?> (<code><?= htmlspecialchars($content_type['type']) ?></code>)</h1>

            <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?><div class="notice">Field removed from content type.</div><?php endif; ?>
            <?php if ($error): ?><div class="notice" style="background:#fee; border-color:#c00; color:#c00;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="grid-layout">
                <!-- Reorder & Display Table Form -->
                <section class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 12px;">
                        <h3>Template Layout & Reordering</h3>
                        <small style="color: #666;">Use arrows to order fields, then click Save.</small>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="update_display">

                        <table>
                            <thead>
                                <tr>
                                    <th>Field</th>
                                    <th>Template Placement</th>
                                    <th>Label Style</th>
                                    <th style="text-align: center; width: 80px;">Order</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Core Title Info -->
                                <tr style="background: #fafafa; color: #555;">
                                    <td>
                                        <strong>Title</strong><br>
                                        <code>title</code>
                                    </td>
                                    <td><em style="font-size: 0.85rem; color: #0b2545;">Page Banner (H1)</em></td>
                                    <td><em style="font-size: 0.85rem;">Above</em></td>
                                    <td style="text-align: center; color: #aaa; font-size: 0.8rem;">Top</td>
                                    <td><em style="font-size:0.8rem; color:#888;">Core</em></td>
                                </tr>

                                <?php if ($content_type['has_body']): ?>
                                <tr style="background: #fafafa; color: #555;">
                                    <td>
                                        <strong>Body Content</strong><br>
                                        <code>body</code>
                                    </td>
                                    <td><em style="font-size: 0.85rem; color: #0b2545;">Body Container (.node-body)</em></td>
                                    <td><em style="font-size: 0.85rem;">&lt;Hidden&gt;</em></td>
                                    <td style="text-align: center; color: #aaa; font-size: 0.8rem;">Main</td>
                                    <td><em style="font-size:0.8rem; color:#888;">Core</em></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>

                            <!-- Custom Fields Reorder Body -->
                            <tbody id="custom-fields-body">
                                <?php if (empty($schema)): ?>
                                    <tr id="empty-notice-row"><td colspan="5" style="text-align:center; color:#777; padding:20px;">No custom fields created yet. Add one using the form on the right.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($schema as $idx => $f): ?>
                                    <?php 
                                        $f_placement = $f['placement'] ?? 'above_body';
                                        $f_label_disp = $f['label_display'] ?? 'inline';
                                        $f_weight = $f['weight'] ?? ($idx * 10);
                                    ?>
                                    <tr class="sortable-field-row">
                                        <td>
                                            <strong><?= htmlspecialchars($f['label']) ?></strong>
                                            <?php if (!empty($f['required'])): ?><span style="color:#c00;">*</span><?php endif; ?><br>
                                            <code><?= htmlspecialchars($f['name']) ?></code> &bull; <span class="type-badge"><?= htmlspecialchars($f['type']) ?></span>
                                            <!-- Hidden weight input -->
                                            <input type="hidden" name="weight[<?= htmlspecialchars($f['name']) ?>]" class="field-weight-input" value="<?= $f_weight ?>">
                                        </td>
                                        <td>
                                            <select name="placement[<?= htmlspecialchars($f['name']) ?>]" class="placement-select">
                                                <option value="above_body" <?= $f_placement === 'above_body' ? 'selected' : '' ?>>Above Body / Content</option>
                                                <option value="below_body" <?= $f_placement === 'below_body' ? 'selected' : '' ?>>Below Body / Content</option>
                                                <option value="header_meta" <?= $f_placement === 'header_meta' ? 'selected' : '' ?>>Header / Top Meta</option>
                                                <option value="sidebar" <?= $f_placement === 'sidebar' ? 'selected' : '' ?>>Sidebar Callout Box</option>
                                                <option value="hidden" <?= $f_placement === 'hidden' ? 'selected' : '' ?>>&lt;Do Not Render / Hidden&gt;</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="label_display[<?= htmlspecialchars($f['name']) ?>]" class="placement-select">
                                                <option value="inline" <?= $f_label_disp === 'inline' ? 'selected' : '' ?>>Inline (Label: Value)</option>
                                                <option value="above" <?= $f_label_disp === 'above' ? 'selected' : '' ?>>Above</option>
                                                <option value="hidden" <?= $f_label_disp === 'hidden' ? 'selected' : '' ?>>&lt;Hidden&gt;</option>
                                            </select>
                                        </td>
                                        <td style="text-align: center;">
                                            <div class="arrow-controls">
                                                <button type="button" class="btn-arrow btn-up" onclick="moveRow(this, -1)" title="Move Up">▲</button>
                                                <button type="button" class="btn-arrow btn-down" onclick="moveRow(this, 1)" title="Move Down">▼</button>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="/cms/admin/content_type_fields.php?type=<?= urlencode($type_key) ?>&action=delete&field=<?= urlencode($f['name']) ?>&csrf_token=<?= get_csrf_token() ?>" 
                                               onclick="return confirm('Remove this field from this content type?');" 
                                               style="color:#c00; font-size:0.8rem;">Delete</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <?php if (!empty($schema)): ?>
                            <div style="margin-top: 18px;">
                                <button type="submit" class="btn-primary">Save Display Layout</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </section>

                <!-- Add New Field Form -->
                <section class="card">
                    <h3>Add New Field</h3>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="add_field">

                        <div class="form-row">
                            <label>Field Title / Label *</label>
                            <input type="text" name="field_label" id="field_label" required placeholder="e.g. Safety Data Sheet PDF" onkeyup="autoMachineName(this.value)">
                        </div>

                        <div class="form-row">
                            <label>Machine Name</label>
                            <input type="text" name="field_name" id="field_name" placeholder="field_safety_data_sheet">
                            <small style="color: #666;">Auto-generated if left blank.</small>
                        </div>

                        <div class="form-row">
                            <label>Field Type *</label>
                            <select name="field_type" id="field_type" onchange="toggleOptionsBox(this.value)" style="width:100%; padding:8px;">
                                <option value="text">Text (Single Line)</option>
                                <option value="textarea">Long Text (Multi-Line)</option>
                                <option value="number">Number (Integer / Decimal)</option>
                                <option value="date">Date</option>
                                <option value="file">File (PDF / Document Download)</option>
                                <option value="image">Image (Photo / Diagram Upload)</option>
                                <option value="boolean">Boolean Checkbox (Yes/No)</option>
                                <option value="select">List / Select (Dropdown Menu)</option>
                            </select>
                        </div>

                        <div class="form-row">
                            <label>Placement in Node Template *</label>
                            <select name="placement" style="width:100%; padding:8px;">
                                <option value="above_body" selected>Above Body / Content</option>
                                <option value="below_body">Below Body / Content (e.g. Downloads, Footers)</option>
                                <option value="header_meta">Header / Top Meta (Near category badge & date)</option>
                                <option value="sidebar">Sidebar Callout Box (Right column widget)</option>
                                <option value="hidden">&lt;Do Not Render / Hidden&gt;</option>
                            </select>
                        </div>

                        <div class="form-row">
                            <label>Label Display *</label>
                            <select name="label_display" style="width:100%; padding:8px;">
                                <option value="inline" selected>Inline (e.g. "Label: Value")</option>
                                <option value="above">Above (Label on own line)</option>
                                <option value="hidden">&lt;Hidden&gt; (Render value only)</option>
                            </select>
                        </div>

                        <div class="form-row" id="select_options_row" style="display: none;">
                            <label>Allowed Values (One per line) *</label>
                            <textarea name="options" rows="4" placeholder="groundwater|Groundwater Protection&#10;deactivation|Facility Deactivation"></textarea>
                        </div>

                        <div class="form-row">
                            <label>Help Text</label>
                            <input type="text" name="help_text" placeholder="Instructions shown to editors in admin form.">
                        </div>

                        <div class="form-row">
                            <label style="font-weight: normal;">
                                <input type="checkbox" name="is_required" value="1">
                                <strong>Required Field</strong>
                            </label>
                        </div>

                        <button type="submit" class="btn-primary" style="margin-top: 10px; width: 100%;">Add Field to Content Type</button>
                    </form>
                </section>
            </div>
        </main>
    </div>

    <script>
        function autoMachineName(val) {
            const clean = val.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
            document.getElementById('field_name').value = clean ? 'field_' + clean : '';
        }

        function toggleOptionsBox(type) {
            const row = document.getElementById('select_options_row');
            row.style.display = (type === 'select') ? 'block' : 'none';
        }

        // Up & Down Arrow Row Reordering Engine
        function moveRow(btn, direction) {
            const row = btn.closest('tr');
            const tbody = document.getElementById('custom-fields-body');
            
            if (direction === -1) {
                // Move Up
                const prev = row.previousElementSibling;
                if (prev && prev.classList.contains('sortable-field-row')) {
                    tbody.insertBefore(row, prev);
                    triggerHighlight(row);
                }
            } else if (direction === 1) {
                // Move Down
                const next = row.nextElementSibling;
                if (next && next.classList.contains('sortable-field-row')) {
                    tbody.insertBefore(next, row);
                    triggerHighlight(row);
                }
            }

            refreshOrderAndButtons();
        }

        function triggerHighlight(row) {
            row.classList.remove('row-highlight');
            void row.offsetWidth; // Force CSS repaint
            row.classList.add('row-highlight');
        }

        function refreshOrderAndButtons() {
            const rows = document.querySelectorAll('#custom-fields-body .sortable-field-row');
            rows.forEach((row, index) => {
                // Recalculate weights sequentially: 0, 10, 20, 30...
                const input = row.querySelector('.field-weight-input');
                if (input) {
                    input.value = index * 10;
                }

                // Update button disabled boundaries
                const upBtn = row.querySelector('.btn-up');
                const downBtn = row.querySelector('.btn-down');
                if (upBtn) upBtn.disabled = (index === 0);
                if (downBtn) downBtn.disabled = (index === rows.length - 1);
            });
        }

        // Initialize button boundary states on page load
        document.addEventListener('DOMContentLoaded', refreshOrderAndButtons);
    </script>
</body>
</html>