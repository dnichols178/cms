<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function get_content_types(): array {
    $db = get_db();
    return $db->query('SELECT * FROM content_types ORDER BY name ASC')->fetchAll();
}

function get_content_type(string $type): ?array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM content_types WHERE type = :type LIMIT 1');
    $stmt->execute(['type' => $type]);
    $result = $stmt->fetch();
    if ($result) {
        $result['fields_schema'] = json_decode($result['fields_schema'] ?? '[]', true);
    }
    return $result ?: null;
}

function get_node_by_slug(string $slug, bool $allow_drafts = false): ?array {
    $db = get_db();
    $sql = '
        SELECT n.*, u.username AS author_name, ct.name AS type_name 
        FROM nodes n
        JOIN users u ON u.id = n.uid
        JOIN content_types ct ON ct.type = n.type
        WHERE n.slug = :slug
    ';
    if (!$allow_drafts) {
        $sql .= " AND n.status = 'published'";
    }
    $sql .= ' LIMIT 1';

    $stmt = $db->prepare($sql);
    $stmt->execute(['slug' => $slug]);
    $node = $stmt->fetch();
    if ($node) {
        $node['fields_data'] = json_decode($node['fields_data'] ?? '{}', true);
        $node['terms'] = get_node_terms((int)$node['id']);
    }
    return $node ?: null;
}

function get_node_by_id(int $id): ?array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT n.*, u.username AS author_name, ct.name AS type_name 
        FROM nodes n
        JOIN users u ON u.id = n.uid
        JOIN content_types ct ON ct.type = n.type
        WHERE n.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id]);
    $node = $stmt->fetch();
    if ($node) {
        $node['fields_data'] = json_decode($node['fields_data'] ?? '{}', true);
        $node['terms'] = get_node_terms((int)$node['id']);
    }
    return $node ?: null;
}

function get_node_terms(int $node_id): array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT t.id, t.name, t.slug 
        FROM taxonomy_terms t
        JOIN node_terms nt ON nt.term_id = t.id
        WHERE nt.node_id = :nid
        ORDER BY t.weight ASC, t.name ASC
    ');
    $stmt->execute(['nid' => $node_id]);
    return $stmt->fetchAll();
}

function save_node(array $data, ?int $id = null, array $term_ids = [], string $log_msg = ''): int {
    $db = get_db();
    $fields_json = json_encode($data['fields_data'] ?? []);
    $keywords = !empty($data['keywords']) ? trim($data['keywords']) : null;

    if ($id === null) {
        $stmt = $db->prepare('
            INSERT INTO nodes (type, title, slug, body, keywords, fields_data, uid, status, publish_on, unpublish_on, template)
            VALUES (:type, :title, :slug, :body, :keywords, :fields_data, :uid, :status, :publish_on, :unpublish_on, :template)
        ');
        $stmt->execute([
            'type'         => $data['type'],
            'title'        => $data['title'],
            'slug'         => $data['slug'],
            'body'         => $data['body'] ?? null,
            'keywords'     => $keywords,
            'fields_data'  => $fields_json,
            'uid'          => $data['uid'],
            'status'       => $data['status'] ?? 'published',
            'publish_on'   => !empty($data['publish_on']) ? $data['publish_on'] : null,
            'unpublish_on' => !empty($data['unpublish_on']) ? $data['unpublish_on'] : null,
            'template'     => $data['template'] ?? null
        ]);
        $saved_id = (int)$db->lastInsertId();
    } else {
        $stmt = $db->prepare('
            UPDATE nodes 
            SET title = :title, slug = :slug, body = :body, keywords = :keywords, fields_data = :fields_data, 
                status = :status, publish_on = :publish_on, unpublish_on = :unpublish_on, template = :template 
            WHERE id = :id
        ');
        $stmt->execute([
            'title'        => $data['title'],
            'slug'         => $data['slug'],
            'body'         => $data['body'] ?? null,
            'keywords'     => $keywords,
            'fields_data'  => $fields_json,
            'status'       => $data['status'] ?? 'published',
            'publish_on'   => !empty($data['publish_on']) ? $data['publish_on'] : null,
            'unpublish_on' => !empty($data['unpublish_on']) ? $data['unpublish_on'] : null,
            'template'     => $data['template'] ?? null,
            'id'           => $id
        ]);
        $saved_id = $id;
    }

    // Save snapshot revision with keywords
    $rev_stmt = $db->prepare('
        INSERT INTO node_revisions (node_id, title, body, keywords, fields_data, log_message, uid) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $rev_stmt->execute([
        $saved_id,
        $data['title'],
        $data['body'] ?? '',
        $keywords,
        $fields_json,
        $log_msg !== '' ? $log_msg : ($id === null ? 'Initial node creation' : 'Standard content update'),
        $data['uid']
    ]);

    // Synchronize taxonomy terms
    $del = $db->prepare('DELETE FROM node_terms WHERE node_id = :nid');
    $del->execute(['nid' => $saved_id]);

    if (!empty($term_ids)) {
        $ins = $db->prepare('INSERT INTO node_terms (node_id, term_id) VALUES (?, ?)');
        foreach ($term_ids as $tid) {
            $ins->execute([$saved_id, (int)$tid]);
        }
    }

    watchdog('content', "Node '{$data['title']}' saved (Type: {$data['type']}).", 'notice', "/cms/{$data['slug']}");
    require_once __DIR__ . '/cache.php';
    cache_clear(); // Automatically invalidate compiled caches on node edits

    // Moderation Workflow Email Alerts
    require_once __DIR__ . '/mailer.php';
    $host_domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $node_url = "http://{$host_domain}/cms/{$data['slug']}";
    $edit_url = "http://{$host_domain}/cms/admin/node_edit.php?id={$saved_id}";
    $node_title_safe = htmlspecialchars($data['title']);

    if (($data['status'] ?? '') === 'in_review') {
        $editor_email = get_site_setting('notify_workflow_email', 'editor@fourriversnuclearpartnership.com');
        $review_html = build_frnp_email_html(
            "Content Pending Editorial Review",
            "<p>A page or article has been submitted to <strong>In Review</strong> status and is awaiting editorial approval:</p>
             <p><strong>Title:</strong> {$node_title_safe}<br><strong>Type:</strong> " . htmlspecialchars($data['type']) . "</p>",
            $edit_url,
            "Open Node in Editor"
        );
        queue_email($editor_email, "Review Needed: {$data['title']}", $review_html, '', 'Editorial Team', 2);
    } elseif (($data['status'] ?? '') === 'published' && $id !== null) {
        // Look up author email
        $author_stmt = $db->prepare('SELECT email, username FROM users WHERE id = ?');
        $author_stmt->execute([$data['uid']]);
        $author = $author_stmt->fetch();
        if ($author && !empty($author['email'])) {
            $pub_html = build_frnp_email_html(
                "Content Published Live",
                "<p>Hello " . htmlspecialchars($author['username']) . ",</p>
                 <p>Your content submission <strong>{$node_title_safe}</strong> is now live on the public Four Rivers Nuclear Partnership portal.</p>",
                $node_url,
                "View Live Page"
            );
            queue_email($author['email'], "Published: {$data['title']}", $pub_html, '', $author['username'], 3);
        }
    }

    return $saved_id;
}

function get_all_taxonomy_terms(): array {
    $db = get_db();
    return $db->query('
        SELECT t.id, t.name, t.slug, v.name AS vocabulary_name
        FROM taxonomy_terms t
        JOIN taxonomy_vocabularies v ON v.id = t.vocabulary_id
        ORDER BY v.name ASC, t.weight ASC, t.name ASC
    ')->fetchAll();
}

function get_menu_items(string $menu_name = 'main'): array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT * FROM menu_items 
        WHERE menu_name = :m AND status = 1 
        ORDER BY weight ASC, id ASC
    ');
    $stmt->execute(['m' => $menu_name]);
    return $stmt->fetchAll();
}

function get_node_revisions(int $node_id): array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT r.*, u.username 
        FROM node_revisions r
        JOIN users u ON u.id = r.uid
        WHERE r.node_id = :nid
        ORDER BY r.created_at DESC
    ');
    $stmt->execute(['nid' => $node_id]);
    return $stmt->fetchAll();
}

function rollback_node_revision(int $revision_id, int $user_id): bool {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM node_revisions WHERE id = :rid LIMIT 1');
    $stmt->execute(['rid' => $revision_id]);
    $rev = $stmt->fetch();

    if (!$rev) {
        return false;
    }

    // Backup current state before rollback
    $node_stmt = $db->prepare('SELECT * FROM nodes WHERE id = :id');
    $node_stmt->execute(['id' => $rev['node_id']]);
    $current = $node_stmt->fetch();

    if ($current) {
        $log_stmt = $db->prepare('
            INSERT INTO node_revisions (node_id, title, body, keywords, fields_data, log_message, uid) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $log_stmt->execute([
            $current['id'],
            $current['title'],
            $current['body'],
            $current['keywords'] ?? null,
            $current['fields_data'],
            'Auto-backup prior to rollback to Rev #' . $revision_id,
            $user_id
        ]);
    }

    // Apply the revision
    $upd = $db->prepare('UPDATE nodes SET title = :t, body = :b, keywords = :k, fields_data = :f WHERE id = :id');
    return $upd->execute([
        't'  => $rev['title'],
        'b'  => $rev['body'],
        'k'  => $rev['keywords'] ?? null,
        'f'  => $rev['fields_data'],
        'id' => $rev['node_id']
    ]);
}
function get_site_setting(string $key, string $default = ''): string {
    static $settings = null;
    if ($settings === null) {
        $db = get_db();
        $rows = $db->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
        $settings = [];
        foreach ($rows as $r) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
    }
    return $settings[$key] ?? $default;
}

function set_site_setting(string $key, string $value): void {
    $db = get_db();
    $stmt = $db->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

function get_blocks_for_region(string $region, string $current_slug): array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT * FROM blocks 
        WHERE region = :reg AND status = 1 
        ORDER BY weight ASC, id ASC
    ');
    $stmt->execute(['reg' => $region]);
    $candidates = $stmt->fetchAll();

    $visible = [];
    foreach ($candidates as $b) {
        $rules = trim($b['visibility_pages'] ?? '');
        if ($rules === '') {
            $visible[] = $b;
            continue;
        }

        $slugs = array_map('trim', explode(',', $rules));
        if (in_array($current_slug, $slugs, true)) {
            $visible[] = $b;
        }
    }
    return $visible;
}

function render_region(string $region, string $current_slug): void {
    $blocks = get_blocks_for_region($region, $current_slug);
    foreach ($blocks as $b) {
        echo '<div class="sidebar-box block-widget" style="margin-bottom: 20px;">';
        if (!empty($b['title'])) {
            echo '<h3>' . htmlspecialchars($b['title']) . '</h3>';
        }
        echo '<div class="block-content">' . $b['body'] . '</div>';
        echo '</div>';
    }
}
function watchdog(string $type, string $message, string $severity = 'notice', ?string $link = null): void {
    try {
        $db = get_db();
        $uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $stmt = $db->prepare('
            INSERT INTO watchdog (uid, type, message, severity, link, ip_address) 
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$uid, $type, $message, $severity, $link, $ip]);
    } catch (\Throwable $e) {
        // Fail silently so logging never breaks primary page execution
        error_log("Watchdog failure: " . $e->getMessage());
    }
}
/**
 * Generates a clean, unique URL slug for a node.
 * Automatically appends -1, -2, etc. if a collision exists in the database.
 */
function generate_node_slug(string $title, string $type, ?string $custom_slug = null, ?int $current_id = null): string {
    $db = get_db();

    // 1. Sanitize base slug
    if ($custom_slug !== null && trim($custom_slug) !== '') {
        $base_slug = preg_replace('/[^a-z0-9_\-\/]/', '', strtolower(str_replace(' ', '-', trim($custom_slug))));
        $base_slug = trim(preg_replace('/-+/', '-', $base_slug), '-/');
    } else {
        $clean = preg_replace('/[^a-z0-9\s-]/', '', strtolower($title));
        $base_slug = preg_replace('/[\s-]+/', '-', trim($clean));
        if ($base_slug === '') {
            $base_slug = 'node-' . bin2hex(random_bytes(3));
        }

        // Apply pathauto prefix for news/articles
        switch ($type) {
            case 'article':
            case 'news':
            case 'news_updates':
                $base_slug = sprintf('news/%s/%s', date('Y'), $base_slug);
                break;
            case 'page':
            default:
                break;
        }
    }

    // 2. Check collision in database and auto-increment suffix if taken
    $slug = $base_slug;
    $counter = 1;

    while (true) {
        $sql = 'SELECT COUNT(*) FROM nodes WHERE slug = :slug';
        $params = ['slug' => $slug];

        if ($current_id !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $current_id;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        if ((int)$stmt->fetchColumn() === 0) {
            break; // Unique slug confirmed
        }

        $slug = $base_slug . '-' . $counter;
        $counter++;
    }

    return $slug;
}
/**
 * Renders custom fields matching a designated template position ('above_body', 'below_body', 'header_meta', 'sidebar', or 'all').
 */
function render_custom_fields(array $node, string $position = 'above_body'): string {
    // If the node has no content type (e.g., custom pages, views, or error pages), skip
    if (empty($node['type'])) {
        return '';
    }

    $content_type = get_content_type($node['type']);
    if (!$content_type || empty($content_type['fields_schema'])) {
        return '';
    }
    // ... rest of the function remains the same

    $fields_data = $node['fields_data'] ?? [];
    $schema = $content_type['fields_schema'];

    // Ensure fields are sorted by weight
    usort($schema, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));

    $html = '';
    $rendered_count = 0;

    foreach ($schema as $f) {
        $fname = $f['name'];
        $field_placement = $f['placement'] ?? 'above_body';

        // Check if field is configured to render in the requested template position
        if ($position !== 'all' && $field_placement !== $position) {
            continue;
        }

        // If field placement is set to hidden, skip rendering
        if ($field_placement === 'hidden') {
            continue;
        }

        // Check if value is populated
        if (!isset($fields_data[$fname]) || $fields_data[$fname] === '' || $fields_data[$fname] === null) {
            continue;
        }

        $raw_val = $fields_data[$fname];
        $formatted_val = format_field_value($f, $raw_val);
        if ($formatted_val === '') {
            continue;
        }

        $rendered_count++;
        $disp = $f['label_display'] ?? 'inline';
        $label_safe = htmlspecialchars($f['label']);

        // Wrap differently based on template region
        if ($position === 'sidebar') {
            $html .= '<div class="sidebar-box custom-field-box" style="margin-bottom: 20px;">';
            if ($disp !== 'hidden') {
                $html .= '<h3 style="color:var(--frnp-navy); font-size:1.05rem; margin-bottom:12px;">' . $label_safe . '</h3>';
            }
            $html .= '<div class="field-value">' . $formatted_val . '</div>';
            $html .= '</div>';
        } elseif ($position === 'header_meta') {
            $html .= '<div class="meta-field-item meta-field-' . htmlspecialchars($fname) . '" style="display:inline-block; margin-right:15px; margin-top:8px; font-size:0.88rem;">';
            if ($disp !== 'hidden') {
                $html .= '<strong style="color:var(--frnp-navy);">' . $label_safe . ':</strong> ';
            }
            $html .= '<span class="field-value">' . $formatted_val . '</span>';
            $html .= '</div>';
        } else {
            // Standard in-content placements (above_body or below_body)
            $html .= '<div class="field-item field-item-' . htmlspecialchars($fname) . '" style="margin-bottom: 16px;">';
            if ($disp === 'hidden') {
                $html .= '<div class="field-value">' . $formatted_val . '</div>';
            } elseif ($disp === 'above') {
                $html .= '<div class="field-label" style="font-weight: 700; color: var(--frnp-navy); margin-bottom: 4px; font-size: 0.95rem;">' . $label_safe . '</div>';
                $html .= '<div class="field-value">' . $formatted_val . '</div>';
            } else {
                $html .= '<strong style="color: var(--frnp-navy);">' . $label_safe . ':</strong> ';
                $html .= '<span class="field-value">' . $formatted_val . '</span>';
            }
            $html .= '</div>';
        }
    }

    if ($rendered_count === 0) {
        return '';
    }

    if ($position === 'above_body') {
        return '<div class="node-fields-top" style="margin-bottom: 24px;">' . $html . '</div>';
    } elseif ($position === 'below_body') {
        return '<div class="node-fields-bottom" style="margin-top: 28px; padding-top: 18px; border-top: 1px solid var(--frnp-border);">' . $html . '</div>';
    }

    return $html;
}

/**
 * Render an individual field by machine name anywhere in custom templates.
 */
function render_field(array $node, string $field_name): string {
    $content_type = get_content_type($node['type']);
    if (!$content_type || empty($content_type['fields_schema'])) {
        return '';
    }

    $fields_data = $node['fields_data'] ?? [];
    if (!isset($fields_data[$field_name]) || $fields_data[$field_name] === '') {
        return '';
    }

    foreach ($content_type['fields_schema'] as $f) {
        if ($f['name'] === $field_name) {
            $formatted_val = format_field_value($f, $fields_data[$field_name]);
            $disp = $f['label_display'] ?? 'inline';
            $label_safe = htmlspecialchars($f['label']);

            if ($disp === 'hidden') {
                return '<div class="field-value">' . $formatted_val . '</div>';
            } elseif ($disp === 'above') {
                return '<div class="field-label" style="font-weight:700; color:var(--frnp-navy);">' . $label_safe . '</div><div class="field-value">' . $formatted_val . '</div>';
            } else {
                return '<strong>' . $label_safe . ':</strong> ' . $formatted_val;
            }
        }
    }
    return '';
}

/**
 * Helper to format raw field values into HTML (downloads, images, dates, select labels).
 */
function format_field_value(array $field, mixed $val): string {
    $type = $field['type'];

    switch ($type) {
        case 'image':
            return '<img src="' . htmlspecialchars((string)$val) . '" alt="" style="max-width: 100%; height: auto; border-radius: 4px; border: 1px solid #e2e8f0; margin-top: 5px;">';

        case 'file':
            $filename = basename((string)$val);
            return '<a href="' . htmlspecialchars((string)$val) . '" target="_blank" class="btn-download">' . htmlspecialchars($filename) . '</a>';

        case 'date':
            return '<time>' . date('F j, Y', strtotime((string)$val)) . '</time>';

        case 'select':
            $opts = $field['options'] ?? [];
            $display_text = $opts[$val] ?? $val;
            return htmlspecialchars((string)$display_text);

        case 'boolean':
            return !empty($val) ? '<span class="badge" style="background:#2e7d32;">Yes</span>' : '<span class="badge" style="background:#64748b;">No</span>';

        case 'textarea':
            return nl2br(htmlspecialchars((string)$val));

        case 'text':
        case 'number':
        default:
            return htmlspecialchars((string)$val);
    }
}

/**
 * Retrieve the active menu link for a specific node if one exists.
 */
/**
 * Retrieve menu items organized as a hierarchical parent/children tree.
 */
function get_menu_tree(string $menu_name = 'main'): array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM menu_items WHERE menu_name = :m ORDER BY weight ASC, id ASC');
    $stmt->execute(['m' => $menu_name]);
    $items = $stmt->fetchAll();

    $tree = [];
    $lookup = [];

    foreach ($items as $item) {
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

    return $tree;
}

/**
 * Get all top-level menu items suitable to serve as parents for submenus.
 */
function get_top_level_menu_items(string $menu_name = 'main', ?int $exclude_node_id = null): array {
    $db = get_db();

    // Auto-migrate parent_id column if missing from legacy menu_items tables
    try {
        $check = $db->query("SHOW COLUMNS FROM menu_items LIKE 'parent_id'")->fetch();
        if (!$check) {
            $db->exec("ALTER TABLE menu_items ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL AFTER weight, ADD INDEX (parent_id)");
        }
    } catch (\Throwable $e) {
        // Suppress if already exists or permissions restricted
    }

    $sql = 'SELECT id, title FROM menu_items WHERE menu_name = :m AND (parent_id IS NULL OR parent_id = 0)';
    $params = ['m' => $menu_name];

    if ($exclude_node_id !== null) {
        $sql .= ' AND (node_id IS NULL OR node_id != :ex_nid)';
        $params['ex_nid'] = $exclude_node_id;
    }

    $sql .= ' ORDER BY weight ASC, title ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Retrieve the active menu link for a specific node if one exists.
 */
function get_node_menu_link(int $node_id, string $menu_name = 'main'): ?array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM menu_items WHERE node_id = :nid AND menu_name = :m LIMIT 1');
    $stmt->execute(['nid' => $node_id, 'm' => $menu_name]);
    $link = $stmt->fetch();
    return $link ?: null;
}

/**
 * Synchronize a node's presence in the menu including parent/child hierarchy.
 */
function save_node_menu_link(int $node_id, string $slug, string $title, bool $enabled, ?int $parent_id = null, int $weight = 0, string $menu_name = 'main'): void {
    $db = get_db();
    $existing = get_node_menu_link($node_id, $menu_name);
    $url = '/cms/' . ltrim($slug, '/');

    if ($enabled) {
        $link_title = trim($title) !== '' ? trim($title) : 'Page';
        $p_id = ($parent_id && $parent_id > 0) ? $parent_id : null;

        if ($existing) {
            $stmt = $db->prepare('UPDATE menu_items SET title = :t, url = :u, parent_id = :pid, weight = :w WHERE id = :id');
            $stmt->execute([
                't'   => $link_title,
                'u'   => $url,
                'pid' => $p_id,
                'w'   => $weight,
                'id'  => $existing['id']
            ]);
        } else {
            $stmt = $db->prepare('
                INSERT INTO menu_items (menu_name, node_id, title, url, parent_id, weight)
                VALUES (:m, :nid, :t, :u, :pid, :w)
            ');
            $stmt->execute([
                'm'   => $menu_name,
                'nid' => $node_id,
                't'   => $link_title,
                'u'   => $url,
                'pid' => $p_id,
                'w'   => $weight
            ]);
        }
    } else {
        if ($existing) {
            // Reparent any orphaned children to root before deleting
            $reparent = $db->prepare('UPDATE menu_items SET parent_id = NULL WHERE parent_id = ?');
            $reparent->execute([$existing['id']]);

            $stmt = $db->prepare('DELETE FROM menu_items WHERE id = ?');
            $stmt->execute([$existing['id']]);
        } else {
            $stmt = $db->prepare('DELETE FROM menu_items WHERE menu_name = :m AND url = :u');
            $stmt->execute(['m' => $menu_name, 'u' => $url]);
        }
    }

    require_once __DIR__ . '/cache.php';
    cache_clear();
}