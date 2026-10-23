<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_site');

$db = get_db();
$message = '';
$export_stats = [];

function copy_dir_recursive(string $src, string $dst): void {
    $dir = opendir($src);
    @mkdir($dst, 0755, true);
    while (false !== ($file = readdir($dir))) {
        if ($file !== '.' && $file !== '..') {
            if (is_dir($src . '/' . $file)) {
                copy_dir_recursive($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $export_dir = __DIR__ . '/../export';

    // 1. Prepare export directory structure
    if (!is_dir($export_dir)) {
        mkdir($export_dir, 0755, true);
    }

    // 2. Mirror static assets and uploaded media
    copy_dir_recursive(__DIR__ . '/../assets', $export_dir . '/assets');
    copy_dir_recursive(__DIR__ . '/../uploads', $export_dir . '/uploads');

    // Remove protected uploads .htaccess from export
    if (file_exists($export_dir . '/uploads/.htaccess')) {
        unlink($export_dir . '/uploads/.htaccess');
    }

    // Base HTTP address of the local web server
    $base_host = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];

    // 3. Compile all published nodes
    $nodes = $db->query("SELECT slug FROM nodes WHERE status = 'published'")->fetchAll(PDO::FETCH_COLUMN);
    $compiled_count = 0;

    foreach ($nodes as $slug) {
        $source_url = "{$base_host}/cms/{$slug}";
        $html = @file_get_contents($source_url);

        if ($html !== false) {
            // Rewrite internal dynamic query paths to static file references
            $rewritten = str_replace('/cms/', './', $html);

            if ($slug === 'home') {
                file_put_contents($export_dir . '/index.html', $rewritten);
            } else {
                $target_folder = $export_dir . '/' . dirname($slug);
                if (!is_dir($target_folder)) {
                    mkdir($target_folder, 0755, true);
                }
                file_put_contents($export_dir . '/' . $slug . '.html', $rewritten);
            }
            $compiled_count++;
        }
    }

    // 4. Compile dynamic views (/news and /sitemap.xml)
    $news_html = @file_get_contents("{$base_host}/cms/news");
    if ($news_html !== false) {
        file_put_contents($export_dir . '/news.html', str_replace('/cms/', './', $news_html));
    }

    $sitemap_xml = @file_get_contents("{$base_host}/cms/sitemap.xml");
    if ($sitemap_xml !== false) {
        file_put_contents($export_dir . '/sitemap.xml', $sitemap_xml);
    }

    watchdog('system', "Static snapshot compiled successfully ({$compiled_count} pages).", 'info');
    $message = "Export complete. {$compiled_count} HTML pages and all media assets compiled to /cms/export/.";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Static Site Exporter - FRNP CMS</title>
    <link rel="stylesheet" href="/cms/assets/css/admin.css">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main" style="max-width: 800px;">
            <h1>Deployment &raquo; Static Site Generator</h1>
            <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>

            <section class="card">
                <h3>Generate Offline Static Distribution</h3>
                <p style="margin-bottom: 20px; color:#555; line-height: 1.5;">
                    Crawls all published nodes, collections, taxonomy archives, and asset trees, compiling the entire site into flat HTML, CSS, and media files in <code>/cms/export/</code>.
                </p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <button type="submit" class="btn-primary" style="padding: 12px 24px;">
                        ⚡ Generate Static Snapshot
                    </button>
                </form>
            </section>
        </main>
    </div>
</body>
</html>