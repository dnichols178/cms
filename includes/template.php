<?php
declare(strict_types=1);

function render_node(array $node): void {
    $template_dir = __DIR__ . '/../templates/';
    
    // Drupal-style template hierarchy: custom template -> node--[type].php -> node.php
    $candidate = null;
    if (!empty($node['template']) && file_exists($template_dir . $node['template'] . '.php')) {
        $candidate = $template_dir . $node['template'] . '.php';
    } elseif (file_exists($template_dir . 'node--' . $node['type'] . '.php')) {
        $candidate = $template_dir . 'node--' . $node['type'] . '.php';
    } else {
        $candidate = $template_dir . 'node.php';
    }

    // Capture inner node HTML
    ob_start();
    extract(['node' => $node]);
    include $candidate;
    $content = ob_get_clean();

    // Wrap in global layout
    $page_title = htmlspecialchars($node['title']) . ' | Four Rivers Nuclear Partnership';
    include $template_dir . 'layout.php';
}