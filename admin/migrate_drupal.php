<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_permission('administer_site');

ini_set('memory_limit', '512M');
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$log_buffer = [];
$migration_ran = false;
$error_message = '';

function log_msg(string $msg, string $type = 'info'): void {
    global $log_buffer;
    $log_buffer[] = [
        'time' => date('H:i:s'),
        'type' => $type,
        'text' => $msg
    ];
}

// Helper: Stream large .sql file to database in chunks without crashing memory
function execute_sql_dump(PDO $pdo, string $filepath): void {
    $fp = fopen($filepath, 'r');
    if (!$fp) {
        throw new RuntimeException("Cannot open uploaded SQL dump file for reading.");
    }

    $query = '';
    while (!feof($fp)) {
        $line = fgets($fp);
        if ($line === false) break;

        $trimmed = trim($line);
        // Skip comment lines and empty lines
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
            continue;
        }

        $query .= $line;
        // Check for statement termination
        if (str_ends_with($trimmed, ';')) {
            $pdo->exec($query);
            $query = '';
        }
    }
    fclose($fp);
}

// Process Upload & Migration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_migration') {
    validate_csrf();
    $migration_ran = true;

    $file = $_FILES['sql_file'] ?? null;
    $table_prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['table_prefix'] ?? '');
    $default_pass = trim($_POST['default_password'] ?? 'ChangeMe@2026!');
    $staging_dbname = 'drupal_staging_' . bin2hex(random_bytes(3));
    $cleanup_staging = isset($_POST['cleanup_staging']);

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $error_message = "File upload failed or no file selected (Upload error code: " . ($file['error'] ?? 'None') . ").";
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'sql') {
        $error_message = "Invalid file extension. Please upload a plain .sql database dump file.";
    } else {
        try {
            $target_db = get_db();

            // 1. Create Staging Database for the uploaded dump
            log_msg("Step 1: Creating temporary staging database `{$staging_dbname}`...", 'info');
            $target_db->exec("CREATE DATABASE IF NOT EXISTS `{$staging_dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");

            // Connect to staging database
            $staging_dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, $staging_dbname);
            $staging_db = new PDO($staging_dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // 2. Load .SQL Dump into Staging DB
            log_msg("Step 2: Parsing and importing " . htmlspecialchars($file['name']) . " into staging...", 'info');
            $staging_db->exec("SET FOREIGN_KEY_CHECKS=0;");
            execute_sql_dump($staging_db, $file['tmp_name']);
            log_msg("SQL dump successfully restored into staging schema.", 'info');

            // Disable target foreign keys during transfer
            $target_db->exec("SET FOREIGN_KEY_CHECKS=0;");

            // 3. Migrate Users
            log_msg("Step 3: Migrating user accounts...", 'info');
            $d_users_stmt = $staging_db->query("
                SELECT uid, name, mail, status, created 
                FROM `{$table_prefix}users` 
                WHERE uid > 0 AND mail IS NOT NULL AND mail != ''
            ");
            $d_users = $d_users_stmt->fetchAll();

            $ins_user = $target_db->prepare("
                INSERT INTO users (id, username, email, password_hash, status, created_at)
                VALUES (:id, :username, :email, :pass, :status, :created_at)
                ON DUPLICATE KEY UPDATE 
                    username = VALUES(username),
                    email = VALUES(email),
                    status = VALUES(status)
            ");
            $user_role_ins = $target_db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:uid, :rid)");
            $default_hash = password_hash($default_pass, PASSWORD_DEFAULT);
            $user_count = 0;

            foreach ($d_users as $u) {
                $ins_user->execute([
                    'id'         => (int)$u['uid'],
                    'username'   => $u['name'],
                    'email'      => $u['mail'],
                    'pass'       => $default_hash,
                    'status'     => (int)$u['status'],
                    'created_at' => date('Y-m-d H:i:s', (int)$u['created'])
                ]);
                $role_id = ((int)$u['uid'] === 1) ? 1 : 2;
                $user_role_ins->execute(['uid' => (int)$u['uid'], 'rid' => $role_id]);
                $user_count++;
            }
            log_msg("Imported {$user_count} user accounts.", 'info');

            // 4. Migrate Content Types
            log_msg("Step 4: Synchronizing content types...", 'info');
            $types_count = 0;
            if ($staging_db->query("SHOW TABLES LIKE '{$table_prefix}node_type'")->rowCount() > 0) {
                $d_types = $staging_db->query("SELECT type, name, description FROM `{$table_prefix}node_type`")->fetchAll();
                $ins_type = $target_db->prepare("
                    INSERT INTO content_types (type, name, description, has_body, fields_schema)
                    VALUES (:type, :name, :description, 1, :fields_schema)
                    ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)
                ");

                foreach ($d_types as $t) {
                    $schema = ($t['type'] === 'article') ? [
                        ['name' => 'subtitle', 'label' => 'Subtitle / Deck', 'type' => 'text'],
                        ['name' => 'publication_date', 'label' => 'Original Release Date', 'type' => 'date']
                    ] : [];

                    $ins_type->execute([
                        'type'          => $t['type'],
                        'name'          => $t['name'],
                        'description'   => $t['description'] ?? '',
                        'fields_schema' => json_encode($schema)
                    ]);
                    $types_count++;
                }
            }
            log_msg("Synchronized {$types_count} content types.", 'info');

            // 5. Migrate Taxonomy
            log_msg("Step 5: Migrating taxonomy vocabularies & terms...", 'info');
            if ($staging_db->query("SHOW TABLES LIKE '{$table_prefix}taxonomy_vocabulary'")->rowCount() > 0) {
                $d_vocabs = $staging_db->query("SELECT vid, name, machine_name, description FROM `{$table_prefix}taxonomy_vocabulary`")->fetchAll();
                $ins_voc = $target_db->prepare("
                    INSERT INTO taxonomy_vocabularies (id, name, machine_name, description)
                    VALUES (:id, :name, :machine_name, :description)
                    ON DUPLICATE KEY UPDATE name = VALUES(name), machine_name = VALUES(machine_name)
                ");
                foreach ($d_vocabs as $v) {
                    $ins_voc->execute([
                        'id'           => (int)$v['vid'],
                        'name'         => $v['name'],
                        'machine_name' => $v['machine_name'],
                        'description'  => $v['description'] ?? ''
                    ]);
                }
            }

            $term_count = 0;
            if ($staging_db->query("SHOW TABLES LIKE '{$table_prefix}taxonomy_term_data'")->rowCount() > 0) {
                $d_terms = $staging_db->query("SELECT tid, vid, name, description, weight FROM `{$table_prefix}taxonomy_term_data`")->fetchAll();
                $ins_term = $target_db->prepare("
                    INSERT INTO taxonomy_terms (id, vocabulary_id, name, slug, weight, description)
                    VALUES (:id, :vid, :name, :slug, :weight, :description)
                    ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug), weight = VALUES(weight)
                ");
                foreach ($d_terms as $tm) {
                    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $tm['name']), '-'));
                    $ins_term->execute([
                        'id'          => (int)$tm['tid'],
                        'vid'         => (int)$tm['vid'],
                        'name'        => $tm['name'],
                        'slug'        => $slug !== '' ? $slug : ('term-' . $tm['tid']),
                        'weight'      => (int)$tm['weight'],
                        'description' => $tm['description'] ?? ''
                    ]);
                    $term_count++;
                }
            }
            log_msg("Migrated {$term_count} taxonomy terms.", 'info');

            // 6. Migrate Nodes & URL Aliases
            log_msg("Step 6: Migrating nodes and URL aliases...", 'info');
            $aliases = [];
            if ($staging_db->query("SHOW TABLES LIKE '{$table_prefix}url_alias'")->rowCount() > 0) {
                $alias_stmt = $staging_db->query("SELECT source, alias FROM `{$table_prefix}url_alias` WHERE source LIKE 'node/%'");
                while ($ar = $alias_stmt->fetch()) {
                    $aliases[$ar['source']] = trim($ar['alias'], '/');
                }
            }

            $node_stmt = $staging_db->query("
                SELECT n.nid, n.type, n.title, n.uid, n.status, n.created, n.changed, b.body_value
                FROM `{$table_prefix}node` n
                LEFT JOIN `{$table_prefix}field_data_body` b 
                    ON b.entity_id = n.nid AND b.entity_type = 'node' AND b.deleted = 0
                ORDER BY n.nid ASC
            ");

            $ins_node = $target_db->prepare("
                INSERT INTO nodes (id, type, title, slug, body, fields_data, uid, status, created_at, updated_at)
                VALUES (:id, :type, :title, :slug, :body, :fields_data, :uid, :status, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE
                    title       = VALUES(title),
                    slug        = VALUES(slug),
                    body        = VALUES(body),
                    fields_data = VALUES(fields_data),
                    status      = VALUES(status),
                    updated_at  = VALUES(updated_at)
            ");

            $existing_slugs = [];
            $node_count = 0;

            while ($n = $node_stmt->fetch()) {
                $nid = (int)$n['nid'];
                $src = 'node/' . $nid;

                if (!empty($aliases[$src])) {
                    $slug = strtolower($aliases[$src]);
                } else {
                    $clean = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $n['title']), '-'));
                    $slug = ($n['type'] === 'article') ? 'news/' . date('Y', (int)$n['created']) . '/' . $clean : $clean;
                }

                if (isset($existing_slugs[$slug])) {
                    $slug .= '-' . $nid;
                }
                $existing_slugs[$slug] = true;

                $fields_data = [];
                if ($n['type'] === 'article') {
                    $fields_data['publication_date'] = date('Y-m-d', (int)$n['created']);
                    $fields_data['subtitle'] = '';
                }

                // Rewrite Drupal image/file paths to uploads/files/
                $body = str_replace('sites/default/files/', 'uploads/files/', $n['body_value'] ?? '');

                $ins_node->execute([
                    'id'          => $nid,
                    'type'        => $n['type'],
                    'title'       => $n['title'],
                    'slug'        => $slug,
                    'body'        => $body,
                    'fields_data' => json_encode($fields_data),
                    'uid'         => (int)$n['uid'] > 0 ? (int)$n['uid'] : 1,
                    'status'      => ((int)$n['status'] === 1) ? 'published' : 'draft',
                    'created_at'  => date('Y-m-d H:i:s', (int)$n['created']),
                    'updated_at'  => date('Y-m-d H:i:s', (int)$n['changed']),
                ]);
                $node_count++;
            }
            log_msg("Transferred {$node_count} content nodes.", 'info');

            // 7. Node-to-term associations
            if ($staging_db->query("SHOW TABLES LIKE '{$table_prefix}taxonomy_index'")->rowCount() > 0) {
                log_msg("Step 7: Mapping node-to-taxonomy assignments...", 'info');
                $d_index = $staging_db->query("SELECT nid, tid FROM `{$table_prefix}taxonomy_index`")->fetchAll();
                $ins_nt = $target_db->prepare("INSERT IGNORE INTO node_terms (node_id, term_id) VALUES (?, ?)");
                foreach ($d_index as $row) {
                    $ins_nt->execute([(int)$row['nid'], (int)$row['tid']]);
                }
            }

            // 8. Cleanup Temporary Staging DB
            if ($cleanup_staging) {
                log_msg("Step 8: Dropping staging database `{$staging_dbname}`...", 'info');
                $target_db->exec("DROP DATABASE IF EXISTS `{$staging_dbname}`;");
                log_msg("Staging database cleaned up.", 'info');
            }

            log_msg("Migration completed successfully! All content is now available in FRNP CMS.", 'success');
            
            // Invalidate system cache
            require_once __DIR__ . '/../includes/cache.php';
            cache_clear();

        } catch (Throwable $e) {
            $error_message = $e->getMessage();
            log_msg("Fatal Error: " . $e->getMessage(), 'error');
        } finally {
            if (isset($target_db)) {
                $target_db->exec("SET FOREIGN_KEY_CHECKS=1;");
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Drupal SQL Migration - FRNP CMS</title>
    <link rel="stylesheet" href="/cms/assets/css/admin.css">
    <style>
        .console-output {
            background: #1e293b;
            color: #f8fafc;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.85rem;
            padding: 20px;
            border-radius: 4px;
            max-height: 420px;
            overflow-y: auto;
            margin-top: 20px;
            line-height: 1.6;
        }
        .log-time { color: #94a3b8; margin-right: 10px; }
        .log-info { color: #38bdf8; }
        .log-success { color: #4ade80; font-weight: bold; }
        .log-error { color: #f87171; font-weight: bold; }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>
        <main class="admin-main" style="max-width: 900px;">
            <h1>Maintenance &raquo; Drupal Database Import</h1>

            <?php if ($error_message): ?>
                <div class="notice" style="background:#fee; border-color:#c00; color:#c00;">
                    <strong>Error:</strong> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <section class="card">
                <h3>Upload Drupal SQL Database Dump</h3>
                <p style="margin-bottom: 20px; color:#555; font-size:0.95rem; line-height: 1.5;">
                    Upload an uncompressed <code>.sql</code> database export from your Drupal 7 installation. The importer extracts users, content types, nodes, revision text, taxonomy vocabularies, and converts Drupal URL aliases into native slugs[cite: 1].
                </p>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="run_migration">

                    <div class="form-row">
                        <label>Select Drupal .SQL File *</label>
                        <input type="file" name="sql_file" accept=".sql" required style="padding: 8px; border: 1px dashed #cbd5e1; width: 100%; background: #f8fafc;">
                        <small style="color: #64748b;">Ensure <code>upload_max_filesize</code> and <code>post_max_size</code> in <code>php.ini</code> accommodate your file size.</small>
                    </div>

                    <div class="form-row" style="display: flex; gap: 20px;">
                        <div style="flex: 1;">
                            <label>Table Prefix (if any)</label>
                            <input type="text" name="table_prefix" placeholder="e.g. d7_ or leave blank">
                        </div>
                        <div style="flex: 1;">
                            <label>Default Password for Migrated Users</label>
                            <input type="text" name="default_password" value="ChangeMe@2026!">
                        </div>
                    </div>

                    <div class="form-row">
                        <label>
                            <input type="checkbox" name="cleanup_staging" value="1" checked>
                            Automatically drop temporary staging database after migration
                        </label>
                    </div>

                    <button type="submit" class="btn-primary" style="padding: 10px 24px; font-size: 0.95rem;">
                        Upload & Run Migration
                    </button>
                </form>
            </section>

            <?php if ($migration_ran): ?>
                <section class="card">
                    <h3>Migration Output Log</h3>
                    <div class="console-output">
                        <?php foreach ($log_buffer as $entry): ?>
                            <div>
                                <span class="log-time">[<?= $entry['time'] ?>]</span>
                                <span class="log-<?= $entry['type'] ?>"><?= htmlspecialchars($entry['text']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top: 15px;">
                        <a href="/cms/admin/nodes.php" class="btn-primary">View Imported Content</a>
                        <a href="/cms/admin/users.php" class="btn-secondary" style="margin-left: 10px;">Review Migrated Users</a>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>