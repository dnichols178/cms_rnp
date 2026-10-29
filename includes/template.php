<?php
declare(strict_types=1);

/**
 * Dispatches node rendering with slug and type-level template inheritance.
 */
function render_node(array $node): void {
    $slug_clean = str_replace('/', '--', trim($node['slug'] ?? '', '/'));
    
    // 1. Slug template override (excluding 'home' which is handled as a layout hero)
    $candidate = __DIR__ . '/../templates/node--' . $slug_clean . '.php';
    if ($slug_clean !== '' && $slug_clean !== 'home' && file_exists($candidate)) {
        $template_file = $candidate;
    } 
    // 2. Explicit node template attribute override
    elseif (!empty($node['template']) && file_exists(__DIR__ . '/../templates/' . $node['template'])) {
        $template_file = __DIR__ . '/../templates/' . $node['template'];
    } 
    // 3. Content type override (e.g., node--page.php, node--article.php)
    elseif (!empty($node['type']) && file_exists(__DIR__ . '/../templates/node--' . $node['type'] . '.php')) {
        $template_file = __DIR__ . '/../templates/node--' . $node['type'] . '.php';
    } 
    // 4. Default generic node fallback
    elseif (file_exists(__DIR__ . '/../templates/node.php')) {
        $template_file = __DIR__ . '/../templates/node.php';
    } 
    else {
        $template_file = null;
    }

    $site_name = get_site_setting('site_name', 'Four Rivers Nuclear Partnership');
    if (($node['slug'] ?? '') === 'home') {
        $site_slogan = get_site_setting('site_slogan', 'Paducah Deactivation Project');
        $page_title  = htmlspecialchars($site_name) . ' | ' . htmlspecialchars($site_slogan);
    } else {
        $page_title  = htmlspecialchars($node['title'] ?? '') . ' | ' . htmlspecialchars($site_name);
    }

    $path = $node['slug'] ?? '';
    
    ob_start();
    if ($template_file !== null && file_exists($template_file)) {
        include $template_file;
    } else {
        echo '<article class="node-page"><div class="node-body">' . ($node['body'] ?? '') . '</div></article>';
    }
    $content = ob_get_clean();

    include __DIR__ . '/../templates/layout.php';
    exit;
}