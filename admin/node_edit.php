<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('create_content');

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$node = null;

if ($id) {
    $node = get_node_by_id($id);
    if (!$node) {
        die('Content node not found.');
    }
    $type_key = $node['type'];
} else {
    $type_key = $_GET['type'] ?? 'page';
}

$content_type = get_content_type($type_key);
if (!$content_type) {
    die('Content type not found.');
}

// Available terms and media assets for drawer
$all_terms = get_all_taxonomy_terms();
$current_term_ids = $node ? array_column($node['terms'], 'id') : [];
$existing_menu_link = $id ? get_node_menu_link($id, 'main') : null;
$top_level_menu_items = get_top_level_menu_items('main', $id);

$media_dir = __DIR__ . '/../uploads/files/';
$media_files = [];
if (is_dir($media_dir)) {
    foreach (scandir($media_dir, SCANDIR_SORT_DESCENDING) as $f) {
        if ($f !== '.' && $f !== '..' && $f !== '.htaccess') {
            $media_files[] = [
                'name'   => $f,
                'url'    => '/cms/uploads/files/' . rawurlencode($f),
                'is_img' => (bool)preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $f)
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $title = trim($_POST['title'] ?? '');
    $slug_input = trim($_POST['slug'] ?? '');
    $slug = generate_node_slug($title, $content_type['type'], $slug_input !== '' ? $slug_input : null, $id);

    $custom_data = $node['fields_data'] ?? [];
    
    // Process all custom field types
    foreach ($content_type['fields_schema'] as $f) {
        $fname = $f['name'];
        $ftype = $f['type'];

        if ($ftype === 'file' || $ftype === 'image') {
            if (!empty($_FILES['custom_file_' . $fname]['name']) && $_FILES['custom_file_' . $fname]['error'] === UPLOAD_ERR_OK) {
                $up = $_FILES['custom_file_' . $fname];
                $ext = strtolower(pathinfo($up['name'], PATHINFO_EXTENSION));
                $clean_name = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($up['name'], PATHINFO_FILENAME));
                $new_filename = sprintf('%s_%s.%s', $clean_name, bin2hex(random_bytes(3)), $ext);
                $dest = $media_dir . $new_filename;
                if (move_uploaded_file($up['tmp_name'], $dest)) {
                    $custom_data[$fname] = '/cms/uploads/files/' . $new_filename;
                }
            }
        } elseif ($ftype === 'boolean') {
            $custom_data[$fname] = isset($_POST['custom_' . $fname]) ? 1 : 0;
        } else {
            $custom_data[$fname] = trim($_POST['custom_' . $fname] ?? '');
        }
    }

    $selected_terms = array_map('intval', $_POST['terms'] ?? []);

    $save_payload = [
        'type'         => $content_type['type'],
        'title'        => $title,
        'slug'         => $slug,
        'body'         => $_POST['body'] ?? '',
        'keywords'     => trim($_POST['keywords'] ?? ''),
        'fields_data'  => $custom_data,
        'uid'          => current_user()['id'],
        'status'       => in_array($_POST['status'] ?? '', ['draft', 'in_review', 'published', 'archived'], true) ? $_POST['status'] : 'draft',
        'publish_on'   => !empty($_POST['publish_on']) ? str_replace('T', ' ', $_POST['publish_on']) : null,
        'unpublish_on' => !empty($_POST['unpublish_on']) ? str_replace('T', ' ', $_POST['unpublish_on']) : null,
        'template'     => !empty($_POST['template']) ? trim($_POST['template']) : null
    ];

    // save_node returns the saved/inserted node ID directly
    $saved_id = save_node($save_payload, $id, $selected_terms);

    // Process Main Menu Link for Basic Pages
    if ($content_type['type'] === 'page') {
        $menu_enabled = isset($_POST['menu_link_enabled']);
        $menu_title = trim($_POST['menu_link_title'] ?? '') ?: $title;
        $menu_parent_id = !empty($_POST['menu_parent_id']) ? (int)$_POST['menu_parent_id'] : null;
        $menu_weight = (int)($_POST['menu_link_weight'] ?? 0);
        save_node_menu_link($saved_id, $slug, $menu_title, $menu_enabled, $menu_parent_id, $menu_weight, 'main');
    }

    // Redirect based on action button
    if (isset($_POST['submit_action']) && $_POST['submit_action'] === 'save_admin') {
        header("Location: /cms/admin/nodes.php?saved=1");
    } else {
        header("Location: /cms/" . rawurlencode($slug) . "?saved=1");
    }
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= $id ? 'Edit' : 'Create' ?> <?= htmlspecialchars($content_type['name']) ?> - FRNP CMS</title>
    <link rel="stylesheet" href="/cms/assets/css/admin.css">
    
    <!-- Trumbowyg WYSIWYG Editor Core & Plugins -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Trumbowyg/2.27.3/ui/trumbowyg.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Trumbowyg/2.27.3/plugins/colors/ui/trumbowyg.colors.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Trumbowyg/2.27.3/plugins/table/ui/trumbowyg.table.min.css">
    
    <style>
        .admin-main {
            max-width: 1300px;
            width: 100%;
            box-sizing: border-box;
        }

        /* 2-Column Responsive CSS Grid Layout */
        .editor-container {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 340px;
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 1024px) {
            .editor-container {
                grid-template-columns: 1fr;
            }
        }

        .editor-main {
            min-width: 0;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            box-sizing: border-box;
        }

        .editor-sidebar {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .sidebar-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 18px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            box-sizing: border-box;
        }

        .sidebar-card h3 {
            font-size: 0.95rem;
            color: #0b2545;
            margin: 0 0 14px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #f1f5f9;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-row {
            margin-bottom: 16px;
        }

        .form-row:last-child {
            margin-bottom: 0;
        }

        .form-row label {
            display: block;
            font-weight: 600;
            font-size: 0.88rem;
            color: #1e293b;
            margin-bottom: 6px;
        }

        .form-row input[type="text"],
        .form-row input[type="number"],
        .form-row input[type="date"],
        .form-row input[type="datetime-local"],
        .form-row select,
        .form-row textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 0.9rem;
            box-sizing: border-box;
            background: #ffffff;
            color: #1e293b;
            font-family: inherit;
        }

        .form-row input:focus,
        .form-row select:focus,
        .form-row textarea:focus {
            border-color: #0b2545;
            outline: none;
            box-shadow: 0 0 0 2px rgba(11, 37, 69, 0.15);
        }

        .form-row small {
            display: block;
            color: #64748b;
            font-size: 0.8rem;
            margin-top: 4px;
            line-height: 1.4;
        }

        .media-drawer-box {
            max-height: 230px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            padding: 8px 12px;
            border-radius: 4px;
            background: #f8fafc;
        }

        .media-item-pill {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 7px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.82rem;
            gap: 8px;
        }

        .media-item-pill:last-child {
            border-bottom: none;
        }

        .form-actions-card {
            margin-top: 24px;
            padding: 18px 24px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            display: flex;
            gap: 12px;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            box-sizing: border-box;
        }

        /* Trumbowyg CSS Overrides */
        .trumbowyg-box {
            border: 1px solid #cbd5e1 !important;
            border-radius: 4px;
            margin-top: 6px;
            background: #ffffff;
            width: 100% !important;
            box-sizing: border-box !important;
        }

        .trumbowyg-toolbar {
            background: #f8fafc !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }

        .trumbowyg-editor {
            min-height: 380px !important;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            font-size: 15px;
            line-height: 1.65;
            color: #2c3e50;
            padding: 16px !important;
            box-sizing: border-box !important;
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>

        <main class="admin-main">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h1><?= $id ? 'Edit' : 'Create' ?> <?= htmlspecialchars($content_type['name']) ?></h1>
                <?php if ($id): ?>
                    <a href="/cms/admin/revisions.php?id=<?= $id ?>" class="btn-secondary" style="font-size:0.85rem;">🕒 View Revision History</a>
                <?php endif; ?>
            </div>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">

                <div class="editor-container">
                    <!-- Main Content Column -->
                    <div class="editor-main">
                        <div class="form-row">
                            <label>Title *</label>
                            <input type="text" name="title" value="<?= htmlspecialchars($node['title'] ?? '') ?>" required autofocus>
                        </div>

                        <div class="form-row">
                            <label>URL Slug</label>
                            <input type="text" name="slug" value="<?= htmlspecialchars($node['slug'] ?? '') ?>" placeholder="leave-blank-for-automatic">
                            <small>Leave blank to automatically generate a clean, search-friendly URL.</small>
                        </div>

                        <div class="form-row">
                            <label>Search Keywords & Meta Tags</label>
                            <input type="text" name="keywords" value="<?= htmlspecialchars($node['keywords'] ?? '') ?>" placeholder="e.g. groundwater, remediation, C-400, safety protocol">
                            <small>Comma-separated keywords. Matches in this field receive top priority in search results and populate search metadata.</small>
                        </div>

                        <?php if ($content_type['has_body']): ?>
                        <div class="form-row" style="margin-top: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: baseline;">
                                <label>Body Content</label>
                                <small>WYSIWYG active &bull; Click 'View HTML' to edit raw source</small>
                            </div>
                            <textarea id="node_body" name="body"><?= htmlspecialchars($node['body'] ?? '') ?></textarea>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($content_type['fields_schema'])): ?>
                            <fieldset class="custom-fields-group" style="border: 1px solid #cbd5e1; padding: 20px; margin-top: 25px; border-radius: 6px; background: #f8fafc;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                                    <legend style="font-weight: bold; color: #0b2545; font-size: 1.05rem; padding: 0 8px;">Custom Fields</legend>
                                    <a href="/cms/admin/content_type_fields.php?type=<?= urlencode($content_type['type']) ?>" target="_blank" style="font-size:0.8rem; color:#0b2545; text-decoration:none; font-weight:600;">⚙ Manage Fields &raquo;</a>
                                </div>

                                <?php foreach ($content_type['fields_schema'] as $field): ?>
                                    <?php 
                                        $val = $node['fields_data'][$field['name']] ?? ''; 
                                        $req_attr = !empty($field['required']) ? 'required' : '';
                                    ?>
                                    <div class="form-row" style="margin-bottom: 16px;">
                                        <label>
                                            <?= htmlspecialchars($field['label']) ?>
                                            <?php if (!empty($field['required'])): ?>
                                                <span style="color:#c00;">*</span>
                                            <?php endif; ?>
                                        </label>

                                        <?php if ($field['type'] === 'textarea'): ?>
                                            <textarea name="custom_<?= htmlspecialchars($field['name']) ?>" rows="4" <?= $req_attr ?>><?= htmlspecialchars((string)$val) ?></textarea>

                                        <?php elseif ($field['type'] === 'number'): ?>
                                            <input type="number" step="any" name="custom_<?= htmlspecialchars($field['name']) ?>" value="<?= htmlspecialchars((string)$val) ?>" <?= $req_attr ?>>

                                        <?php elseif ($field['type'] === 'date'): ?>
                                            <input type="date" name="custom_<?= htmlspecialchars($field['name']) ?>" value="<?= htmlspecialchars((string)$val) ?>" <?= $req_attr ?>>

                                        <?php elseif ($field['type'] === 'boolean'): ?>
                                            <label style="font-weight: normal; display: inline-flex; align-items: center; gap: 8px; margin-top: 4px;">
                                                <input type="checkbox" name="custom_<?= htmlspecialchars($field['name']) ?>" value="1" <?= !empty($val) ? 'checked' : '' ?>>
                                                Enable / Yes
                                            </label>

                                        <?php elseif ($field['type'] === 'select'): ?>
                                            <select name="custom_<?= htmlspecialchars($field['name']) ?>" <?= $req_attr ?>>
                                                <option value="">-- Select an option --</option>
                                                <?php foreach (($field['options'] ?? []) as $opt_key => $opt_label): ?>
                                                    <option value="<?= htmlspecialchars((string)$opt_key) ?>" <?= ((string)$val === (string)$opt_key) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars((string)$opt_label) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                        <?php elseif ($field['type'] === 'file' || $field['type'] === 'image'): ?>
                                            <input type="file" name="custom_file_<?= htmlspecialchars($field['name']) ?>" <?= (empty($val) ? $req_attr : '') ?>>
                                            <?php if (!empty($val)): ?>
                                                <div style="font-size:0.85rem; margin-top:6px; display:flex; align-items:center; gap:10px;">
                                                    <?php if ($field['type'] === 'image'): ?>
                                                        <img src="<?= htmlspecialchars((string)$val) ?>" alt="" style="max-height: 48px; border: 1px solid #ccc; border-radius: 3px;">
                                                    <?php endif; ?>
                                                    <span>Current: <a href="<?= htmlspecialchars((string)$val) ?>" target="_blank"><?= htmlspecialchars(basename((string)$val)) ?></a></span>
                                                </div>
                                            <?php endif; ?>

                                        <?php else: ?>
                                            <input type="text" name="custom_<?= htmlspecialchars($field['name']) ?>" value="<?= htmlspecialchars((string)$val) ?>" <?= $req_attr ?>>
                                        <?php endif; ?>

                                        <?php if (!empty($field['help_text'])): ?>
                                            <small><?= htmlspecialchars($field['help_text']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </fieldset>
                        <?php endif; ?>
                    </div>

                    <!-- Sidebar Settings Column -->
                    <div class="editor-sidebar">
                        <!-- Publishing Settings -->
                        <div class="sidebar-card">
                            <h3>Publishing Settings</h3>
                            <div class="form-row">
                                <label>Moderation State</label>
                                <?php $current_status = $node['status'] ?? 'published'; ?>
                                <select name="status">
                                    <option value="draft" <?= $current_status === 'draft' ? 'selected' : '' ?>>Draft (Internal Working Copy)</option>
                                    <option value="in_review" <?= $current_status === 'in_review' ? 'selected' : '' ?>>In Review (Pending Editor Approval)</option>
                                    <option value="published" <?= $current_status === 'published' ? 'selected' : '' ?>>Published (Live to Public)</option>
                                    <option value="archived" <?= $current_status === 'archived' ? 'selected' : '' ?>>Archived (Offline / Retracted)</option>
                                </select>
                            </div>

                            <div class="form-row">
                                <label>Schedule Publish (Optional)</label>
                                <input type="datetime-local" name="publish_on" 
                                       value="<?= !empty($node['publish_on']) ? date('Y-m-d\TH:i', strtotime($node['publish_on'])) : '' ?>">
                            </div>

                            <div class="form-row">
                                <label>Schedule Unpublish (Optional)</label>
                                <input type="datetime-local" name="unpublish_on" 
                                       value="<?= !empty($node['unpublish_on']) ? date('Y-m-d\TH:i', strtotime($node['unpublish_on'])) : '' ?>">
                            </div>

                            <div class="form-row">
                                <label>Template Override</label>
                                <input type="text" name="template" value="<?= htmlspecialchars($node['template'] ?? '') ?>" placeholder="e.g. node--custom">
                            </div>
                        </div>

                        <!-- Menu Settings (Basic Pages Only) -->
                        <?php if ($content_type['type'] === 'page'): ?>
                        <div class="sidebar-card">
                            <h3>Menu Settings</h3>
                            <div class="form-row">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600;">
                                    <input type="checkbox" name="menu_link_enabled" id="menu_link_enabled" value="1" 
                                           <?= $existing_menu_link ? 'checked' : '' ?> 
                                           onchange="toggleMenuFields(this.checked)">
                                    Provide a menu link
                                </label>
                            </div>

                            <div id="menu_details_box" style="display: <?= $existing_menu_link ? 'block' : 'none' ?>; margin-top: 12px; border-top: 1px solid #f1f5f9; padding-top: 12px;">
                                <div class="form-row">
                                    <label>Menu Link Title</label>
                                    <input type="text" name="menu_link_title" id="menu_link_title" 
                                           value="<?= htmlspecialchars($existing_menu_link['title'] ?? '') ?>" 
                                           placeholder="Defaults to page title">
                                    <small>Navigation link label displayed in header.</small>
                                </div>

                                <div class="form-row">
                                    <label>Parent Menu Item</label>
                                    <select name="menu_parent_id">
                                        <option value="">&lt;Main Menu (Top Level)&gt;</option>
                                        <?php foreach ($top_level_menu_items as $p_item): ?>
                                            <option value="<?= $p_item['id'] ?>" <?= ((int)($existing_menu_link['parent_id'] ?? 0) === (int)$p_item['id']) ? 'selected' : '' ?>>
                                                ↳ <?= htmlspecialchars($p_item['title']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small>Select a parent to make this page appear as a hover dropdown item.</small>
                                </div>

                                <div class="form-row">
                                    <label>Menu Weight / Order</label>
                                    <input type="number" name="menu_link_weight" 
                                           value="<?= (int)($existing_menu_link['weight'] ?? 0) ?>" 
                                           style="width:90px;">
                                    <small>Lower weights display first.</small>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Taxonomy & Tags -->
                        <div class="sidebar-card">
                            <h3>Taxonomy & Tags</h3>
                            <div style="max-height: 180px; overflow-y: auto;">
                                <?php if (empty($all_terms)): ?>
                                    <small>No taxonomy terms defined.</small>
                                <?php else: ?>
                                    <?php foreach ($all_terms as $t): ?>
                                        <label style="display: block; font-weight: normal; margin-bottom: 6px; font-size: 0.85rem;">
                                            <input type="checkbox" name="terms[]" value="<?= $t['id'] ?>" <?= in_array($t['id'], $current_term_ids) ? 'checked' : '' ?>>
                                            <?= htmlspecialchars($t['name']) ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Media / Asset Drawer -->
                        <div class="sidebar-card">
                            <h3>Media / Asset Drawer</h3>
                            <div class="media-drawer-box">
                                <?php if (empty($media_files)): ?>
                                    <div style="color:#777; font-size:0.82rem;">No files found in <code>/uploads/files/</code>.</div>
                                <?php else: ?>
                                    <?php foreach ($media_files as $mf): ?>
                                        <div class="media-item-pill">
                                            <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width: 140px;" title="<?= htmlspecialchars($mf['name']) ?>">
                                                <?= htmlspecialchars($mf['name']) ?>
                                            </span>
                                            <div style="display:flex; gap:4px;">
                                                <?php if ($mf['is_img']): ?>
                                                    <button type="button" class="btn-secondary" style="font-size:0.75rem; padding: 2px 6px;" 
                                                            onclick="insertToEditor('<img src=\'<?= $mf['url'] ?>\' alt=\'\' style=\'max-width:100%; height:auto;\'>')">Img</button>
                                                <?php endif; ?>
                                                <button type="button" class="btn-secondary" style="font-size:0.75rem; padding: 2px 6px;" 
                                                        onclick="insertToEditor('<a href=\'<?= $mf['url'] ?>\' target=\'_blank\' class=\'btn-download\'><?= htmlspecialchars($mf['name']) ?></a>')">Link</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Action Buttons -->
                <div class="form-actions-card">
                    <button type="submit" name="submit_action" value="save_view" class="btn-primary" style="padding: 10px 22px;">
                        Save & View Page
                    </button>
                    <button type="submit" name="submit_action" value="save_admin" class="btn-secondary" style="padding: 10px 18px;">
                        Save & Return to Admin
                    </button>
                    <a href="<?= !empty($node['slug']) ? '/cms/' . htmlspecialchars($node['slug']) : '/cms/admin/nodes.php' ?>" class="btn-secondary" style="margin-left:auto;">
                        Cancel
                    </a>
                </div>
            </form>
        </main>
    </div>

    <!-- Trumbowyg Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Trumbowyg/2.27.3/trumbowyg.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Trumbowyg/2.27.3/plugins/cleanpaste/trumbowyg.cleanpaste.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Trumbowyg/2.27.3/plugins/table/trumbowyg.table.min.js"></script>

    <script>
        $(document).ready(function() {
            if ($('#node_body').length) {
                $('#node_body').trumbowyg({
                    btns: [
                        ['viewHTML'],
                        ['undo', 'redo'],
                        ['formatting'],
                        ['strong', 'em', 'underline', 'del'],
                        ['link'],
                        ['insertImage'],
                        ['justifyLeft', 'justifyCenter', 'justifyRight', 'justifyFull'],
                        ['unorderedList', 'orderedList'],
                        ['horizontalRule'],
                        ['table'],
                        ['removeformat'],
                        ['fullscreen']
                    ],
                    autogrow: true,
                    resetCss: false,
                    removeformatPasted: true,
                    semantic: true,
                    tagsToRemove: ['script', 'link', 'style']
                });
            }
        });

        function insertToEditor(htmlSnippet) {
            if ($('#node_body').data('trumbowyg')) {
                $('#node_body').trumbowyg('restoreRange');
                $('#node_body').trumbowyg('execCmd', {
                    cmd: 'insertHTML',
                    param: htmlSnippet,
                    forceCss: false
                });
            } else {
                const textarea = document.getElementById('node_body');
                if (textarea) {
                    const start = textarea.selectionStart;
                    const end = textarea.selectionEnd;
                    textarea.value = textarea.value.substring(0, start) + htmlSnippet + textarea.value.substring(end);
                }
            }
        }

        function toggleMenuFields(isChecked) {
            const box = document.getElementById('menu_details_box');
            if (box) {
                box.style.display = isChecked ? 'block' : 'none';
                const titleInput = document.getElementById('menu_link_title');
                const pageTitleInput = document.querySelector('input[name="title"]');
                if (isChecked && titleInput && !titleInput.value && pageTitleInput) {
                    titleInput.value = pageTitleInput.value;
                }
            }
        }
    </script>
</body>
</html>