<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('create_content');

$media_dir = __DIR__ . '/../uploads/files/';
if (!is_dir($media_dir)) {
    @mkdir($media_dir, 0755, true);
}

/**
 * Automatically resizes an uploaded raster image to a specific width (750px) 
 * while maintaining the aspect ratio for computed height.
 */
function resize_image_to_width(string $filepath, int $target_width = 750): bool {
    if (!extension_loaded('gd') || !file_exists($filepath)) {
        return false;
    }

    $image_info = @getimagesize($filepath);
    if (!$image_info) {
        return false;
    }

    [$orig_w, $orig_h, $image_type] = $image_info;
    if ($orig_w <= 0 || $orig_h <= 0) {
        return false;
    }

    if ($orig_w === $target_width) {
        return true;
    }

    $target_height = (int)max(1, round(($orig_h / $orig_w) * $target_width));

    $src = match ($image_type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($filepath),
        IMAGETYPE_PNG  => @imagecreatefrompng($filepath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filepath) : false,
        IMAGETYPE_GIF  => @imagecreatefromgif($filepath),
        default        => false,
    };

    if (!$src) {
        return false;
    }

    // Correct EXIF orientation for JPEG captures
    if ($image_type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($filepath);
        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3:
                    $src = imagerotate($src, 180, 0);
                    break;
                case 6:
                    $src = imagerotate($src, -90, 0);
                    $tmp = $target_width;
                    $target_width = $target_height;
                    $target_height = $tmp;
                    break;
                case 8:
                    $src = imagerotate($src, 90, 0);
                    $tmp = $target_width;
                    $target_width = $target_height;
                    $target_height = $tmp;
                    break;
            }
        }
    }

    $dst = imagecreatetruecolor($target_width, $target_height);
    if (!$dst) {
        imagedestroy($src);
        return false;
    }

    // Preserve transparency for alpha channels
    if (in_array($image_type, [IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefilledrectangle($dst, 0, 0, $target_width, $target_height, $transparent);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $target_width, $target_height, imagesx($src), imagesy($src));

    $saved = match ($image_type) {
        IMAGETYPE_JPEG => imagejpeg($dst, $filepath, 88),
        IMAGETYPE_PNG  => imagepng($dst, $filepath, 8),
        IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($dst, $filepath, 85) : false,
        IMAGETYPE_GIF  => imagegif($dst, $filepath),
        default        => false,
    };

    imagedestroy($src);
    imagedestroy($dst);

    return (bool)$saved;
}

// 0. Dedicated AJAX Endpoint for Field Uploads
if (isset($_GET['action']) && $_GET['action'] === 'ajax_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    validate_csrf();

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'No file received or upload transfer error.']);
        exit;
    }

    $up = $_FILES['file'];
    $ext = strtolower(pathinfo($up['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip', 'mp4'];
    
    if (!in_array($ext, $allowed, true)) {
        echo json_encode(['success' => false, 'error' => "File extension .{$ext} is not allowed."]);
        exit;
    }

    $raw_dir = trim($_POST['upload_dir'] ?? '');
    $clean_dir = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $raw_dir);
    $clean_dir = trim(preg_replace('#/+#', '/', $clean_dir), '/');
    $subfolder = $clean_dir !== '' ? $clean_dir : 'files';

    $dest_dir = __DIR__ . '/../uploads/' . $subfolder . '/';
    if (!is_dir($dest_dir)) {
        @mkdir($dest_dir, 0755, true);
    }

    $clean_name = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($up['name'], PATHINFO_FILENAME));
    $new_filename = sprintf('%s_%s.%s', $clean_name, bin2hex(random_bytes(3)), $ext);
    $dest = $dest_dir . $new_filename;

    if (move_uploaded_file($up['tmp_name'], $dest)) {
        // Automatically resize raster images to 750px width
        if (preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $new_filename)) {
            resize_image_to_width($dest, 750);
        }

        $file_url = base_path('uploads/' . $subfolder . '/' . $new_filename);
        echo json_encode([
            'success'  => true,
            'url'      => $file_url,
            'filename' => $new_filename,
            'is_img'   => (bool)preg_match('/\.(jpg|jpeg|png|webp|gif|svg)$/i', $new_filename)
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to write file to storage.']);
    }
    exit;
}

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

$db = get_db();

// Available terms and media assets for drawer
$all_terms = get_all_taxonomy_terms();
$current_term_ids = $node ? array_column($node['terms'], 'id') : [];
$existing_menu_link = $id ? get_node_menu_link($id, 'main') : null;
$top_level_menu_items = get_top_level_menu_items('main', $id);

$media_files = [];
if (is_dir($media_dir)) {
    foreach (scandir($media_dir, SCANDIR_SORT_DESCENDING) as $f) {
        if ($f !== '.' && $f !== '..' && $f !== '.htaccess') {
            $media_files[] = [
                'name'   => $f,
                'url'    => base_path('uploads/files/' . rawurlencode($f)),
                'is_img' => (bool)preg_match('/\.(jpg|jpeg|png|webp|gif|svg)$/i', $f)
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $title = trim($_POST['title'] ?? '');
    $custom_data = $node['fields_data'] ?? [];

    // Process all configured fields in schema
    foreach ($content_type['fields_schema'] as $f) {
        $fname = $f['name'];
        $ftype = $f['type'] ?? 'text';

        // Skip body since it is stored in the primary node record column
        if ($fname === 'body' || $ftype === 'body') {
            continue;
        }

        if ($ftype === 'file' || $ftype === 'image') {
            $subfolder = !empty($f['upload_dir']) ? trim($f['upload_dir'], '/') : 'files';
            $subfolder = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $subfolder);
            $subfolder = trim(preg_replace('#/+#', '/', $subfolder), '/');
            if ($subfolder === '') {
                $subfolder = 'files';
            }

            $target_dir = __DIR__ . '/../uploads/' . $subfolder . '/';
            if (!is_dir($target_dir)) {
                @mkdir($target_dir, 0755, true);
            }

            $existing_ajax_val = trim((string)($_POST['custom_' . $fname] ?? ''));

            // 1. If an AJAX upload already populated the hidden input, retain it (prevents duplicate write)
            if ($existing_ajax_val !== '') {
                $custom_data[$fname] = $existing_ajax_val;
            }
            // 2. Otherwise process fallback direct form POST upload if a new file was submitted
            elseif (!empty($_FILES['custom_file_' . $fname]['name']) && $_FILES['custom_file_' . $fname]['error'] === UPLOAD_ERR_OK) {
                $up = $_FILES['custom_file_' . $fname];
                $ext = strtolower(pathinfo($up['name'], PATHINFO_EXTENSION));
                $clean_name = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($up['name'], PATHINFO_FILENAME));
                $new_filename = sprintf('%s_%s.%s', $clean_name, bin2hex(random_bytes(3)), $ext);
                $dest = $target_dir . $new_filename;
                if (move_uploaded_file($up['tmp_name'], $dest)) {
                    // Automatically resize raster images to 750px width
                    if (preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $new_filename)) {
                        resize_image_to_width($dest, 750);
                    }
                    $custom_data[$fname] = base_path('uploads/' . $subfolder . '/' . $new_filename);
                }
            } else {
                $custom_data[$fname] = '';
            }
        } elseif ($ftype === 'boolean') {
            $custom_data[$fname] = isset($_POST['custom_' . $fname]) ? 1 : 0;
        } else {
            $custom_data[$fname] = trim($_POST['custom_' . $fname] ?? '');
        }
    }

    // Process Taxonomy Deluxe Terms (Supporting both existing IDs and on-the-fly created tags)
    $raw_terms = $_POST['terms'] ?? [];
    $selected_terms = [];
    $default_vocab_id = null;

    foreach ($raw_terms as $t_val) {
        $t_val = trim((string)$t_val);
        if ($t_val === '') continue;

        if (ctype_digit($t_val) || is_int($t_val)) {
            $selected_terms[] = (int)$t_val;
        } else {
            // Check if term already exists by name or slug
            $clean_slug = preg_replace('/[^a-z0-9_-]/', '', strtolower(str_replace(' ', '-', $t_val)));
            $clean_slug = trim(preg_replace('/-+/', '-', $clean_slug), '-');

            $chk = $db->prepare('SELECT id FROM taxonomy_terms WHERE name = ? OR slug = ? LIMIT 1');
            $chk->execute([$t_val, $clean_slug]);
            $existing_tid = $chk->fetchColumn();

            if ($existing_tid) {
                $selected_terms[] = (int)$existing_tid;
            } else {
                if ($default_vocab_id === null) {
                    $default_vocab_id = (int)($db->query('SELECT id FROM taxonomy_vocabularies ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);
                }
                $ins_term = $db->prepare('INSERT INTO taxonomy_terms (vocabulary_id, name, slug, weight) VALUES (?, ?, ?, 0)');
                $ins_term->execute([$default_vocab_id, $t_val, $clean_slug !== '' ? $clean_slug : 'term-' . bin2hex(random_bytes(3))]);
                $selected_terms[] = (int)$db->lastInsertId();
            }
        }
    }
    $selected_terms = array_values(array_unique($selected_terms));

    // Option A: Automatically compile keywords from selected taxonomy terms
    $auto_keywords = '';
    if (!empty($selected_terms)) {
        $placeholders = implode(',', array_fill(0, count($selected_terms), '?'));
        $kw_stmt = $db->prepare("SELECT name FROM taxonomy_terms WHERE id IN ({$placeholders})");
        $kw_stmt->execute($selected_terms);
        $term_names = $kw_stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($term_names)) {
            $auto_keywords = implode(', ', $term_names);
        }
    }

    $auto_slug = isset($_POST['auto_slug']) ? 1 : 0;
    $slug_input = trim($_POST['slug'] ?? '');

    $save_payload = [
        'type'              => $content_type['type'],
        'title'             => $title,
        'seo_title'         => trim($_POST['seo_title'] ?? ''),
        'seo_description'   => trim($_POST['seo_description'] ?? ''),
        'seo_focus_keyword' => trim($_POST['seo_focus_keyword'] ?? ''),
        'slug'              => $slug_input,
        'auto_slug'         => $auto_slug,
        'body'              => $_POST['body'] ?? '',
        'format'            => $_POST['format'] ?? 'filtered_html',
        'keywords'          => $auto_keywords,
        'fields_data'       => $custom_data,
        'uid'               => current_user()['id'],
        'status'            => in_array($_POST['status'] ?? '', ['draft', 'in_review', 'published', 'archived'], true) ? $_POST['status'] : 'draft',
        'publish_on'        => !empty($_POST['publish_on']) ? str_replace('T', ' ', $_POST['publish_on']) : null,
        'unpublish_on'      => !empty($_POST['unpublish_on']) ? str_replace('T', ' ', $_POST['unpublish_on']) : null,
        'template'          => !empty($_POST['template']) ? trim($_POST['template']) : null
    ];

    // Save node and retrieve the inserted/updated ID
    $saved_id = save_node($save_payload, $id, $selected_terms);

    // Process Main Menu Link for Basic Pages
    if ($content_type['type'] === 'page') {
        $menu_enabled = isset($_POST['menu_link_enabled']);
        $menu_title = trim($_POST['menu_link_title'] ?? '') ?: $title;
        $menu_parent_id = !empty($_POST['menu_parent_id']) ? (int)$_POST['menu_parent_id'] : null;
        $menu_weight = (int)($_POST['menu_link_weight'] ?? 0);
        $saved_node = get_node_by_id($saved_id);
        save_node_menu_link($saved_id, $saved_node['slug'], $menu_title, $menu_enabled, $menu_parent_id, $menu_weight, 'main');
    }

    // Redirect based on action button
    if (isset($_POST['submit_action']) && $_POST['submit_action'] === 'save_admin') {
        set_flash('success', 'Content saved successfully.');
        header('Location: ' . base_path('admin/nodes.php'));
    } else {
        $saved_node = get_node_by_id($saved_id);
        $final_slug = $saved_node['slug'] ?? $slug_input;
        $clean_url_path = implode('/', array_map('rawurlencode', explode('/', ltrim($final_slug, '/'))));
        set_flash('success', 'Content published successfully.');
        header('Location: ' . base_path("{$clean_url_path}"));
    }
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= $id ? 'Edit' : 'Create' ?> <?= htmlspecialchars($content_type['name']) ?> - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    
    <!-- Trumbowyg WYSIWYG Editor Core & Plugins -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Trumbowyg/2.27.3/ui/trumbowyg.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Trumbowyg/2.27.3/plugins/colors/ui/trumbowyg.colors.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Trumbowyg/2.27.3/plugins/table/ui/trumbowyg.table.min.css">

</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>

        <main class="admin-main">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h1><?= $id ? 'Edit' : 'Create' ?> <?= htmlspecialchars($content_type['name']) ?></h1>
                <?php if ($id): ?>
                    <a href="<?= base_path('admin/revisions.php?id=' . $id) ?>" class="btn-secondary" style="font-size:0.85rem;">View Revision History</a>
                <?php endif; ?>
            </div>
            
            <?php render_flash_messages(); ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">

                <div class="editor-container">
                    <!-- Main Content Column -->
                    <div class="editor-main">
                        <!-- 1. Title is Always First -->
                        <div class="form-row">
                            <label>Title <span style="color:#c00;">*</span></label>
                            <input type="text" name="title" value="<?= htmlspecialchars($node['title'] ?? '') ?>" required autofocus>
                        </div>

                        <!-- 2. Pathauto URL Configuration -->
                        <div class="form-row" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px; margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <label style="margin: 0; font-weight: 700; color: #0b2545;">URL Path</label>
                                <label style="font-weight: 600; font-size: 0.85rem; cursor: pointer; color: #1e293b; display: flex; align-items: center; gap: 6px;">
                                    <input type="checkbox" name="auto_slug" id="auto_slug" value="1" 
                                           <?= (!isset($node['auto_slug']) || $node['auto_slug']) ? 'checked' : '' ?>
                                           onchange="handleAutoSlugToggle(this.checked)">
                                    <span>Generate automatic URL alias</span>
                                </label>
                            </div>

                            <div id="box_auto_preview" style="display: <?= (!isset($node['auto_slug']) || $node['auto_slug']) ? 'block' : 'none' ?>;">
                                <div style="display: flex; align-items: center; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 4px; padding: 8px 12px; font-family: monospace; font-size: 0.88rem;">
                                    <span style="color: #64748b;">/</span>
                                    <span id="preview_slug_text" style="font-weight: 700; color: #0b2545; word-break: break-all;">
                                        <?= htmlspecialchars($node['slug'] ?? 'generating-url...') ?>
                                    </span>
                                </div>
                                <small style="color: #64748b; margin-top: 4px; display: block;">
                                    Template configured in <a href="<?= base_path('admin/pathauto.php') ?>" style="color: #0b2545; font-weight: 600;">URL Patterns</a>: <code>/<?= htmlspecialchars($content_type['url_pattern'] ?? '[node:title]')?></code>
                                </small>
                            </div>

                            <div id="box_custom_slug" style="display: <?= (isset($node['auto_slug']) && !$node['auto_slug']) ? 'block' : 'none' ?>;">
                                <div style="display: flex; align-items: center;">
                                    <span style="background: #f1f5f9; border: 1px solid #cbd5e1; border-right: none; padding: 8px 12px; font-family: monospace; font-size: 0.88rem; color: #64748b; border-radius: 4px 0 0 4px;"><?= htmlspecialchars(base_path()) ?></span>
                                    <input type="text" name="slug" id="slug_custom_input" 
                                           value="<?= htmlspecialchars($node['slug'] ?? '') ?>" 
                                           placeholder="custom-path/or-[node:title]"
                                           style="border-top-left-radius: 0; border-bottom-left-radius: 0; font-family: monospace; font-size: 0.88rem;">
                                </div>
                                <div style="margin-top: 8px; padding: 8px 10px; background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 4px; display: flex; flex-wrap: wrap; gap: 4px; align-items: center;">
                                    <span style="font-size: 0.72rem; font-weight: 700; color: #475569; text-transform: uppercase; margin-right: 4px;">Insert Token:</span>
                                    <button type="button" class="btn-token" onclick="insertToken('[node:title]')">[node:title]</button>
                                    <button type="button" class="btn-token" onclick="insertToken('[node:type]')">[node:type]</button>
                                    <button type="button" class="btn-token" onclick="insertToken('[node:created:Y]')">[node:created:Y]</button>
                                    <button type="button" class="btn-token" onclick="insertToken('[node:created:m]')">[node:created:m]</button>
                                    <button type="button" class="btn-token" onclick="insertToken('[term:slug]')">[term:slug]</button>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Sequential Form Fields (Rendered strictly in Content Type Schema Order) -->
                        <?php foreach ($content_type['fields_schema'] as $field): ?>
                            <?php 
                                $fname = $field['name'];
                                $ftype = $field['type'] ?? 'text';
                                $val = $node['fields_data'][$fname] ?? '';
                                $is_req = !empty($field['required']);
                                $req_attr = $is_req ? 'required' : '';
                            ?>

                            <?php if ($fname === 'body' || $ftype === 'body'): ?>
                                <!-- Rich Text Body Editor in configured sequence position -->
                                <div class="form-row" style="margin: 22px 0;">
                                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px;">
                                        <label style="font-weight: 700; color: #0b2545;">
                                            <?= htmlspecialchars($field['label']) ?>
                                            <?php if ($is_req): ?><span style="color:#c00;">*</span><?php endif; ?>
                                        </label>
                                        <small style="color: #64748b;">WYSIWYG active &bull; Click 'View HTML' to edit raw source</small>
                                    </div>
                                    <textarea id="node_body" name="body" <?= $req_attr ?>><?= htmlspecialchars($node['body'] ?? '') ?></textarea>
                                    
                                    <div style="background:#f1f5f9; padding:8px 12px; border-radius:4px; margin-top:8px; display:flex; align-items:center; gap:12px;">
                                        <label style="margin:0; font-size:0.82rem; font-weight:600;">Text Processing Format:</label>
                                        <select name="format" style="width:auto; padding:4px 8px; font-size:0.85rem;">
                                            <option value="filtered_html" <?= ($node['format'] ?? 'filtered_html') === 'filtered_html' ? 'selected' : '' ?>>Filtered HTML</option>
                                            <option value="full_html" <?= ($node['format'] ?? '') === 'full_html' ? 'selected' : '' ?>>Full HTML</option>
                                            <option value="plain_text" <?= ($node['format'] ?? '') === 'plain_text' ? 'selected' : '' ?>>Plain Text</option>
                                        </select>
                                    </div>
                                    <?php if (!empty($field['help_text'])): ?>
                                        <small style="color: #64748b;"><?= htmlspecialchars($field['help_text']) ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <!-- All Other Schema Fields -->
                                <div class="form-row" style="margin-bottom: 18px;">
                                    <label style="font-weight: 600;">
                                        <?= htmlspecialchars($field['label']) ?>
                                        <?php if ($is_req): ?><span style="color:#c00;">*</span><?php endif; ?>
                                    </label>

                                    <?php if ($ftype === 'image' || $ftype === 'file'): ?>
                                        <?php $upload_subfolder = !empty($field['upload_dir']) ? $field['upload_dir'] : 'files'; ?>
                                        <!-- Dedicated AJAX Upload Container with Visual Feedback -->
                                        <input type="hidden" name="custom_<?= htmlspecialchars($fname) ?>" id="val_<?= htmlspecialchars($fname) ?>" value="<?= htmlspecialchars((string)$val) ?>">
                                        <div class="upload-field-container" id="container_<?= htmlspecialchars($fname) ?>">
                                            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                                <input type="file" 
                                                       id="file_input_<?= htmlspecialchars($fname) ?>" 
                                                       name="custom_file_<?= htmlspecialchars($fname) ?>" 
                                                       style="flex: 1; min-width: 200px; padding: 6px; font-size: 0.88rem; background: #fff; border: 1px solid #cbd5e1; border-radius: 4px;"
                                                       <?= (empty($val) ? $req_attr : '') ?>
                                                       onchange="handleFileSelected('<?= htmlspecialchars($fname) ?>')">
                                                
                                                <button type="button" 
                                                        id="btn_upload_<?= htmlspecialchars($fname) ?>" 
                                                        class="btn-primary" 
                                                        style="padding: 7px 16px; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px;"
                                                        onclick="triggerAjaxUpload('<?= htmlspecialchars($fname) ?>', '<?= htmlspecialchars($ftype) ?>', '<?= htmlspecialchars($upload_subfolder) ?>')">
                                                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                                    </svg>
                                                    Upload
                                                </button>
                                            </div>

                                            <!-- Live Feedback & Preview Container -->
                                            <div id="feedback_<?= htmlspecialchars($fname) ?>" style="margin-top: 10px; <?= empty($val) ? 'display: none;' : '' ?>">
                                                <?php if (!empty($val)): ?>
                                                    <div class="upload-success-badge">
                                                        <?php if ($ftype === 'image'): ?>
                                                            <img src="<?= htmlspecialchars((string)$val) ?>" alt="" style="max-height: 48px; max-width: 70px; object-fit: cover; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff;">
                                                        <?php else: ?>
                                                            <span style="font-size: 1.25rem;">📄</span>
                                                        <?php endif; ?>
                                                        <div style="flex: 1; min-width: 0;">
                                                            <div style="color: #065f46; font-weight: 700; font-size: 0.85rem;">
                                                                ✓ Current <?= $ftype === 'image' ? 'Image' : 'Document' ?>:
                                                            </div>
                                                            <div style="font-size: 0.82rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                                <a href="<?= htmlspecialchars((string)$val) ?>" target="_blank" style="color: #0b2545; font-weight: 600; text-decoration: underline;">
                                                                    <?= htmlspecialchars(basename((string)$val)) ?>
                                                                </a>
                                                            </div>
                                                        </div>
                                                        <button type="button" class="btn-secondary" style="font-size: 0.75rem; padding: 3px 8px; color: #b91c1c;" onclick="removeUploadedField('<?= htmlspecialchars($fname) ?>')">Remove</button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                    <?php elseif ($ftype === 'video'): ?>
                                        <input type="url" 
                                               name="custom_<?= htmlspecialchars($fname) ?>" 
                                               value="<?= htmlspecialchars((string)$val) ?>" 
                                               placeholder="https://www.youtube.com/watch?v=... or https://youtu.be/..." 
                                               <?= $req_attr ?>>
                                        <?php if (!empty($val)): ?>
                                            <?php $yt_preview_id = function_exists('get_youtube_id') ? get_youtube_id((string)$val) : null; ?>
                                            <?php if ($yt_preview_id): ?>
                                                <div style="margin-top: 10px; max-width: 360px;">
                                                    <div style="position:relative; padding-bottom:56.25%; height:0; overflow:hidden; border-radius:4px; border:1px solid #cbd5e1;">
                                                        <iframe src="https://www.youtube-nocookie.com/embed/<?= htmlspecialchars($yt_preview_id) ?>" 
                                                                style="position:absolute; top:0; left:0; width:100%; height:100%; border:0;" 
                                                                allowfullscreen 
                                                                loading="lazy">
                                                        </iframe>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                    <?php elseif ($ftype === 'textarea'): ?>
                                        <textarea name="custom_<?= htmlspecialchars($fname) ?>" rows="4" <?= $req_attr ?>><?= htmlspecialchars((string)$val) ?></textarea>
                                    <?php elseif ($ftype === 'number'): ?>
                                        <input type="number" step="any" name="custom_<?= htmlspecialchars($fname) ?>" value="<?= htmlspecialchars((string)$val) ?>" <?= $req_attr ?>>
                                    <?php elseif ($ftype === 'date'): ?>
                                        <input type="date" name="custom_<?= htmlspecialchars($fname) ?>" value="<?= htmlspecialchars((string)$val) ?>" <?= $req_attr ?>>
                                    <?php elseif ($ftype === 'boolean'): ?>
                                        <label style="font-weight: normal; display: inline-flex; align-items: center; gap: 8px; margin-top: 4px;">
                                            <input type="checkbox" name="custom_<?= htmlspecialchars($fname) ?>" value="1" <?= !empty($val) ? 'checked' : '' ?>>
                                            Enable / Yes
                                        </label>
                                    <?php elseif ($ftype === 'select'): ?>
                                        <select name="custom_<?= htmlspecialchars($fname) ?>" <?= $req_attr ?>>
                                            <option value="">-- Select an option --</option>
                                            <?php foreach (($field['options'] ?? []) as $opt_k => $opt_v): ?>
                                                <option value="<?= htmlspecialchars((string)$opt_k) ?>" <?= ((string)$val === (string)$opt_k) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars((string)$opt_v) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="text" name="custom_<?= htmlspecialchars($fname) ?>" value="<?= htmlspecialchars((string)$val) ?>" <?= $req_attr ?>>
                                    <?php endif; ?>

                                    <?php if (!empty($field['help_text'])): ?>
                                        <small style="color: #64748b;"><?= htmlspecialchars($field['help_text']) ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <!-- Real-Time SEO Integration (Yoast-style Scoring & Snippet Preview) -->
                        <div class="form-row" style="margin-top: 24px; padding-top: 18px; border-top: 1px solid #e2e8f0;">
                            <div style="background: #ffffff; border: 1px solid #cbd5e1; border-top: 4px solid #10b981; border-radius: 6px; padding: 20px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                                    <h3 style="margin: 0; color: #0b2545; font-size: 1.1rem; display: flex; align-items: center; gap: 8px;">
                                        Real-Time SEO Integration
                                    </h3>
                                    <div id="seo-score-badge" class="badge" style="background: #64748b; color: #ffffff; padding: 5px 12px; font-size: 0.86rem; border-radius: 12px; font-weight: 700;">
                                        SEO Score: <span id="seo-score-val">--</span>/100
                                    </div>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 320px; gap: 20px; align-items: start;">
                                    <div>
                                        <div class="form-row" style="margin-bottom: 14px;">
                                            <label style="display: block; font-weight: 600; font-size: 0.86rem; margin-bottom: 4px;">Focus Keyphrase</label>
                                            <input type="text" name="seo_focus_keyword" id="seo_focus_keyword" value="<?= htmlspecialchars($node['seo_focus_keyword'] ?? '') ?>" placeholder="e.g. environmental remediation" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.9rem;">
                                            <small style="color: #64748b;">Specify the primary search term you want this content to rank for.</small>
                                        </div>

                                        <div class="form-row" style="margin-bottom: 14px;">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                                <label style="font-weight: 600; font-size: 0.86rem; margin: 0;">SEO Title</label>
                                                <span id="seo-title-count" style="font-size: 0.78rem; color: #64748b;">0 / 60</span>
                                            </div>
                                            <input type="text" name="seo_title" id="seo_title" value="<?= htmlspecialchars($node['seo_title'] ?? '') ?>" placeholder="Leave blank to use node title" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.9rem;">
                                        </div>

                                        <div class="form-row" style="margin-bottom: 0;">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                                <label style="font-weight: 600; font-size: 0.86rem; margin: 0;">Meta Description</label>
                                                <span id="seo-desc-count" style="font-size: 0.78rem; color: #64748b;">0 / 155</span>
                                            </div>
                                            <textarea name="seo_description" id="seo_description" rows="3" placeholder="Summary of page content for search engines..." style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.9rem; font-family: inherit;"><?= htmlspecialchars($node['seo_description'] ?? '') ?></textarea>
                                        </div>
                                    </div>

                                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
                                        <h4 style="margin: 0 0 10px 0; font-size: 0.9rem; color: #0b2545;">Google Search Preview</h4>
                                        <div style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 4px; padding: 10px; margin-bottom: 14px; font-family: Arial, sans-serif;">
                                            <div id="snippet-url" style="font-size: 0.72rem; color: #202124; margin-bottom: 2px; word-break: break-all;">
                                                <?= htmlspecialchars(base_path($node['slug'] ?? 'page-url')) ?>
                                            </div>
                                            <div id="snippet-title" style="font-size: 1.05rem; color: #1a0dab; line-height: 1.3; margin-bottom: 3px; word-break: break-word;">
                                                <?= htmlspecialchars(!empty($node['seo_title']) ? $node['seo_title'] : (!empty($node['title']) ? $node['title'] : 'Page Title Preview')) ?>
                                            </div>
                                            <div id="snippet-desc" style="font-size: 0.82rem; color: #4d5156; line-height: 1.4; word-break: break-word;">
                                                <?= htmlspecialchars(!empty($node['seo_description']) ? $node['seo_description'] : 'Enter a meta description to preview snippet text.') ?>
                                            </div>
                                        </div>

                                        <h4 style="margin: 0 0 6px 0; font-size: 0.88rem; color: #0b2545;">SEO Analysis & Suggestions</h4>
                                        <ul id="seo-checks-list" style="margin: 0; padding-left: 16px; font-size: 0.8rem; color: #334155; line-height: 1.45;">
                                            <li>Set a focus keyphrase and content to run analysis.</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
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

                        <!-- Taxonomy & Tags (Autocomplete Deluxe Input Widget) -->
                        <div class="sidebar-card">
                            <h3>Keywords & Tags</h3>
                            <div class="autocomplete-deluxe-wrapper" id="taxonomy_deluxe_wrapper">
                                <div class="autocomplete-deluxe-container" id="taxonomy_deluxe_container" onclick="focusTaxonomyInput()">
                                    <div class="autocomplete-deluxe-items" id="taxonomy_deluxe_items">
                                        <?php if (!empty($node['terms'])): ?>
                                            <?php foreach ($node['terms'] as $term_item): ?>
                                                <span class="autocomplete-deluxe-item" data-id="<?= (int)$term_item['id'] ?>" data-name="<?= htmlspecialchars($term_item['name']) ?>">
                                                    <span class="item-label"><?= htmlspecialchars($term_item['name']) ?></span>
                                                    <span class="item-remove" onclick="removeDeluxeItem(this, event)">&times;</span>
                                                    <input type="hidden" name="terms[]" value="<?= (int)$term_item['id'] ?>">
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <input type="text" id="taxonomy_deluxe_input" class="autocomplete-deluxe-search" placeholder="Type term name..." autocomplete="off">
                                </div>
                                <div class="autocomplete-deluxe-dropdown" id="taxonomy_deluxe_dropdown" style="display: none;"></div>
                            </div>
                            <small style="color: #64748b; font-size: 0.78rem; margin-top: 6px; display: block;">
                                Search existing categories or type a new tag and press Enter or comma.
                            </small>
                        </div>

                        <!-- Media / Asset Drawer -->
                        <div class="sidebar-card">
                            <h3>Media / Asset Drawer</h3>
                            <div class="media-drawer-box" id="mediaDrawerList">
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
                                                        onclick="insertToEditor('<a href=\'<?= $mf['url'] ?>\' class=\'btn-download\'><?= htmlspecialchars($mf['name']) ?></a>')">Link</button>
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
                    <a href="<?= !empty($node['slug']) ? htmlspecialchars(base_path($node['slug'])) : base_path('admin/nodes.php') ?>" class="btn-secondary" style="margin-left:auto;">
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
        const typePattern = <?= json_encode($content_type['url_pattern'] ?? '[node:title]') ?>;
        const typeMachineName = <?= json_encode($content_type['type']) ?>;
        const csrfTokenVal = <?= json_encode(get_csrf_token()) ?>;
        const allTaxonomyTerms = <?= json_encode($all_terms) ?>;

        /* --- Autocomplete Deluxe Engine --- */
        let highlightedIndex = -1;

        function getSelectedTermIds() {
            const ids = [];
            document.querySelectorAll('#taxonomy_deluxe_items .autocomplete-deluxe-item').forEach(el => {
                ids.push(String(el.dataset.id));
            });
            return ids;
        }

        function getSelectedTermNames() {
            const names = [];
            document.querySelectorAll('#taxonomy_deluxe_items .autocomplete-deluxe-item').forEach(el => {
                names.push(el.dataset.name.toLowerCase());
            });
            return names;
        }

        function focusTaxonomyInput() {
            const input = document.getElementById('taxonomy_deluxe_input');
            if (input) input.focus();
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function renderDeluxeDropdown(query = '') {
            const dropdown = document.getElementById('taxonomy_deluxe_dropdown');
            if (!dropdown) return;

            const cleanQuery = query.trim().toLowerCase();
            const selectedIds = getSelectedTermIds();
            const selectedNames = getSelectedTermNames();

            // Filter available terms
            const matches = allTaxonomyTerms.filter(t => {
                if (selectedIds.includes(String(t.id))) return false;
                if (!cleanQuery) return true;
                return t.name.toLowerCase().includes(cleanQuery);
            });

            dropdown.innerHTML = '';
            highlightedIndex = -1;

            if (matches.length === 0 && !cleanQuery) {
                dropdown.style.display = 'none';
                return;
            }

            matches.slice(0, 10).forEach((t, idx) => {
                const opt = document.createElement('div');
                opt.className = 'autocomplete-deluxe-option';
                opt.dataset.index = idx;
                opt.innerHTML = `
                    <span class="option-name">${escapeHtml(t.name)}</span>
                    ${t.vocabulary_name ? `<span class="vocab-badge">${escapeHtml(t.vocabulary_name)}</span>` : ''}
                `;
                opt.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    addDeluxeItem(t.id, t.name);
                });
                dropdown.appendChild(opt);
            });

            // If user typed something and it's not already added or exact-matched, offer to create/add
            if (cleanQuery && !selectedNames.includes(cleanQuery)) {
                const hasExact = matches.some(m => m.name.toLowerCase() === cleanQuery);
                if (!hasExact) {
                    const createOpt = document.createElement('div');
                    createOpt.className = 'autocomplete-deluxe-option create-new';
                    createOpt.dataset.index = matches.length;
                    createOpt.innerHTML = `<em>+ Add "<strong>${escapeHtml(query.trim())}</strong>"</em>`;
                    createOpt.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        addDeluxeItem(query.trim(), query.trim());
                    });
                    dropdown.appendChild(createOpt);
                }
            }

            if (dropdown.children.length > 0) {
                dropdown.style.display = 'block';
            } else {
                dropdown.style.display = 'none';
            }
        }

        function addDeluxeItem(id, name) {
            const container = document.getElementById('taxonomy_deluxe_items');
            const input = document.getElementById('taxonomy_deluxe_input');
            const dropdown = document.getElementById('taxonomy_deluxe_dropdown');

            const item = document.createElement('span');
            item.className = 'autocomplete-deluxe-item';
            item.dataset.id = id;
            item.dataset.name = name;
            item.innerHTML = `
                <span class="item-label">${escapeHtml(name)}</span>
                <span class="item-remove" onclick="removeDeluxeItem(this, event)">&times;</span>
                <input type="hidden" name="terms[]" value="${escapeHtml(String(id))}">
            `;

            container.appendChild(item);
            input.value = '';
            dropdown.style.display = 'none';
            input.focus();
        }

        function removeDeluxeItem(btn, event) {
            if (event) event.stopPropagation();
            const item = btn.closest('.autocomplete-deluxe-item');
            if (item) item.remove();
            focusTaxonomyInput();
        }

        function updateHighlight(options) {
            options.forEach((opt, idx) => {
                if (idx === highlightedIndex) {
                    opt.classList.add('active');
                    opt.scrollIntoView({ block: 'nearest' });
                } else {
                    opt.classList.remove('active');
                }
            });
        }

        function handleAutoSlugToggle(isChecked) {
            document.getElementById('box_auto_preview').style.display = isChecked ? 'block' : 'none';
            document.getElementById('box_custom_slug').style.display = isChecked ? 'none' : 'block';
            if (isChecked) {
                updateDynamicPreview();
            }
        }

        function slugify(text) {
            return text.toString().toLowerCase().trim()
                .replace(/[^a-z0-9\s_\-]/g, '')
                .replace(/[\s_]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }

        function updateDynamicPreview() {
            const titleInput = document.querySelector('input[name="title"]');
            const rawTitle = titleInput ? titleInput.value.trim() : '';
            const cleanTitle = rawTitle ? slugify(rawTitle) : 'title-preview';

            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');

            let preview = typePattern
                .replace(/\[node:title\]/g, cleanTitle)
                .replace(/\[node:type\]/g, typeMachineName)
                .replace(/\[node:created:Y\]/g, year)
                .replace(/\[node:created:m\]/g, month)
                .replace(/\[current-date:Y\]/g, year)
                .replace(/\[term:slug\]/g, 'general');

            preview = preview.split('/').map(seg => slugify(seg)).filter(Boolean).join('/');
            const previewEl = document.getElementById('preview_slug_text');
            if (previewEl) {
                previewEl.innerText = preview || 'title-preview';
            }
        }

        function insertToken(token) {
            const input = document.getElementById('slug_custom_input');
            if (!input) return;
            const start = input.selectionStart || input.value.length;
            const end = input.selectionEnd || input.value.length;
            const val = input.value;
            input.value = val.substring(0, start) + token + val.substring(end);
            input.focus();
            input.selectionStart = input.selectionEnd = start + token.length;
        }

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
            if (typeof updateSeoAnalysis === 'function') {
                updateSeoAnalysis();
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

        /* --- AJAX Field Upload Engine & Feedback --- */
        function handleFileSelected(fieldName) {
            const fileInput = document.getElementById('file_input_' + fieldName);
            const uploadBtn = document.getElementById('btn_upload_' + fieldName);
            if (fileInput && fileInput.files.length > 0 && uploadBtn) {
                uploadBtn.style.boxShadow = '0 0 0 2px rgba(197, 155, 39, 0.5)';
            }
        }

        function triggerAjaxUpload(fieldName, fieldType, uploadDir = 'files') {
            const fileInput = document.getElementById('file_input_' + fieldName);
            const uploadBtn = document.getElementById('btn_upload_' + fieldName);
            const hiddenVal = document.getElementById('val_' + fieldName);

            if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                alert('Please choose a file to upload first.');
                fileInput.focus();
                return;
            }

            const file = fileInput.files[0];
            const formData = new FormData();
            formData.append('file', file);
            formData.append('csrf_token', csrfTokenVal);
            formData.append('upload_dir', uploadDir);

            const origContent = uploadBtn.innerHTML;
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = 'Uploading...';

            fetch('<?= base_path("admin/node_edit.php?action=ajax_upload") ?>', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = origContent;
                uploadBtn.style.boxShadow = 'none';

                if (data.success) {
                    hiddenVal.value = data.url;

                    // Clear file input so form submission won't re-upload duplicate file
                    fileInput.value = '';

                    const isImage = data.is_img || fieldType === 'image';
                    const previewThumb = isImage 
                        ? `<img src="${data.url}" alt="" style="max-height: 48px; max-width: 70px; object-fit: cover; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff;">`
                        : `<span style="font-size: 1.25rem;">📄</span>`;

                    const feedbackContainer = document.getElementById('feedback_' + fieldName);
                    feedbackContainer.style.display = 'block';
                    feedbackContainer.innerHTML = `
                        <div class="upload-success-badge">
                            ${previewThumb}
                            <div style="flex: 1; min-width: 0;">
                                <div style="color: #065f46; font-weight: 700; font-size: 0.85rem;">
                                    ✓ ${isImage ? 'Image' : 'Document'} uploaded successfully!
                                </div>
                                <div style="font-size: 0.82rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <a href="${data.url}" target="_blank" style="color: #0b2545; font-weight: 600; text-decoration: underline;">
                                        ${data.filename}
                                    </a>
                                </div>
                            </div>
                            <button type="button" class="btn-secondary" style="font-size: 0.75rem; padding: 3px 8px; color: #b91c1c;" onclick="removeUploadedField('${fieldName}')">Remove</button>
                        </div>
                    `;

                    fileInput.removeAttribute('required');
                    appendMediaDrawerPill(data.filename, data.url, isImage);
                } else {
                    alert('Upload failed: ' + (data.error || 'Server error occurred.'));
                }
            })
            .catch(err => {
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = origContent;
                alert('Upload network error: ' + err.message);
            });
        }

        function removeUploadedField(fieldName) {
            confirmModal('Remove this uploaded file?', function() {
                const hiddenVal = document.getElementById('val_' + fieldName);
                const fileInput = document.getElementById('file_input_' + fieldName);
                const feedbackContainer = document.getElementById('feedback_' + fieldName);

                if (hiddenVal) hiddenVal.value = '';
                if (fileInput) fileInput.value = '';
                if (feedbackContainer) {
                    feedbackContainer.innerHTML = '';
                    feedbackContainer.style.display = 'none';
                }
            }, {
                title: 'Remove File',
                confirmText: 'Remove',
                danger: true
            });
        }

        function appendMediaDrawerPill(filename, url, isImg) {
            const drawer = document.getElementById('mediaDrawerList');
            if (!drawer) return;
            const pill = document.createElement('div');
            pill.className = 'media-item-pill';
            const actionBtn = isImg 
                ? `<button type="button" class="btn-secondary" style="font-size:0.75rem; padding: 2px 6px;" onclick="insertToEditor('<img src=\\'${url}\\' alt=\\'\\' style=\\'max-width:100%; height:auto;\\'>')">Img</button>`
                : `<button type="button" class="btn-secondary" style="font-size:0.75rem; padding: 2px 6px;" onclick="insertToEditor('<a href=\\'${url}\\' class=\\'btn-download\\'>${filename}</a>')">Link</button>`;

            pill.innerHTML = `
                <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width: 140px;" title="${filename}">
                    ${filename}
                </span>
                <div style="display:flex; gap:4px;">
                    ${actionBtn}
                </div>
            `;
            drawer.insertBefore(pill, drawer.firstChild);
        }

        /* --- Real-Time SEO Analyzer Engine --- */
        function updateSeoAnalysis() {
            const titleInput = document.querySelector('input[name="title"]');
            const seoTitleInput = document.getElementById('seo_title');
            const seoDescInput = document.getElementById('seo_description');
            const seoKeywordInput = document.getElementById('seo_focus_keyword');
            const slugInput = document.getElementById('slug_custom_input');
            const previewSlugText = document.getElementById('preview_slug_text');

            const snippetTitle = document.getElementById('snippet-title');
            const snippetDesc = document.getElementById('snippet-desc');
            const snippetUrl = document.getElementById('snippet-url');
            const titleCount = document.getElementById('seo-title-count');
            const descCount = document.getElementById('seo-desc-count');
            const scoreVal = document.getElementById('seo-score-val');
            const scoreBadge = document.getElementById('seo-score-badge');
            const checksList = document.getElementById('seo-checks-list');

            if (!checksList || !scoreVal) return;

            const primaryTitle = titleInput ? titleInput.value.trim() : '';
            const customSeoTitle = seoTitleInput ? seoTitleInput.value.trim() : '';
            const displayTitle = customSeoTitle !== '' ? customSeoTitle : (primaryTitle !== '' ? primaryTitle : 'Page Title Preview');
            const desc = seoDescInput ? seoDescInput.value.trim() : '';
            const keyword = seoKeywordInput ? seoKeywordInput.value.trim().toLowerCase() : '';

            let currentSlug = '';
            const autoSlugCheckbox = document.getElementById('auto_slug');
            if (autoSlugCheckbox && autoSlugCheckbox.checked && previewSlugText) {
                currentSlug = previewSlugText.innerText.trim();
            } else if (slugInput) {
                currentSlug = slugInput.value.trim();
            }

            let bodyHtml = '';
            if (window.jQuery && $('#node_body').data('trumbowyg')) {
                bodyHtml = $('#node_body').trumbowyg('html') || '';
            } else {
                const bodyElem = document.getElementById('node_body');
                bodyHtml = bodyElem ? bodyElem.value : '';
            }

            // Update snippet preview
            if (snippetTitle) snippetTitle.textContent = displayTitle;
            if (snippetDesc) {
                snippetDesc.textContent = desc !== '' ? desc : 'Enter a meta description to preview snippet text.';
            }
            if (snippetUrl && currentSlug) {
                snippetUrl.textContent = '/' + currentSlug.replace(/^\/+/, '');
            }

            // Length counters
            const tLen = displayTitle.length;
            const dLen = desc.length;

            if (titleCount) {
                titleCount.textContent = `${tLen} / 60`;
                titleCount.style.color = (tLen >= 30 && tLen <= 60) ? '#059669' : (tLen === 0 ? '#64748b' : '#d97706');
            }
            if (descCount) {
                descCount.textContent = `${dLen} / 155`;
                descCount.style.color = (dLen >= 120 && dLen <= 155) ? '#059669' : (dLen === 0 ? '#64748b' : '#d97706');
            }

            // Plain text conversion for word count and keyword search
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = bodyHtml;
            const plainText = (tempDiv.textContent || tempDiv.innerText || '').toLowerCase();
            const words = plainText.match(/\b\w+\b/g) || [];
            const wordCount = words.length;

            let score = 100;
            const checks = [];

            // 1. Focus Keyword Evaluation
            if (!keyword) {
                score -= 30;
                checks.push({ type: 'error', text: 'No focus keyphrase specified.' });
            } else {
                // In Title
                if (displayTitle.toLowerCase().includes(keyword)) {
                    checks.push({ type: 'success', text: 'Focus keyphrase appears in the SEO title.' });
                } else {
                    score -= 15;
                    checks.push({ type: 'error', text: 'Focus keyphrase does not appear in the SEO title.' });
                }

                // In Meta Description
                if (desc.toLowerCase().includes(keyword)) {
                    checks.push({ type: 'success', text: 'Focus keyphrase appears in the meta description.' });
                } else {
                    score -= 10;
                    checks.push({ type: 'warning', text: 'Focus keyphrase does not appear in the meta description.' });
                }

                // In URL Slug
                const sanitizedKwSlug = slugify(keyword);
                if (currentSlug.toLowerCase().includes(sanitizedKwSlug)) {
                    checks.push({ type: 'success', text: 'Focus keyphrase appears in the URL alias.' });
                } else {
                    score -= 5;
                    checks.push({ type: 'warning', text: 'Focus keyphrase does not appear in the URL slug.' });
                }

                // Density in Body
                if (wordCount > 0) {
                    const occurrences = (plainText.match(new RegExp(keyword.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g')) || []).length;
                    const density = ((occurrences / wordCount) * 100).toFixed(1);
                    if (occurrences === 0) {
                        score -= 10;
                        checks.push({ type: 'warning', text: 'Focus keyphrase was not found in the body text.' });
                    } else if (parseFloat(density) > 3.0) {
                        score -= 10;
                        checks.push({ type: 'warning', text: `Keyphrase density is high (${density}%). Consider reducing repetition.` });
                    } else {
                        checks.push({ type: 'success', text: `Good keyphrase density (${density}% - ${occurrences} matches).` });
                    }
                }
            }

            // 2. Title length checks
            if (tLen === 0) {
                score -= 20;
                checks.push({ type: 'error', text: 'Title is empty.' });
            } else if (tLen < 30) {
                score -= 8;
                checks.push({ type: 'warning', text: `SEO title is short (${tLen} chars). Optimal is 30-60.` });
            } else if (tLen > 65) {
                score -= 8;
                checks.push({ type: 'warning', text: `SEO title is long (${tLen} chars) and may truncate in search engines.` });
            } else {
                checks.push({ type: 'success', text: `Optimal title length (${tLen} chars).` });
            }

            // 3. Description length checks
            if (dLen === 0) {
                score -= 15;
                checks.push({ type: 'error', text: 'Meta description is missing.' });
            } else if (dLen < 120) {
                score -= 6;
                checks.push({ type: 'warning', text: `Meta description is short (${dLen} chars). Recommended: 120-155.` });
            } else if (dLen > 160) {
                score -= 6;
                checks.push({ type: 'warning', text: `Meta description exceeds recommended limit (${dLen} chars).` });
            } else {
                checks.push({ type: 'success', text: `Optimal description length (${dLen} chars).` });
            }

            // 4. Content length check
            if (wordCount < 150) {
                score -= 10;
                checks.push({ type: 'warning', text: `Body content has only ${wordCount} words. Aim for 300+ words.` });
            } else {
                checks.push({ type: 'success', text: `Good content length (${wordCount} words).` });
            }

            score = Math.max(0, Math.min(100, score));
            scoreVal.textContent = String(score);

            if (score >= 80) {
                scoreBadge.style.background = '#10b981';
            } else if (score >= 50) {
                scoreBadge.style.background = '#d97706';
            } else {
                scoreBadge.style.background = '#dc2626';
            }

            checksList.innerHTML = '';
            checks.forEach(item => {
                const li = document.createElement('li');
                li.style.color = item.type === 'error' ? '#dc2626' : (item.type === 'warning' ? '#d97706' : '#059669');
                li.style.marginBottom = '3px';
                li.textContent = item.text;
                checksList.appendChild(li);
            });
        }

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
                }).on('tbwchange', updateSeoAnalysis);
            }

            const titleElem = document.querySelector('input[name="title"]');
            if (titleElem) {
                titleElem.addEventListener('input', function() {
                    updateDynamicPreview();
                    updateSeoAnalysis();
                });
            }
            updateDynamicPreview();

            // Bind SEO inputs to live analysis
            ['seo_title', 'seo_description', 'seo_focus_keyword', 'slug_custom_input'].forEach(id => {
                const elem = document.getElementById(id);
                if (elem) {
                    elem.addEventListener('input', updateSeoAnalysis);
                }
            });

            const autoSlugEl = document.getElementById('auto_slug');
            if (autoSlugEl) {
                autoSlugEl.addEventListener('change', updateSeoAnalysis);
            }

            updateSeoAnalysis();

            // Initialize Autocomplete Deluxe Listeners
            const taxInput = document.getElementById('taxonomy_deluxe_input');
            const taxDropdown = document.getElementById('taxonomy_deluxe_dropdown');

            if (taxInput && taxDropdown) {
                taxInput.addEventListener('input', function() {
                    renderDeluxeDropdown(this.value);
                });

                taxInput.addEventListener('focus', function() {
                    renderDeluxeDropdown(this.value);
                });

                taxInput.addEventListener('keydown', function(e) {
                    const options = taxDropdown.querySelectorAll('.autocomplete-deluxe-option');

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        if (options.length === 0) return;
                        highlightedIndex = (highlightedIndex + 1) % options.length;
                        updateHighlight(options);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        if (options.length === 0) return;
                        highlightedIndex = (highlightedIndex - 1 + options.length) % options.length;
                        updateHighlight(options);
                    } else if (e.key === 'Enter' || e.key === ',') {
                        e.preventDefault();
                        if (highlightedIndex >= 0 && options[highlightedIndex]) {
                            options[highlightedIndex].dispatchEvent(new MouseEvent('mousedown'));
                        } else if (this.value.trim() !== '') {
                            if (options.length > 0) {
                                options[0].dispatchEvent(new MouseEvent('mousedown'));
                            } else {
                                addDeluxeItem(this.value.trim(), this.value.trim());
                            }
                        }
                    } else if (e.key === 'Backspace' && this.value === '') {
                        const items = document.querySelectorAll('#taxonomy_deluxe_items .autocomplete-deluxe-item');
                        if (items.length > 0) {
                            items[items.length - 1].remove();
                            renderDeluxeDropdown('');
                        }
                    } else if (e.key === 'Escape') {
                        taxDropdown.style.display = 'none';
                    }
                });

                document.addEventListener('click', function(e) {
                    const wrap = document.getElementById('taxonomy_deluxe_wrapper');
                    if (wrap && !wrap.contains(e.target)) {
                        taxDropdown.style.display = 'none';
                    }
                });
            }
        });
    </script>
</body>
</html>
