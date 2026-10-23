<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/template.php';
require_once __DIR__ . '/includes/auth.php';

$path = trim($_GET['q'] ?? '', '/');

if ($path === '' || $path === 'index.php') {
    $path = 'home';
}

$db = get_db();

// 1. URL Redirects Interception (Drupal Redirect Engine)
$r_stmt = $db->prepare('SELECT target_path, status_code FROM url_redirects WHERE source_path = :src LIMIT 1');
$r_stmt->execute(['src' => $path]);
$redir = $r_stmt->fetch();

if ($redir) {
    http_response_code((int)$redir['status_code']);
    header('Location: ' . $redir['target_path']);
    exit;
}

// 2. Search Engine XML Sitemap (/sitemap.xml)
if ($path === 'sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    $nodes = $db->query('SELECT slug, updated_at FROM nodes WHERE status = 1 ORDER BY updated_at DESC')->fetchAll();
    
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo '<url><loc>http://' . $_SERVER['HTTP_HOST'] . '/cms/home</loc><changefreq>weekly</changefreq><priority>1.0</priority></url>';
    echo '<url><loc>http://' . $_SERVER['HTTP_HOST'] . '/cms/news</loc><changefreq>daily</changefreq><priority>0.8</priority></url>';
    echo '<url><loc>http://' . $_SERVER['HTTP_HOST'] . '/cms/contact</loc><changefreq>monthly</changefreq><priority>0.5</priority></url>';

    foreach ($nodes as $n) {
        if ($n['slug'] === 'home') continue;
        $loc = 'http://' . $_SERVER['HTTP_HOST'] . '/cms/' . htmlspecialchars($n['slug']);
        $lastmod = date('c', strtotime($n['updated_at']));
        echo "<url><loc>{$loc}</loc><lastmod>{$lastmod}</lastmod><priority>0.7</priority></url>";
    }
    echo '</urlset>';
    exit;
}

// 3. Contact Us Route (/contact) with Anti-Spam (Honeypot + Time-Gate)
if ($path === 'contact') {
    $contact_success = false;
    $contact_error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $c_name      = trim($_POST['name'] ?? '');
        $c_email     = trim($_POST['email'] ?? '');
        $c_subject   = trim($_POST['subject'] ?? '');
        $c_message   = trim($_POST['message'] ?? '');
        $c_ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $honeypot    = trim($_POST['frnp_hp_company'] ?? '');
        $submit_time = (int)($_POST['form_token_time'] ?? 0);

        // Anti-Spam Check 1: Hidden Honeypot trap
        if ($honeypot !== '') {
            watchdog('spam', "Spam bot caught via honeypot trap from IP: {$c_ip}", 'warning');
            $contact_success = true; // Silent success to discard bot submission
            $_POST = [];
        }
        // Anti-Spam Check 2: Speed-gate (submission under 3 seconds indicates automated script)
        elseif ($submit_time === 0 || (time() - $submit_time) < 3) {
            $contact_error = 'Submission was completed too quickly. Please take a moment and re-submit.';
        }
        // Standard Field Validation
        elseif ($c_name === '' || $c_email === '' || $c_subject === '' || $c_message === '') {
            $contact_error = 'All fields are mandatory.';
        } elseif (!filter_var($c_email, FILTER_VALIDATE_EMAIL)) {
            $contact_error = 'Please enter a valid email address.';
        } else {
            $sub_stmt = $db->prepare('
                INSERT INTO contact_submissions (name, email, subject, message, ip_address) 
                VALUES (?, ?, ?, ?, ?)
            ');
            $sub_stmt->execute([$c_name, $c_email, $c_subject, $c_message, $c_ip]);
            // Queue Notification to Site Communications
            require_once __DIR__ . '/includes/mailer.php';
            $notify_to = get_site_setting('notify_contact_email', 'communications@fourriversnuclearpartnership.com');
            $inquiry_subject = "Portal Inquiry: " . $c_subject;
            
            $msg_body = "
                <p><strong>A new public inquiry has been submitted via the FRNP site portal.</strong></p>
                <table style='width:100%; border-collapse:collapse; margin:15px 0;'>
                    <tr><td style='padding:6px; font-weight:bold; width:120px; border-bottom:1px solid #eee;'>Sender:</td><td style='padding:6px; border-bottom:1px solid #eee;'>" . htmlspecialchars($c_name) . "</td></tr>
                    <tr><td style='padding:6px; font-weight:bold; border-bottom:1px solid #eee;'>Email:</td><td style='padding:6px; border-bottom:1px solid #eee;'><a href='mailto:" . htmlspecialchars($c_email) . "'>" . htmlspecialchars($c_email) . "</a></td></tr>
                    <tr><td style='padding:6px; font-weight:bold; border-bottom:1px solid #eee;'>Subject:</td><td style='padding:6px; border-bottom:1px solid #eee;'>" . htmlspecialchars($c_subject) . "</td></tr>
                    <tr><td style='padding:6px; font-weight:bold; border-bottom:1px solid #eee;'>IP Address:</td><td style='padding:6px; border-bottom:1px solid #eee;'>" . htmlspecialchars($c_ip) . "</td></tr>
                </table>
                <p><strong>Message Content:</strong></p>
                <div style='background:#f8fafc; border-left:4px solid #0b2545; padding:12px; margin:10px 0; font-size:14px;'>
                    " . nl2br(htmlspecialchars($c_message)) . "
                </div>
            ";

            $html_alert = build_frnp_email_html(
                "New Inbound Contact Submission",
                $msg_body,
                "http://" . $_SERVER['HTTP_HOST'] . "/cms/admin/submissions.php",
                "View In Submissions Manager"
            );

            queue_email($notify_to, $inquiry_subject, $html_alert, '', 'FRNP Communications', 1);
            watchdog('content', "New contact inquiry received from {$c_name} ({$c_email})", 'info');
            $contact_success = true;
            $_POST = []; // Clear form fields
        }
    }

    ob_start();
    include __DIR__ . '/templates/view--contact.php';
    $content = ob_get_clean();

    $page_title = 'Contact | Four Rivers Nuclear Partnership';
    $node = ['title' => 'Contact Us', 'slug' => 'contact'];
    include __DIR__ . '/templates/layout.php';
    exit;
}

// 4. Dynamic Route: Taxonomy Term Archives (/term/[slug])
if (str_starts_with($path, 'term/')) {
    $term_slug = substr($path, 5);
    $t_stmt = $db->prepare('SELECT id, name, description FROM taxonomy_terms WHERE slug = :slug LIMIT 1');
    $t_stmt->execute(['slug' => $term_slug]);
    $term = $t_stmt->fetch();

    if ($term) {
        $stmt = $db->prepare("
            SELECT n.id, n.title, n.slug, n.created_at, n.type, ct.name AS type_name,
                   SUBSTRING(REGEXP_REPLACE(COALESCE(n.body, ''), '<[^>]+>', ''), 1, 240) AS teaser
            FROM nodes n
            JOIN node_terms nt ON nt.node_id = n.id
            JOIN content_types ct ON ct.type = n.type
            WHERE nt.term_id = :tid AND n.status = 1
            ORDER BY n.created_at DESC
        ");
        $stmt->execute(['tid' => $term['id']]);
        $tagged_nodes = $stmt->fetchAll();

        ob_start();
        include __DIR__ . '/templates/view--term.php';
        $content = ob_get_clean();

        $page_title = htmlspecialchars($term['name']) . ' | Four Rivers Nuclear Partnership';
        $node = ['title' => 'Topic: ' . $term['name'], 'slug' => 'term/' . $term_slug];
        include __DIR__ . '/templates/layout.php';
        exit;
    }
}

// 5. Dynamic Route: News Archive (/news)
if ($path === 'news') {
    // Matches 'article', 'news', 'news_updates', or any content type with "News" in the title
    $stmt = $db->query("
        SELECT n.id, n.title, n.slug, n.created_at, n.fields_data,
               SUBSTRING(REGEXP_REPLACE(COALESCE(n.body, ''), '<[^>]+>', ''), 1, 260) AS teaser
        FROM nodes n
        JOIN content_types ct ON ct.type = n.type
        WHERE (n.type IN ('article', 'news', 'news_updates') OR ct.name LIKE '%News%' OR ct.name LIKE '%Article%')
          AND (n.status = 'published' OR n.status = '1')
        ORDER BY n.created_at DESC
    ");
    $articles = $stmt->fetchAll();

    foreach ($articles as &$item) {
        $item['fields_data'] = json_decode($item['fields_data'] ?? '{}', true);
    }
    unset($item);

    ob_start();
    include __DIR__ . '/templates/view--articles.php';
    $content = ob_get_clean();

    $page_title = 'News & Releases | Four Rivers Nuclear Partnership';
    $node = ['title' => 'News & Press Releases', 'slug' => 'news', 'type' => 'view'];
    include __DIR__ . '/templates/layout.php';
    exit;
}
// 6. Search Route (/search) with Full-Text Relevance
// Dynamic Route: Site Search (/search)
if ($path === 'search') {
    $keyword = trim($_GET['keyword'] ?? $_GET['q'] ?? '');
    $results = [];

    if ($keyword !== '') {
        $search_term = '%' . $keyword . '%';

        // Use distinct placeholders or positional parameters (?) for each column
        $sql = "
            SELECT n.id, n.type, n.title, n.slug, n.created_at, n.fields_data,
                   SUBSTRING(REGEXP_REPLACE(COALESCE(n.body, ''), '<[^>]+>', ''), 1, 260) AS teaser,
                   CASE 
                       WHEN n.keywords LIKE ? THEN 3
                       WHEN n.title LIKE ? THEN 2
                       ELSE 1
                   END AS relevance
            FROM nodes n
            WHERE (n.title LIKE ? OR n.body LIKE ? OR n.keywords LIKE ?)
              AND (n.status = 'published' OR n.status = '1')
            ORDER BY relevance DESC, n.created_at DESC
            LIMIT 50
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $search_term, // relevance CASE keywords
            $search_term, // relevance CASE title
            $search_term, // WHERE title
            $search_term, // WHERE body
            $search_term  // WHERE keywords
        ]);
        $results = $stmt->fetchAll();

        foreach ($results as &$r) {
            $r['fields_data'] = json_decode($r['fields_data'] ?? '{}', true);
            $r['terms'] = get_node_terms((int)$r['id']);
        }
        unset($r);
    }

    $page_title = 'Search Results for "' . htmlspecialchars($keyword) . '" | Four Rivers Nuclear Partnership';
    $node = ['title' => 'Search Results', 'slug' => 'search', 'type' => 'search'];

    ob_start();
    ?>
    <div class="search-results-page">
        <form method="GET" action="/cms/search" style="margin-bottom: 30px; display: flex; gap: 10px;">
            <input type="text" name="keyword" value="<?= htmlspecialchars($keyword) ?>" placeholder="Search site content..." 
                   style="flex: 1; padding: 10px 14px; font-size: 1rem; border: 1px solid #cbd5e1; border-radius: 4px;" required autofocus>
            <button type="submit" class="btn-primary" style="padding: 10px 24px;">Search</button>
        </form>

        <?php if ($keyword === ''): ?>
            <p style="color: #64748b;">Please enter one or more keywords to search the site.</p>
        <?php elseif (empty($results)): ?>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 25px; border-radius: 6px; color: #475569;">
                No matches found for <strong>&ldquo;<?= htmlspecialchars($keyword) ?>&rdquo;</strong>. Try checking your spelling or using broader search terms.
            </div>
        <?php else: ?>
            <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 20px;">
                Found <?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?> for <strong>&ldquo;<?= htmlspecialchars($keyword) ?>&rdquo;</strong>:
            </p>
            <div class="search-results-list">
                <?php foreach ($results as $res): ?>
                    <article style="margin-bottom: 24px; padding-bottom: 18px; border-bottom: 1px solid #e2e8f0;">
                        <h3 style="margin: 0 0 6px 0; font-size: 1.2rem;">
                            <a href="/cms/<?= htmlspecialchars($res['slug']) ?>" style="color: #0b2545; text-decoration: none;">
                                <?= htmlspecialchars($res['title']) ?>
                            </a>
                        </h3>
                        <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 8px;">
                            <span style="text-transform: uppercase; font-weight: 600;"><?= htmlspecialchars($res['type']) ?></span> &bull; 
                            <?= date('F j, Y', strtotime($res['created_at'])) ?>
                        </div>
                        <?php if (!empty($res['teaser'])): ?>
                            <p style="margin: 0; color: #334155; font-size: 0.93rem; line-height: 1.5;">
                                <?= htmlspecialchars($res['teaser']) ?>...
                            </p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    $content = ob_get_clean();
    include __DIR__ . '/templates/layout.php';
    exit;
}

// 6.5. Custom Pages Route Interception (/custom_pages/*.php)
$custom_page_dir = realpath(__DIR__ . '/custom_pages');
$candidate_file  = __DIR__ . '/custom_pages/' . $path . '.php';
$resolved_file   = realpath($candidate_file);

if ($resolved_file && str_starts_with($resolved_file, $custom_page_dir) && is_file($resolved_file)) {
    $page_title = ucwords(str_replace(['-', '_'], ' ', basename($path))) . ' | Four Rivers Nuclear Partnership';
    $node = [
        'title'  => ucwords(str_replace(['-', '_'], ' ', basename($path))),
        'slug'   => $path,
        'type'   => 'custom_page',
        'status' => 'published'
    ];

    ob_start();
    require $resolved_file;
    $content = ob_get_clean();

    include __DIR__ . '/templates/layout.php';
    exit;
}

// 7. Individual Node Route
$can_view_drafts = user_has_permission('create_content');
$node = get_node_by_slug($path, $can_view_drafts);

if (!$node) {
    http_response_code(404);
    watchdog('page_not_found', "404 Page Not Found encountered for path: '/{$path}'", 'warning');
    $node = [
        'title'       => 'Page Not Found',
        'body'        => '<p>The requested page could not be located on the Four Rivers Nuclear Partnership portal.</p><p><a href="/cms/home">Return to Home</a></p>',
        'type'        => 'page',
        'slug'        => '404',
        'created_at'  => date('Y-m-d H:i:s'),
        'fields_data' => []
    ];
}

render_node($node);