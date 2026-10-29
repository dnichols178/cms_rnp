<?php declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_site');

$db = get_db();
$menu_name = 'main';

// Ensure table has parent_id column
try {
    $col_check = $db->query("SHOW COLUMNS FROM menu_items LIKE 'parent_id'")->fetch();
    if (!$col_check) {
        $db->exec("ALTER TABLE menu_items ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL AFTER weight, ADD INDEX (parent_id)");
    }
} catch (\Throwable $e) {}

// 1. Handle Deleting a Menu Item
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    validate_csrf();
    $del_id = (int)$_GET['id'];
    // Re-parent any direct child links to root before deleting
    $reparent = $db->prepare('UPDATE menu_items SET parent_id = NULL WHERE parent_id = ?');
    $reparent->execute([$del_id]);
    $del = $db->prepare('DELETE FROM menu_items WHERE id = ?');
    $del->execute([$del_id]);
    if (function_exists('cache_clear')) {
        cache_clear();
    }
    watchdog('menu', "Deleted menu item ID: {$del_id}.", 'notice');
    set_flash('success', 'Menu link removed. Any child items have been cleanly re-parented.');
    header('Location: ' . base_path('admin/menus.php'));
    exit;
}

// 2. Handle Updating Hierarchy & Ordering (Batch Save)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_hierarchy') {
    validate_csrf();
    $weights = $_POST['weights'] ?? [];
    $parents = $_POST['parents'] ?? [];
    $titles  = $_POST['titles'] ?? [];
    $urls    = $_POST['urls'] ?? [];

    $upd = $db->prepare('UPDATE menu_items SET title = :t, url = :u, parent_id = :p, weight = :w WHERE id = :id');
    foreach ($weights as $item_id => $weight) {
        $item_id = (int)$item_id;
        $parent_val = !empty($parents[$item_id]) ? (int)$parents[$item_id] : null;
        if ($parent_val === $item_id) {
            $parent_val = null; // Prevent self-parent loop
        }
        $title_val = trim($titles[$item_id] ?? '');
        $url_val   = trim($urls[$item_id] ?? '');

        // Normalize internal path so it is portable across hosts
        if ($url_val !== '' && !str_starts_with($url_val, 'http://') && !str_starts_with($url_val, 'https://')) {
            $cleaned_slug = preg_replace('#^/?cms/#', '', ltrim($url_val, '/'));
            $url_val = '/' . ltrim($cleaned_slug, '/');
        }

        if ($title_val !== '' && $url_val !== '') {
            $upd->execute([
                't'  => $title_val,
                'u'  => $url_val,
                'p'  => $parent_val,
                'w'  => (int)$weight,
                'id' => $item_id
            ]);
        }
    }
    if (function_exists('cache_clear')) {
        cache_clear();
    }
    watchdog('menu', 'Updated main navigation menu hierarchy.', 'notice');
    set_flash('success', 'Menu structure and ordering saved successfully.');
    header('Location: ' . base_path('admin/menus.php'));
    exit;
}

// 3. Handle Adding New Menu Link
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_link') {
    validate_csrf();
    $title  = trim($_POST['title'] ?? '');
    $url    = trim($_POST['url'] ?? '');
    $parent = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    $weight = (int)($_POST['weight'] ?? 0);

    if ($title === '' || $url === '') {
        set_flash('error', 'Both Link Title and Target URL are required.');
        header('Location: ' . base_path('admin/menus.php'));
        exit;
    } else {
        // Normalize portable internal path without hardcoded /cms/ prefix
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $cleaned_slug = preg_replace('#^/?cms/#', '', ltrim($url, '/'));
            $url = '/' . ltrim($cleaned_slug, '/');
        }

        $ins = $db->prepare('
            INSERT INTO menu_items (menu_name, title, url, parent_id, weight)
            VALUES (:m, :t, :u, :p, :w)
        ');
        $ins->execute([
            'm' => $menu_name,
            't' => $title,
            'u' => $url,
            'p' => $parent,
            'w' => $weight
        ]);
        if (function_exists('cache_clear')) {
            cache_clear();
        }
        watchdog('menu', "Added menu link '{$title}' -> {$url}", 'notice');
        set_flash('success', "Menu link '{$title}' added successfully.");
        header('Location: ' . base_path('admin/menus.php'));
        exit;
    }
}

// Fetch all menu items
$raw_items = $db->query('SELECT * FROM menu_items WHERE menu_name = "main" ORDER BY weight ASC, id ASC')->fetchAll();

// Build hierarchical structure
$items_by_parent = [];
$top_level_items = [];
foreach ($raw_items as $item) {
    $pid = $item['parent_id'] ? (int)$item['parent_id'] : 0;
    $items_by_parent[$pid][] = $item;
    if ($pid === 0) {
        $top_level_items[] = $item;
    }
}

// Flatten for tree table rendering
$flattened_menu = [];
function flatten_menu_branch(int $parent_id, int $depth, array $items_by_parent, array &$output): void {
    if (empty($items_by_parent[$parent_id])) {
        return;
    }
    foreach ($items_by_parent[$parent_id] as $child) {
        $child['depth'] = $depth;
        $output[] = $child;
        flatten_menu_branch((int)$child['id'], $depth + 1, $items_by_parent, $output);
    }
}
flatten_menu_branch(0, 0, $items_by_parent, $flattened_menu);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Menu Management - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main" style="max-width: 1350px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h1>Structure &raquo; Navigation Menu</h1>
                <a href="<?= base_path('home') ?>" class="btn-secondary" style="font-size:0.85rem;">View Live Site &raquo;</a>
            </div>

            <?php render_flash_messages(); ?>

            <div class="grid-layout">
                <!-- Menu Structure Table -->
                <section class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                        <h3>Menu Links & Hierarchy</h3>
                        <small style="color:#64748b;">Set a parent item to create hover dropdowns.</small>
                    </div>

                    <?php if (empty($flattened_menu)): ?>
                        <div style="padding: 30px; text-align: center; color: #64748b; border: 1px dashed #cbd5e1; border-radius: 6px;">
                            No menu links configured. Add a link using the form on the right.
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                            <input type="hidden" name="action" value="save_hierarchy">
                            <table class="menu-table">
                                <thead>
                                    <tr>
                                        <th>Link Title</th>
                                        <th>Target URL</th>
                                        <th style="width: 210px;">Parent Item</th>
                                        <th style="width: 80px; text-align:center;">Weight</th>
                                        <th style="width: 70px; text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($flattened_menu as $item): ?>
                                    <?php 
                                        $id = (int)$item['id'];
                                        $depth = (int)$item['depth'];
                                        $has_children = !empty($items_by_parent[$id]);
                                        // Strip legacy cms prefix for display/editing
                                        $raw_url = $item['url'];
                                        if (!str_starts_with($raw_url, 'http://') && !str_starts_with($raw_url, 'https://')) {
                                            $clean_val = '/' . ltrim(preg_replace('#^/?cms/#', '', ltrim($raw_url, '/')), '/');
                                        } else {
                                            $clean_val = $raw_url;
                                        }
                                    ?>
                                    <tr style="<?= $depth > 0 ? 'background: #fafbfc;' : 'background: #ffffff;' ?>">
                                        <td class="depth-<?= min(2, $depth) ?>">
                                            <?php if ($depth > 0): ?>
                                                <span style="color: #94a3b8; font-weight: normal; margin-right: 4px;">↳</span>
                                            <?php endif; ?>
                                            <input type="text" name="titles[<?= $id ?>]" value="<?= htmlspecialchars($item['title']) ?>" style="font-weight: <?= $depth === 0 ? '700' : '500' ?>; width: calc(100% - 30px);">
                                            <?php if ($has_children): ?>
                                                <br><span class="badge-parent" style="margin-top: 4px;">Has Submenu</span>
                                            <?php elseif ($depth > 0): ?>
                                                <br><span class="badge-child" style="margin-top: 4px;">Dropdown Link</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <input type="text" name="urls[<?= $id ?>]" value="<?= htmlspecialchars($clean_val) ?>" style="width: 100%; font-family: monospace;">
                                        </td>
                                        <td>
                                            <select name="parents[<?= $id ?>]">
                                                <option value="">&lt;Top Level / Main Menu&gt;</option>
                                                <?php foreach ($top_level_items as $p): ?>
                                                    <?php if ((int)$p['id'] !== $id): ?>
                                                        <option value="<?= (int)$p['id'] ?>" <?= ((int)$item['parent_id'] === (int)$p['id']) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($p['title']) ?>
                                                        </option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td style="text-align: center;">
                                            <input type="number" name="weights[<?= $id ?>]" value="<?= (int)$item['weight'] ?>" style="width: 65px; text-align: center;">
                                        </td>
                                        <td style="text-align: right;">
                                            <a href="<?= base_path('admin/menus.php?action=delete&id=' . $id . '&csrf_token=' . get_csrf_token()) ?>" 
                                               data-confirm="Remove &quot;<?= htmlspecialchars($item['title'], ENT_QUOTES) ?>&quot; from the menu?" 
                                               data-confirm-title="Remove Menu Link"
                                               data-confirm-btn="Delete"
                                               style="color: #c00; font-size: 0.82rem; text-decoration: none;">Delete</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid #e2e8f0;">
                                <button type="submit" class="btn-primary" style="padding: 10px 22px;">Save Menu Hierarchy</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>

                <!-- Add New Menu Link Form -->
                <section class="card">
                    <h3>Add Menu Link</h3>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="add_link">

                        <div class="form-row">
                            <label>Menu Link Title *</label>
                            <input type="text" name="title" required placeholder="e.g. Site Tours">
                        </div>

                        <div class="form-row">
                            <label>Target URL / Path *</label>
                            <input type="text" name="url" required placeholder="/tours or https://...">
                            <small style="color:#64748b;">Enter relative routes (e.g. <code>/tours</code> or <code>about-us</code>). The CMS automatically prefixes the current site root.</small>
                        </div>

                        <div class="form-row">
                            <label>Parent Menu Item</label>
                            <select name="parent_id" style="width: 100%; padding: 8px;">
                                <option value="">&lt;Top Level / Main Menu&gt;</option>
                                <?php foreach ($top_level_items as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>">
                                        <?= htmlspecialchars($p['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color:#64748b;">Select a parent to make this link a dropdown submenu item.</small>
                        </div>

                        <div class="form-row">
                            <label>Weight / Order</label>
                            <input type="number" name="weight" value="<?= count($raw_items) * 5 ?>" style="width: 90px;">
                            <small style="color:#64748b;">Lower numbers render first from left to right.</small>
                        </div>

                        <button type="submit" class="btn-primary" style="width: 100%; margin-top: 10px; padding: 10px;">Add Link to Menu</button>
                    </form>
                </section>
            </div>
        </main>
    </div>
</body>
</html>