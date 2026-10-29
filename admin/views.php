<?php declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/views.php';

require_permission('administer_site');

$db = get_db();
$action = $_GET['action'] ?? 'list';
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$active_view = $edit_id > 0 ? get_view($edit_id) : null;

// AJAX Live Preview Endpoint
if ($action === 'ajax_preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $raw_config = json_decode($_POST['config_payload'] ?? '{}', true) ?: [];
    $mock_view = [
        'name'          => 'Live Query Preview',
        'machine_name'  => $_POST['machine_name'] ?? 'preview_view',
        'status'        => 1,
        'configuration' => $raw_config
    ];

    $query_data = execute_view_query($mock_view, 'default');
    
    echo '<div style="margin-bottom: 12px; background: #0f172a; color: #38bdf8; padding: 12px; border-radius: 4px; font-family: monospace; font-size: 0.8rem; overflow-x: auto;">';
    echo '<strong>Compiled SQL:</strong><br>' . htmlspecialchars($query_data['sql']) . '<br>';
    echo '<strong>Parameters:</strong> ' . htmlspecialchars(json_encode($query_data['params']));
    echo '</div>';
    
    echo render_view($mock_view, 'default');
    exit;
}

// AJAX Variables Endpoint
if ($action === 'ajax_get_vars') {
    $base_table = $_GET['base_table'] ?? 'nodes';
    $content_type = $_GET['content_type'] ?? 'all';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(get_available_views_variables($base_table, $content_type));
    exit;
}

// Handle Form Submission: Save View
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_view') {
    validate_csrf();
    $id           = (int)($_POST['id'] ?? 0);
    $name         = trim($_POST['name'] ?? '');
    $machine_name = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['machine_name'] ?? '')));
    $description  = trim($_POST['description'] ?? '');
    $status       = isset($_POST['status']) ? 1 : 0;
    $display_type = in_array($_POST['display_type'] ?? '', ['page', 'block'], true) ? $_POST['display_type'] : 'block';
    $path         = strtolower(trim(preg_replace('/[^a-z0-9_\-\/]/i', '', str_replace(' ', '-', $_POST['path'] ?? '')), '/'));

    $config_json = $_POST['config_payload'] ?? '{}';
    $configuration = json_decode($config_json, true) ?: [];
    $configuration['display_type'] = $display_type;
    $configuration['path'] = $path;
    $base_table = $configuration['base_table'] ?? 'nodes';

    if ($name === '' || $machine_name === '') {
        set_flash('error', 'View title and machine identifier are required.');
    } elseif ($display_type === 'page' && $path === '') {
        set_flash('error', 'A URL alias slug is required when deploying this view as a Page.');
    } else {
        if ($id > 0) {
            $stmt = $db->prepare('
                UPDATE views 
                SET name = ?, machine_name = ?, description = ?, base_table = ?, display_type = ?, path = ?, configuration = ?, status = ? 
                WHERE id = ?
            ');
            $stmt->execute([$name, $machine_name, $description, $base_table, $display_type, $path, json_encode($configuration), $status, $id]);
            $saved_id = $id;
        } else {
            $stmt = $db->prepare('
                INSERT INTO views (name, machine_name, description, base_table, display_type, path, configuration, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$name, $machine_name, $description, $base_table, $display_type, $path, json_encode($configuration), $status]);
            $saved_id = (int)$db->lastInsertId();
        }

        // Synchronize with Blocks table
        if ($display_type === 'block') {
            $existing_block_id = $db->query("SELECT block_id FROM views WHERE id = {$saved_id}")->fetchColumn();
            $block_body = "[view:{$machine_name}]";
            if ($existing_block_id) {
                $b_stmt = $db->prepare('UPDATE blocks SET title = ?, body = ?, status = ? WHERE id = ?');
                $b_stmt->execute([$name, $block_body, $status, $existing_block_id]);
            } else {
                $b_stmt = $db->prepare('INSERT INTO blocks (title, region, body, weight, status, visibility_pages, visibility_mode) VALUES (?, "sidebar", ?, 0, ?, "", "show_only")');
                $b_stmt->execute([$name, $block_body, $status]);
                $new_bid = (int)$db->lastInsertId();
                $db->prepare('UPDATE views SET block_id = ? WHERE id = ?')->execute([$new_bid, $saved_id]);
            }
        }

        set_flash('success', "View '{$name}' saved successfully.");
        header('Location: ' . base_path("admin/views.php?action=edit&id={$saved_id}"));
        exit;
    }
}

// Handle Delete View
if ($action === 'delete' && $edit_id > 0) {
    validate_csrf();
    $v_record = get_view($edit_id);
    if ($v_record && !empty($v_record['block_id'])) {
        $db->prepare('DELETE FROM blocks WHERE id = ?')->execute([$v_record['block_id']]);
    }
    $db->prepare('DELETE FROM views WHERE id = ?')->execute([$edit_id]);
    set_flash('success', 'View removed.');
    header('Location: ' . base_path('admin/views.php'));
    exit;
}

$all_views = get_all_views();
$content_types = get_content_types();

// Fetch taxonomy terms for taxonomy criteria filter selector
$all_terms = [];
try {
    $all_terms = $db->query('SELECT t.id, t.name, v.name as vocab_name FROM taxonomy_terms t LEFT JOIN taxonomy_vocabularies v ON v.id = t.vocabulary_id ORDER BY v.name, t.name')->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Views Builder - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    <style>
        .top-action-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 12px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .config-section { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 18px; margin-bottom: 18px; }
        .config-section h3 { margin: 0 0 12px 0; font-size: 1rem; color: #0b2545; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; }
        .wizard-selector-row {
            display: flex;
            align-items: center;
            gap: 15px;
            background: #f8fafc;
            padding: 14px 18px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .field-row-item { display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 8px 12px; margin-bottom: 8px; }
        .tag-pill { font-size: 0.72rem; padding: 2px 6px; background: #e2e8f0; border-radius: 3px; color: #475569; font-family: monospace; }
        .token-help { font-size: 0.78rem; color: #64748b; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; padding: 8px 10px; margin-top: 6px; }
        .live-preview-box { background: #ffffff; border: 1px dashed #94a3b8; border-radius: 6px; padding: 20px; min-height: 120px; margin-top: 15px; }
        
        /* Drupal Split Filter Layout */
        .drupal-filter-box {
            display: flex;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            overflow: hidden;
            background: #ffffff;
            margin-top: 14px;
        }
        .drupal-filter-left {
            flex: 1;
            padding: 16px;
            border-right: 1px solid #e2e8f0;
        }
        .drupal-filter-divider {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 10px;
            background: #f8fafc;
            color: #94a3b8;
            font-weight: 700;
            border-right: 1px solid #e2e8f0;
        }
        .drupal-filter-right {
            flex: 1.5;
            padding: 16px;
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>
        <main class="admin-main" style="max-width: 1280px;">

            <?php if ($action === 'create' || ($action === 'edit' && $active_view)): ?>
                <?php 
                    $is_edit = ($action === 'edit' && $active_view);
                    $config = $active_view['configuration'] ?? [];
                    $base_table = $config['base_table'] ?? 'nodes';
                    $content_type = $config['content_type'] ?? 'all';
                    $disp = $config['displays']['default'] ?? [
                        'format' => 'cards',
                        'grid_columns' => 3,
                        'fields' => [
                            ['name' => 'title', 'label' => 'Title', 'show_label' => 0, 'link_to_node' => 1, 'formatter' => 'plain_text'],
                            ['name' => 'created_at', 'label' => 'Date', 'show_label' => 1, 'formatter' => 'date', 'date_format' => 'M j, Y']
                        ],
                        'filters' => [],
                        'sorts' => [['field' => 'created_at', 'order' => 'DESC']],
                        'items_per_page' => 10,
                        'use_pager' => 1,
                        'exposed_filters' => []
                    ];
                    $cur_display_type = $active_view['display_type'] ?? 'block';
                    $cur_path = $active_view['path'] ?? '';
                    $cur_status = (!$is_edit || !empty($active_view['status'])) ? 1 : 0;
                ?>

                <div style="font-size:0.84rem; color:#64748b; margin-bottom:8px;">
                    <a href="<?= base_path('admin/views.php') ?>" style="color:#0b2545; text-decoration:none;">&laquo; All Views</a>
                    <?php if ($is_edit): ?>
                        &raquo; <span><?= htmlspecialchars($active_view['name']) ?></span>
                    <?php endif; ?>
                </div>

                <form method="POST" id="viewsMasterForm">
                    <input type="hidden" name="csrf_token" id="views_csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="save_view">
                    <input type="hidden" name="id" value="<?= $is_edit ? $active_view['id'] : 0 ?>">
                    <input type="hidden" name="config_payload" id="config_payload" value="">

                    <!-- TOP ACTION BAR: PUBLISH CHECKBOX & SAVE BUTTON AT TOP -->
                    <div class="top-action-bar">
                        <div style="display:flex; align-items:center; gap:20px;">
                            <h2 style="margin:0; font-size:1.25rem; color:#0b2545;">
                                <?= $is_edit ? 'Edit View: ' . htmlspecialchars($active_view['name']) : 'Create View' ?>
                            </h2>
                            <label style="font-weight:700; display:flex; align-items:center; gap:6px; cursor:pointer; color:#0b2545;">
                                <input type="checkbox" name="status" value="1" <?= $cur_status ? 'checked' : '' ?>>
                                Published / Active
                            </label>
                        </div>
                        <div style="display:flex; gap:10px;">
                            <a href="<?= base_path('admin/views.php') ?>" class="btn-secondary" style="padding:8px 16px;">Cancel</a>
                            <button type="submit" class="btn-primary" style="padding:8px 24px;" onclick="syncState()">Save View</button>
                        </div>
                    </div>

                    <?php render_flash_messages(); ?>

                    <!-- VIEW META SETTINGS -->
                    <section class="card" style="margin-bottom: 18px;">
                        <div style="display:grid; grid-template-columns: 1.5fr 1.5fr 1fr 1.5fr; gap:16px;">
                            <div class="form-row">
                                <label>View Title *</label>
                                <input type="text" name="name" id="view_name" required placeholder="e.g. Press Releases" value="<?= htmlspecialchars($is_edit ? $active_view['name'] : '') ?>" onkeyup="autoMachineName(this.value)">
                            </div>
                            <div class="form-row">
                                <label>Machine Identifier *</label>
                                <input type="text" name="machine_name" id="view_machine_name" required placeholder="press_releases" value="<?= htmlspecialchars($is_edit ? $active_view['machine_name'] : '') ?>">
                            </div>
                            <div class="form-row">
                                <label>Display Target</label>
                                <select name="display_type" id="view_display_type" onchange="toggleDeploymentUI(this.value)">
                                    <option value="block" <?= $cur_display_type === 'block' ? 'selected' : '' ?>>Block (Region Widget)</option>
                                    <option value="page" <?= $cur_display_type === 'page' ? 'selected' : '' ?>>Page (URL Route)</option>
                                </select>
                            </div>
                            <div class="form-row" id="view_path_row" style="<?= $cur_display_type === 'page' ? '' : 'display:none;' ?>">
                                <label>Page URL Alias (Slug) *</label>
                                <input type="text" name="path" id="view_path" placeholder="news/releases" value="<?= htmlspecialchars($cur_path) ?>">
                            </div>
                        </div>
                    </section>

                    <!-- DRUPAL-STYLE BASE ENTITY & TYPE SELECTOR (SHOW OF TYPE SORTED BY) -->
                    <div class="wizard-selector-row">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <label style="font-weight:700; color:#0b2545;">Show:</label>
                            <select id="wiz_base_table" onchange="onBaseTableChange(this.value)" style="padding:6px 12px; font-weight:600; border-radius:4px; border:1px solid #cbd5e1;">
                                <option value="nodes" <?= $base_table === 'nodes' ? 'selected' : '' ?>>Content</option>
                                <option value="users" <?= $base_table === 'users' ? 'selected' : '' ?>>Users</option>
                                <option value="taxonomy_terms" <?= $base_table === 'taxonomy_terms' ? 'selected' : '' ?>>Taxonomy terms</option>
                            </select>
                        </div>

                        <div id="wiz_type_container" style="display:<?= $base_table === 'nodes' ? 'flex' : 'none' ?>; align-items:center; gap:8px;">
                            <label style="font-weight:700; color:#0b2545;">of type:</label>
                            <select id="wiz_content_type" onchange="onContentTypeChange(this.value)" style="padding:6px 12px; border-radius:4px; border:1px solid #cbd5e1;">
                                <option value="all">All</option>
                                <?php foreach ($content_types as $ct): ?>
                                    <option value="<?= htmlspecialchars($ct['type']) ?>" <?= $content_type === $ct['type'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($ct['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="display:flex; align-items:center; gap:8px;">
                            <label style="font-weight:700; color:#0b2545;">sorted by:</label>
                            <select id="wiz_default_sort" onchange="onDefaultSortChange(this.value)" style="padding:6px 12px; border-radius:4px; border:1px solid #cbd5e1;">
                                <option value="newest">Newest first</option>
                                <option value="oldest">Oldest first</option>
                                <option value="title_asc">Title A-Z</option>
                                <option value="title_desc">Title Z-A</option>
                                <option value="unsorted">Unsorted</option>
                            </select>
                        </div>
                    </div>

                    <!-- 3-COLUMN CONFIGURATION PANELS -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; align-items: start;">
                        
                        <!-- COLUMN 1: FORMAT & FIELDS -->
                        <div>
                            <div class="config-section">
                                <h3>Format & Style</h3>
                                <div class="form-row">
                                    <label>Style Plugin</label>
                                    <select id="cfg_format" onchange="syncState()">
                                        <option value="cards" <?= ($disp['format'] ?? '') === 'cards' ? 'selected' : '' ?>>Grid Cards</option>
                                        <option value="table" <?= ($disp['format'] ?? '') === 'table' ? 'selected' : '' ?>>HTML Table (Sortable Headers)</option>
                                        <option value="unformatted" <?= ($disp['format'] ?? '') === 'unformatted' ? 'selected' : '' ?>>Unformatted Rows</option>
                                        <option value="list" <?= ($disp['format'] ?? '') === 'list' ? 'selected' : '' ?>>HTML List (&lt;ul&gt;)</option>
                                        <option value="json" <?= ($disp['format'] ?? '') === 'json' ? 'selected' : '' ?>>Raw JSON / REST Feed</option>
                                    </select>
                                </div>
                                <div class="form-row" id="cfg_grid_cols_row">
                                    <label>Grid Columns</label>
                                    <select id="cfg_grid_columns" onchange="syncState()">
                                        <option value="1" <?= ($disp['grid_columns'] ?? 3) == 1 ? 'selected' : '' ?>>1 Column</option>
                                        <option value="2" <?= ($disp['grid_columns'] ?? 3) == 2 ? 'selected' : '' ?>>2 Columns</option>
                                        <option value="3" <?= ($disp['grid_columns'] ?? 3) == 3 ? 'selected' : '' ?>>3 Columns</option>
                                        <option value="4" <?= ($disp['grid_columns'] ?? 3) == 4 ? 'selected' : '' ?>>4 Columns</option>
                                    </select>
                                </div>
                            </div>

                            <div class="config-section">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                    <h3 style="margin:0; border:none; padding:0;">Fields</h3>
                                    <button type="button" class="btn-secondary" style="font-size:0.75rem; padding:3px 8px;" onclick="openAddFieldModal()">+ Add Field</button>
                                </div>
                                <div id="fields_container_list">
                                    <!-- Rendered dynamically via JS -->
                                </div>
                            </div>
                        </div>

                        <!-- COLUMN 2: FILTERS & SORTS -->
                        <div>
                            <div class="config-section">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                    <h3 style="margin:0; border:none; padding:0;">Filter Criteria</h3>
                                    <button type="button" class="btn-secondary" style="font-size:0.75rem; padding:3px 8px;" onclick="openAddFilterModal()">+ Add Filter</button>
                                </div>
                                <div id="filters_container_list">
                                    <!-- Rendered dynamically via JS -->
                                </div>
                            </div>

                            <div class="config-section">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                    <h3 style="margin:0; border:none; padding:0;">Sort Criteria</h3>
                                    <button type="button" class="btn-secondary" style="font-size:0.75rem; padding:3px 8px;" onclick="openAddSortModal()">+ Add Sort</button>
                                </div>
                                <div id="sorts_container_list">
                                    <!-- Rendered dynamically via JS -->
                                </div>
                            </div>
                        </div>

                        <!-- COLUMN 3: PAGER & HEADER -->
                        <div>
                            <div class="config-section">
                                <h3>Pagination</h3>
                                <div class="form-row">
                                    <label>Items Per Page (0 = Unlimited)</label>
                                    <input type="number" id="cfg_items_per_page" value="<?= (int)($disp['items_per_page'] ?? 10) ?>" min="0" onchange="syncState()">
                                </div>
                                <div class="form-row">
                                    <label style="font-weight:normal; display:flex; align-items:center; gap:6px; cursor:pointer;">
                                        <input type="checkbox" id="cfg_use_pager" onchange="syncState()" <?= !empty($disp['use_pager']) ? 'checked' : '' ?>> Enable Page Number Pager
                                    </label>
                                </div>
                            </div>

                            <div class="config-section">
                                <h3>Heading & Empty State</h3>
                                <div class="form-row">
                                    <label>Section Title</label>
                                    <input type="text" id="cfg_display_title" value="<?= htmlspecialchars($disp['display_title'] ?? '') ?>" placeholder="Optional Title" onchange="syncState()">
                                </div>
                                <div class="form-row">
                                    <label>Empty Results Message</label>
                                    <input type="text" id="cfg_empty_text" value="<?= htmlspecialchars($disp['empty_text'] ?? 'No records found.') ?>" onchange="syncState()">
                                </div>
                            </div>
                        </div>

                    </div>
                </form>

                <!-- LIVE PREVIEW SECTION -->
                <section class="card" style="margin-top:25px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <h3 style="color:var(--frnp-navy); margin:0;">Live Query & Output Inspector</h3>
                            <span style="font-size:0.8rem; color:#64748b;">Renders matching database records with live formatters, filters, and styling.</span>
                        </div>
                        <button type="button" class="btn-secondary" onclick="fetchLivePreview()" style="font-size:0.8rem; padding:6px 14px;">↻ Refresh Preview</button>
                    </div>
                    <div id="livePreviewContainer" class="live-preview-box">
                        <em style="color:#64748b;">Click "Refresh Preview" to generate live results.</em>
                    </div>
                </section>

                <!-- MODAL: FIELD CONFIGURATION -->
                <div id="fieldEditModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
                    <div style="background:#ffffff; width:560px; border-radius:6px; padding:24px; max-height:90vh; overflow-y:auto; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:10px; margin-bottom:16px;">
                            <h3 id="modalFieldTitle" style="margin:0; color:#0b2545;">Configure field</h3>
                            <button type="button" onclick="closeModal('fieldEditModal')" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
                        </div>
                        
                        <input type="hidden" id="modal_field_index" value="">
                        
                        <div class="form-row">
                            <label style="font-weight:700; display:flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" id="modal_show_label" onchange="toggleLabelInput(this.checked)"> Create a label
                            </label>
                            <div id="modal_label_wrapper" style="margin-top:8px;">
                                <input type="text" id="modal_field_label" placeholder="Label text">
                            </div>
                        </div>

                        <div class="form-row">
                            <label style="font-weight:normal; display:flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" id="modal_exclude_display"> Exclude from display
                            </label>
                            <small style="color:#64748b; display:block; margin-top:2px;">Enable to load this field as hidden (useful for tokens in another field).</small>
                        </div>

                        <div class="form-row">
                            <label style="font-weight:700;">Formatter</label>
                            <select id="modal_field_formatter" onchange="onFormatterChange(this.value)">
                                <option value="default">Default</option>
                                <option value="html">Rendered HTML (Full HTML markup)</option>
                                <option value="plain_text">Plain text (Strip HTML & encode)</option>
                                <option value="trimmed">Trimmed text</option>
                                <option value="date">Date / Time</option>
                                <option value="image">Image Tag (&lt;img&gt;)</option>
                                <option value="link">Link / Anchor</option>
                            </select>
                        </div>

                        <div class="form-row">
                            <label style="font-weight:normal; display:flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" id="modal_strip_styles"> Strip styles (Remove inline style="..." attributes)
                            </label>
                        </div>

                        <div class="form-row" style="margin-top: 15px;">
                            <label style="font-weight: 600; color: #0b2545;">Wrapper HTML Tag</label>
                            <select name="modal_field_wrapper_tag" id="modal_field_wrapper_tag" class="form-control" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                <option value="p">&lt;p&gt; (Paragraph - Default)</option>
                                <option value="div">&lt;div&gt; (Container)</option>
                                <option value="span">&lt;span&gt; (Inline)</option>
                                <option value="h3">&lt;h3&gt; (Heading 3)</option>
                                <option value="h4">&lt;h4&gt; (Heading 4)</option>
                                <option value="strong">&lt;strong&gt; (Bold)</option>
                                <option value="small">&lt;small&gt; (Small text)</option>
                            </select>
                            <small style="color: #64748b; font-size: 0.8rem;">The HTML element used to wrap the field output.</small>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px;">
                            <div class="form-row">
                                <label style="font-weight: 600; color: #0b2545;">CSS Class</label>
                                <input type="text" name="modal_field_custom_class" id="modal_field_custom_class" 
                                       placeholder="e.g. source-url" 
                                       class="form-control" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                <small style="color: #64748b; font-size: 0.8rem;">Renders as <code>class="source-url"</code>.</small>
                            </div>

                            <div class="form-row">
                                <label style="font-weight: 600; color: #0b2545;">HTML ID</label>
                                <input type="text" name="modal_field_custom_id" id="modal_field_custom_id" 
                                       placeholder="e.g. link" 
                                       class="form-control" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                <small style="color: #64748b; font-size: 0.8rem;">Renders as <code>id="link"</code>.</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <label style="font-weight:normal; display:flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" id="modal_link_to_node"> Link to the original content item
                            </label>
                        </div>

                        <div class="form-row" id="modal_date_format_row" style="display:none;">
                            <label>Date Format</label>
                            <input type="text" id="modal_date_format" placeholder="e.g. M j, Y or Y-m-d H:i">
                        </div>

                        <div class="form-row" id="modal_trim_length_row" style="display:none;">
                            <label>Trim Length (characters)</label>
                            <input type="number" id="modal_trim_length" min="1" value="150">
                        </div>

                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; padding:12px; margin:14px 0;">
                            <label style="font-weight:700; color:#0b2545; display:flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" id="modal_rewrite_enabled" onchange="toggleRewriteBox(this.checked)"> Rewrite the output of this field
                            </label>
                            <div id="modal_rewrite_details" style="display:none; margin-top:10px;">
                                <textarea id="modal_rewrite_template" rows="3" style="width:100%;" placeholder="e.g. &lt;span class='badge'&gt;{{ type }}&lt;/span&gt; &lt;h3&gt;{{ title }}&lt;/h3&gt;"></textarea>
                                <div class="token-help">
                                    <strong>Available Tokens:</strong> <code>{{ id }}</code>, <code>{{ title }}</code>, <code>{{ slug }}</code>, <code>{{ body }}</code>, <code>{{ type }}</code>, <code>{{ author_name }}</code>, <code>{{ created_at }}</code>, <code>{{ url }}</code>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <label style="font-weight:normal; display:flex; align-items:center; gap:8px; margin-bottom:6px; cursor:pointer;">
                                <input type="checkbox" id="modal_hide_empty"> Hide field if empty
                            </label>
                            <input type="text" id="modal_empty_text" placeholder="Custom text to display if field is empty...">
                        </div>

                        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:14px;">
                            <button type="button" class="btn-secondary" onclick="closeModal('fieldEditModal')">Cancel</button>
                            <button type="button" class="btn-primary" onclick="saveFieldModal()">Apply</button>
                        </div>
                    </div>
                </div>

                <!-- MODAL: ADD FIELD SELECTOR -->
                <div id="addFieldSelectorModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
                    <div style="background:#ffffff; width:480px; border-radius:6px; padding:20px;">
                        <h3 style="margin-top:0; color:#0b2545;">Select Field to Add</h3>
                        <div class="form-row">
                            <select id="new_field_selector" style="width:100%; padding:8px;">
                                <!-- Populated dynamically -->
                            </select>
                        </div>
                        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:15px;">
                            <button type="button" class="btn-secondary" onclick="closeModal('addFieldSelectorModal')">Cancel</button>
                            <button type="button" class="btn-primary" onclick="confirmAddField()">Add Field</button>
                        </div>
                    </div>
                </div>

                <!-- MODAL 1: ADD FILTER VARIABLE PICKER -->
                <div id="filterVariableSelectModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
                    <div style="background:#ffffff; width:520px; border-radius:6px; padding:24px; max-height:85vh; overflow-y:auto; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:8px; margin-bottom:14px;">
                            <h3 style="margin:0; color:#0b2545;">Add filter criterion</h3>
                            <button type="button" onclick="closeModal('filterVariableSelectModal')" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
                        </div>
                        <p style="color:#64748b; font-size:0.86rem; margin-top:0;">Select a content property or taxonomy variable to filter the view results.</p>
                        <div class="form-row">
                            <select id="filter_variable_dropdown" style="width:100%; padding:9px; font-size:0.92rem;">
                                <!-- Populated dynamically -->
                            </select>
                        </div>
                        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                            <button type="button" class="btn-secondary" onclick="closeModal('filterVariableSelectModal')">Cancel</button>
                            <button type="button" class="btn-primary" onclick="proceedToFilterConfig()">Configure Filter &raquo;</button>
                        </div>
                    </div>
                </div>

                <!-- MODAL 2: CONFIGURE FILTER CRITERION -->
                <div id="filterCriterionModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
                    <div style="background:#ffffff; width:680px; border-radius:6px; padding:24px; max-height:90vh; overflow-y:auto; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:8px; margin-bottom:12px;">
                            <h3 id="modalFilterHeaderTitle" style="margin:0; color:#0b2545;">Configure filter criterion</h3>
                            <button type="button" onclick="closeModal('filterCriterionModal')" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
                        </div>
                        
                        <p id="modalFilterDesc" style="color:#475569; font-size:0.9rem; margin:0 0 16px 0;"></p>
                        
                        <input type="hidden" id="modal_filter_index" value="">
                        <input type="hidden" id="modal_filter_field" value="">
                        <input type="hidden" id="modal_filter_type" value="">

                        <!-- Expose toggle -->
                        <div style="margin-bottom:14px;">
                            <label style="font-weight:600; display:flex; align-items:center; gap:8px; cursor:pointer; color:#0b2545;">
                                <input type="checkbox" id="modal_filter_exposed" onchange="toggleExposedLabel(this.checked)"> Expose this filter to visitors, to allow them to change it
                            </label>
                            <div id="modal_filter_exposed_label_wrap" style="display:none; margin-top:8px;">
                                <input type="text" id="modal_filter_exposed_label" placeholder="Filter label shown to visitors (e.g. Authored Date)">
                            </div>
                        </div>

                        <!-- Split operator & value configuration box -->
                        <div class="drupal-filter-box">
                            <!-- Left: Operator Column -->
                            <div class="drupal-filter-left">
                                <label style="font-size:0.85rem; font-weight:700; color:#0b2545; display:block; margin-bottom:8px;">Operator</label>
                                
                                <!-- Operator Dropdown (Used for Date, Text, Number) -->
                                <select id="modal_filter_operator_select" style="width:100%; padding:7px;" onchange="onOperatorChange(this.value)">
                                    <option value="=">Is equal to</option>
                                    <option value="<">Is less than</option>
                                    <option value="<=">Is less than or equal to</option>
                                    <option value=">=">Is greater than or equal to</option>
                                    <option value=">">Is greater than</option>
                                    <option value="BETWEEN">Is between</option>
                                    <option value="NOT_BETWEEN">Is not between</option>
                                    <option value="!=">Is not equal to</option>
                                    <option value="CONTAINS">Contains</option>
                                    <option value="STARTS_WITH">Starts with</option>
                                    <option value="EMPTY">Is empty (NULL)</option>
                                    <option value="NOT_EMPTY">Is not empty (NOT NULL)</option>
                                </select>

                                <!-- Operator Radios (Used for Published / Status) -->
                                <div id="modal_filter_operator_radios" style="display:none; flex-direction:column; gap:8px;">
                                    <label style="font-weight:normal; font-size:0.88rem; cursor:pointer;"><input type="radio" name="flt_op_radio" value="=" checked> Is equal to</label>
                                    <label style="font-weight:normal; font-size:0.88rem; cursor:pointer;"><input type="radio" name="flt_op_radio" value="!="> Is not equal to</label>
                                    <label style="font-weight:normal; font-size:0.88rem; cursor:pointer;"><input type="radio" name="flt_op_radio" value="EMPTY"> Is empty (NULL)</label>
                                    <label style="font-weight:normal; font-size:0.88rem; cursor:pointer;"><input type="radio" name="flt_op_radio" value="NOT_EMPTY"> Is not empty (NOT NULL)</label>
                                </div>
                            </div>

                            <div class="drupal-filter-divider">&gt;</div>

                            <!-- Right: Value Column -->
                            <div class="drupal-filter-right">
                                
                                <!-- SECTION A: DATE WIDGET -->
                                <div id="modal_val_section_date" style="display:none;">
                                    <label style="font-size:0.85rem; font-weight:700; color:#0b2545; display:block; margin-bottom:6px;">Value type</label>
                                    <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:12px;">
                                        <label style="font-weight:normal; font-size:0.85rem; cursor:pointer;">
                                            <input type="radio" name="flt_date_type" value="date" checked onchange="toggleDateTypeHelp('date')"> A date in any machine readable format. CCYY-MM-DD HH:MM:SS is preferred.
                                        </label>
                                        <label style="font-weight:normal; font-size:0.85rem; cursor:pointer;">
                                            <input type="radio" name="flt_date_type" value="offset" onchange="toggleDateTypeHelp('offset')"> An offset from the current time such as "+1 day" or "-2 hours -30 minutes"
                                        </label>
                                    </div>

                                    <div id="modal_date_single_val_wrap">
                                        <label style="font-size:0.85rem; font-weight:700; color:#0b2545;">Value</label>
                                        <input type="text" id="modal_filter_date_value" placeholder="e.g. 2026-10-27 or -7 days" style="width:100%; padding:8px; margin-top:4px;">
                                    </div>

                                    <!-- Between Min / Max values -->
                                    <div id="modal_date_between_val_wrap" style="display:none; gap:10px;">
                                        <div style="flex:1;">
                                            <label style="font-size:0.85rem; font-weight:700; color:#0b2545;">Min Date / Offset</label>
                                            <input type="text" id="modal_filter_date_min" placeholder="e.g. -30 days" style="width:100%; padding:8px; margin-top:4px;">
                                        </div>
                                        <div style="flex:1;">
                                            <label style="font-size:0.85rem; font-weight:700; color:#0b2545;">Max Date / Offset</label>
                                            <input type="text" id="modal_filter_date_max" placeholder="e.g. now" style="width:100%; padding:8px; margin-top:4px;">
                                        </div>
                                    </div>
                                </div>

                                <!-- SECTION B: STATUS / PUBLISHED WIDGET -->
                                <div id="modal_val_section_status" style="display:none;">
                                    <label style="font-size:0.85rem; font-weight:700; color:#0b2545; display:block; margin-bottom:8px;">Published status</label>
                                    <div style="display:flex; flex-direction:column; gap:8px;">
                                        <label style="font-weight:normal; font-size:0.88rem; cursor:pointer;"><input type="radio" name="flt_status_val" value="any"> - Any -</label>
                                        <label style="font-weight:normal; font-size:0.88rem; cursor:pointer;"><input type="radio" name="flt_status_val" value="yes" checked> Yes</label>
                                        <label style="font-weight:normal; font-size:0.88rem; cursor:pointer;"><input type="radio" name="flt_status_val" value="no"> No</label>
                                    </div>
                                </div>

                                <!-- SECTION C: TAXONOMY SELECTOR -->
                                <div id="modal_val_section_taxonomy" style="display:none;">
                                    <label style="font-size:0.85rem; font-weight:700; color:#0b2545; display:block; margin-bottom:6px;">Select Term</label>
                                    <select id="modal_filter_tax_val" style="width:100%; padding:8px;">
                                        <option value="all">-- All Terms --</option>
                                        <?php foreach ($all_terms as $t): ?>
                                            <option value="<?= (int)$t['id'] ?>">
                                                <?= htmlspecialchars($t['vocab_name'] ? $t['vocab_name'] . ': ' : '') ?><?= htmlspecialchars($t['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- SECTION D: STANDARD TEXT / NUMERIC INPUT -->
                                <div id="modal_val_section_generic" style="display:none;">
                                    <label style="font-size:0.85rem; font-weight:700; color:#0b2545; display:block; margin-bottom:6px;">Value</label>
                                    <input type="text" id="modal_filter_generic_val" placeholder="Filter parameter value..." style="width:100%; padding:8px;">
                                </div>

                            </div>
                        </div>

                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:14px;">
                            <button type="button" id="modal_filter_remove_btn" class="btn-secondary" style="color:#dc2626; border-color:#fca5a5; display:none;" onclick="removeActiveModalFilter()">Remove Criterion</button>
                            <div style="display:flex; gap:10px; margin-left:auto;">
                                <button type="button" class="btn-secondary" onclick="closeModal('filterCriterionModal')">Cancel</button>
                                <button type="button" class="btn-primary" onclick="saveFilterModal()">Apply</button>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                let viewState = <?= json_encode($disp, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
                let currentBaseTable = "<?= $base_table ?>";
                let currentContentType = "<?= $content_type ?>";
                let allVariablesPool = {};

                if (!viewState.fields) viewState.fields = [];
                if (!viewState.filters) viewState.filters = [];
                if (!viewState.sorts) viewState.sorts = [];

                function autoMachineName(val) {
                    if (document.getElementById('view_machine_name').readOnly) return;
                    document.getElementById('view_machine_name').value = val.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
                }

                function toggleDeploymentUI(type) {
                    document.getElementById('view_path_row').style.display = (type === 'page') ? 'block' : 'none';
                }

                function onBaseTableChange(base) {
                    currentBaseTable = base;
                    document.getElementById('wiz_type_container').style.display = (base === 'nodes') ? 'flex' : 'none';
                    reloadVariables();
                    syncState();
                }

                function onContentTypeChange(ct) {
                    currentContentType = ct;
                    reloadVariables();
                    syncState();
                }

                function onDefaultSortChange(val) {
                    viewState.sorts = [];
                    if (val === 'newest') viewState.sorts.push({ field: 'created_at', order: 'DESC' });
                    if (val === 'oldest') viewState.sorts.push({ field: 'created_at', order: 'ASC' });
                    if (val === 'title_asc') viewState.sorts.push({ field: 'title', order: 'ASC' });
                    if (val === 'title_desc') viewState.sorts.push({ field: 'title', order: 'DESC' });
                    renderSortsList();
                    syncState();
                }

                function reloadVariables() {
                    fetch('<?= base_path("admin/views.php?action=ajax_get_vars") ?>&base_table=' + currentBaseTable + '&content_type=' + currentContentType)
                    .then(r => r.json())
                    .then(vars => {
                        allVariablesPool = vars;

                        // Reload Field Selector
                        const fSel = document.getElementById('new_field_selector');
                        fSel.innerHTML = '';
                        for (const [k, f] of Object.entries(vars)) {
                            const opt = document.createElement('option');
                            opt.value = k;
                            opt.setAttribute('data-label', f.label);
                            opt.textContent = `${f.label} (${k})`;
                            fSel.appendChild(opt);
                        }

                        // Reload Filter Variable Selector
                        const fltSel = document.getElementById('filter_variable_dropdown');
                        fltSel.innerHTML = '';
                        for (const [k, f] of Object.entries(vars)) {
                            const opt = document.createElement('option');
                            opt.value = k;
                            opt.textContent = `${f.group}: ${f.label}`;
                            fltSel.appendChild(opt);
                        }
                    });
                }

                function renderFieldsList() {
                    const c = document.getElementById('fields_container_list');
                    c.innerHTML = '';
                    if (viewState.fields.length === 0) {
                        c.innerHTML = '<span style="color:#94a3b8; font-size:0.85rem;">No fields configured.</span>';
                        return;
                    }
                    viewState.fields.forEach((f, idx) => {
                        const row = document.createElement('div');
                        row.className = 'field-row-item';
                        row.innerHTML = `
                            <div>
                                <strong>${escapeHtml(f.label || f.name)}</strong> 
                                <span class="tag-pill">${escapeHtml(f.name)}</span>
                                ${f.show_label ? '<span class="tag-pill" style="background:#e0f2fe; color:#0369a1;">Label</span>' : ''}
                                ${f.formatter === 'html' ? '<span class="tag-pill" style="background:#fef3c7; color:#92400e;">HTML</span>' : ''}
                                ${f.strip_styles ? '<span class="tag-pill" style="background:#f1f5f9; color:#475569;">No Styles</span>' : ''}
                                ${f.custom_class ? `<span class="tag-pill" style="background:#f3e8ff; color:#6b21a8;">.${escapeHtml(f.custom_class)}</span>` : ''}
                                ${f.custom_id ? `<span class="tag-pill" style="background:#fce7f3; color:#9d174d;">#${escapeHtml(f.custom_id)}</span>` : ''}
                            </div>
                            <div>
                                <button type="button" class="btn-secondary" style="font-size:0.75rem; padding:2px 6px;" onclick="editField(${idx})">Edit</button>
                                <button type="button" class="btn-secondary" style="font-size:0.75rem; padding:2px 6px; color:#c00;" onclick="removeField(${idx})">&times;</button>
                            </div>
                        `;
                        c.appendChild(row);
                    });
                }

                function renderFiltersList() {
                    const c = document.getElementById('filters_container_list');
                    c.innerHTML = '';
                    if (viewState.filters.length === 0) {
                        c.innerHTML = '<span style="color:#94a3b8; font-size:0.85rem;">No static filters applied.</span>';
                        return;
                    }
                    viewState.filters.forEach((flt, idx) => {
                        const row = document.createElement('div');
                        row.className = 'field-row-item';
                        const displayVal = flt.operator === 'BETWEEN' ? `${flt.min} - ${flt.max}` : flt.value;
                        row.innerHTML = `
                            <div>
                                <strong>${escapeHtml(flt.field_label || flt.field)}</strong> (${escapeHtml(flt.operator)}) 
                                <code>${escapeHtml(displayVal || '')}</code>
                                ${flt.exposed ? '<span class="tag-pill" style="background:#dbeafe; color:#1e40af;">Exposed</span>' : ''}
                            </div>
                            <div>
                                <button type="button" class="btn-secondary" style="font-size:0.75rem; padding:2px 6px;" onclick="openEditFilterModal(${idx})">Edit</button>
                                <button type="button" class="btn-secondary" style="font-size:0.75rem; padding:2px 6px; color:#c00;" onclick="removeFilter(${idx})">&times;</button>
                            </div>
                        `;
                        c.appendChild(row);
                    });
                }

                function renderSortsList() {
                    const c = document.getElementById('sorts_container_list');
                    c.innerHTML = '';
                    if (viewState.sorts.length === 0) {
                        c.innerHTML = '<span style="color:#94a3b8; font-size:0.85rem;">Unsorted / Natural order.</span>';
                        return;
                    }
                    viewState.sorts.forEach((s, idx) => {
                        const row = document.createElement('div');
                        row.className = 'field-row-item';
                        row.innerHTML = `
                            <div>
                                <strong>${escapeHtml(s.field)}</strong> (${escapeHtml(s.order)})
                            </div>
                            <button type="button" class="btn-secondary" style="font-size:0.75rem; padding:2px 6px; color:#c00;" onclick="removeSort(${idx})">&times;</button>
                        `;
                        c.appendChild(row);
                    });
                }

                // Field Modal Handlers
                function openAddFieldModal() {
                    document.getElementById('addFieldSelectorModal').style.display = 'flex';
                }

                function confirmAddField() {
                    const sel = document.getElementById('new_field_selector');
                    const fname = sel.value;
                    const flabel = sel.options[sel.selectedIndex].getAttribute('data-label') || fname;
                    viewState.fields.push({
                        name: fname,
                        label: flabel,
                        show_label: 1,
                        formatter: (fname === 'body' ? 'html' : 'plain_text'),
                        strip_styles: 0,
                        wrapper_tag: 'p',
                        custom_class: '',
                        custom_id: '',
                        link_to_node: (fname === 'title' ? 1 : 0),
                        date_format: (fname.includes('date') || fname.includes('created') ? 'M j, Y' : '')
                    });
                    closeModal('addFieldSelectorModal');
                    renderFieldsList();
                    syncState();
                }

                function editField(idx) {
                    const f = viewState.fields[idx];
                    document.getElementById('modal_field_index').value = idx;
                    document.getElementById('modalFieldTitle').innerText = 'Configure field: ' + (f.label || f.name);
                    
                    document.getElementById('modal_show_label').checked = !!f.show_label;
                    document.getElementById('modal_field_label').value = f.label || '';
                    toggleLabelInput(!!f.show_label);

                    document.getElementById('modal_exclude_display').checked = !!f.exclude_display;
                    document.getElementById('modal_field_formatter').value = f.formatter || (f.name === 'body' ? 'html' : 'plain_text');
                    onFormatterChange(document.getElementById('modal_field_formatter').value);

                    document.getElementById('modal_strip_styles').checked = !!f.strip_styles;
                    document.getElementById('modal_field_wrapper_tag').value = f.wrapper_tag || 'p';
                    document.getElementById('modal_field_custom_class').value = f.custom_class || '';
                    document.getElementById('modal_field_custom_id').value = f.custom_id || '';
                    document.getElementById('modal_link_to_node').checked = !!f.link_to_node;
                    document.getElementById('modal_date_format').value = f.date_format || '';
                    document.getElementById('modal_trim_length').value = f.trim_length || 150;
                    document.getElementById('modal_rewrite_enabled').checked = !!f.rewrite_enabled;
                    document.getElementById('modal_rewrite_template').value = f.rewrite_template || '';
                    document.getElementById('modal_hide_empty').checked = !!f.hide_empty;
                    document.getElementById('modal_empty_text').value = f.empty_text || '';

                    toggleRewriteBox(!!f.rewrite_enabled);
                    document.getElementById('fieldEditModal').style.display = 'flex';
                }

                function saveFieldModal() {
                    const idx = document.getElementById('modal_field_index').value;
                    if (idx === '') return;
                    const f = viewState.fields[idx];

                    f.show_label = document.getElementById('modal_show_label').checked ? 1 : 0;
                    f.label = document.getElementById('modal_field_label').value;
                    f.exclude_display = document.getElementById('modal_exclude_display').checked ? 1 : 0;
                    f.formatter = document.getElementById('modal_field_formatter').value;
                    f.strip_styles = document.getElementById('modal_strip_styles').checked ? 1 : 0;
                    f.wrapper_tag = document.getElementById('modal_field_wrapper_tag').value.trim() || 'p';
                    f.custom_class = document.getElementById('modal_field_custom_class').value.trim();
                    f.custom_id = document.getElementById('modal_field_custom_id').value.trim();
                    f.link_to_node = document.getElementById('modal_link_to_node').checked ? 1 : 0;
                    f.date_format = document.getElementById('modal_date_format').value;
                    f.trim_length = parseInt(document.getElementById('modal_trim_length').value, 10) || 150;
                    f.rewrite_enabled = document.getElementById('modal_rewrite_enabled').checked ? 1 : 0;
                    f.rewrite_template = document.getElementById('modal_rewrite_template').value;
                    f.hide_empty = document.getElementById('modal_hide_empty').checked ? 1 : 0;
                    f.empty_text = document.getElementById('modal_empty_text').value;

                    closeModal('fieldEditModal');
                    renderFieldsList();
                    syncState();
                }

                function removeField(idx) {
                    viewState.fields.splice(idx, 1);
                    renderFieldsList();
                    syncState();
                }

                function toggleLabelInput(checked) {
                    document.getElementById('modal_label_wrapper').style.display = checked ? 'block' : 'none';
                }

                function onFormatterChange(val) {
                    document.getElementById('modal_date_format_row').style.display = (val === 'date') ? 'block' : 'none';
                    document.getElementById('modal_trim_length_row').style.display = (val === 'trimmed') ? 'block' : 'none';
                }

                function toggleRewriteBox(show) {
                    document.getElementById('modal_rewrite_details').style.display = show ? 'block' : 'none';
                }

                // Filter Criteria Modal Pipeline
                function openAddFilterModal() {
                    document.getElementById('filterVariableSelectModal').style.display = 'flex';
                }

                function proceedToFilterConfig() {
                    const sel = document.getElementById('filter_variable_dropdown');
                    const fldKey = sel.value;
                    closeModal('filterVariableSelectModal');
                    setupFilterCriterionModal(-1, fldKey);
                }

                function openEditFilterModal(idx) {
                    const flt = viewState.filters[idx];
                    setupFilterCriterionModal(idx, flt.field, flt);
                }

                function setupFilterCriterionModal(idx, fldKey, existing = null) {
                    const meta = allVariablesPool[fldKey] || { label: fldKey, type: 'text', description: '' };
                    document.getElementById('modal_filter_index').value = idx;
                    document.getElementById('modal_filter_field').value = fldKey;
                    document.getElementById('modal_filter_type').value = meta.type;

                    document.getElementById('modalFilterHeaderTitle').innerText = 'Configure filter criterion: Content: ' + meta.label;
                    document.getElementById('modalFilterDesc').innerText = meta.description || 'Filter records matching specified conditions.';
                    
                    const isExposed = existing ? !!existing.exposed : false;
                    document.getElementById('modal_filter_exposed').checked = isExposed;
                    document.getElementById('modal_filter_exposed_label').value = existing ? (existing.exposed_label || '') : meta.label;
                    toggleExposedLabel(isExposed);

                    document.getElementById('modal_filter_remove_btn').style.display = (idx >= 0) ? 'inline-block' : 'none';

                    // Hide all widget sections initially
                    document.getElementById('modal_val_section_date').style.display = 'none';
                    document.getElementById('modal_val_section_status').style.display = 'none';
                    document.getElementById('modal_val_section_taxonomy').style.display = 'none';
                    document.getElementById('modal_val_section_generic').style.display = 'none';

                    const isStatus = (meta.type === 'status' || fldKey === 'status');
                    const isDate = (meta.type === 'date' || fldKey.includes('created') || fldKey.includes('date') || fldKey.includes('updated'));
                    const isTax = (meta.type === 'taxonomy' || fldKey === 'taxonomy_term');

                    document.getElementById('modal_filter_operator_select').style.display = isStatus ? 'none' : 'block';
                    document.getElementById('modal_filter_operator_radios').style.display = isStatus ? 'flex' : 'none';

                    if (isStatus) {
                        document.getElementById('modal_val_section_status').style.display = 'block';
                        const curOp = existing ? existing.operator : '=';
                        const curVal = existing ? existing.value : 'yes';
                        setRadioVal('flt_op_radio', curOp);
                        setRadioVal('flt_status_val', curVal);
                    } else if (isDate) {
                        document.getElementById('modal_val_section_date').style.display = 'block';
                        const curOp = existing ? existing.operator : '>=';
                        document.getElementById('modal_filter_operator_select').value = curOp;
                        const curValType = existing ? (existing.value_type || 'date') : 'date';
                        setRadioVal('flt_date_type', curValType);
                        document.getElementById('modal_filter_date_value').value = existing ? (existing.value || '') : '';
                        document.getElementById('modal_filter_date_min').value = existing ? (existing.min || '') : '';
                        document.getElementById('modal_filter_date_max').value = existing ? (existing.max || '') : '';
                        onOperatorChange(curOp);
                    } else if (isTax) {
                        document.getElementById('modal_val_section_taxonomy').style.display = 'block';
                        document.getElementById('modal_filter_operator_select').value = existing ? existing.operator : '=';
                        document.getElementById('modal_filter_tax_val').value = existing ? existing.value : 'all';
                    } else {
                        document.getElementById('modal_val_section_generic').style.display = 'block';
                        document.getElementById('modal_filter_operator_select').value = existing ? existing.operator : '=';
                        document.getElementById('modal_filter_generic_val').value = existing ? existing.value : '';
                    }

                    document.getElementById('filterCriterionModal').style.display = 'flex';
                }

                function onOperatorChange(op) {
                    const isBetween = (op === 'BETWEEN' || op === 'NOT_BETWEEN');
                    document.getElementById('modal_date_between_val_wrap').style.display = isBetween ? 'flex' : 'none';
                    document.getElementById('modal_date_single_val_wrap').style.display = isBetween ? 'none' : 'block';
                }

                function toggleDateTypeHelp(type) {
                    const single = document.getElementById('modal_filter_date_value');
                    if (type === 'offset') {
                        single.placeholder = 'e.g. +1 day or -2 hours -30 minutes';
                    } else {
                        single.placeholder = 'e.g. 2026-10-27 00:00:00';
                    }
                }

                function toggleExposedLabel(checked) {
                    document.getElementById('modal_filter_exposed_label_wrap').style.display = checked ? 'block' : 'none';
                }

                function saveFilterModal() {
                    const idx = parseInt(document.getElementById('modal_filter_index').value, 10);
                    const fld = document.getElementById('modal_filter_field').value;
                    const fType = document.getElementById('modal_filter_type').value;
                    const meta = allVariablesPool[fld] || { label: fld };

                    const exposed = document.getElementById('modal_filter_exposed').checked ? 1 : 0;
                    const exposed_label = document.getElementById('modal_filter_exposed_label').value;

                    let op = document.getElementById('modal_filter_operator_select').value;
                    let val = '';
                    let val_type = 'date';
                    let min_val = '';
                    let max_val = '';

                    if (fType === 'status' || fld === 'status') {
                        op = getRadioVal('flt_op_radio') || '=';
                        val = getRadioVal('flt_status_val') || 'yes';
                    } else if (fType === 'date' || fld.includes('created') || fld.includes('date') || fld.includes('updated')) {
                        val_type = getRadioVal('flt_date_type') || 'date';
                        if (op === 'BETWEEN' || op === 'NOT_BETWEEN') {
                            min_val = document.getElementById('modal_filter_date_min').value;
                            max_val = document.getElementById('modal_filter_date_max').value;
                        } else {
                            val = document.getElementById('modal_filter_date_value').value;
                        }
                    } else if (fType === 'taxonomy' || fld === 'taxonomy_term') {
                        val = document.getElementById('modal_filter_tax_val').value;
                    } else {
                        val = document.getElementById('modal_filter_generic_val').value;
                    }

                    const filterData = {
                        field: fld,
                        field_label: meta.label,
                        operator: op,
                        value: val,
                        value_type: val_type,
                        min: min_val,
                        max: max_val,
                        exposed: exposed,
                        exposed_label: exposed_label
                    };

                    if (idx >= 0) {
                        viewState.filters[idx] = filterData;
                    } else {
                        viewState.filters.push(filterData);
                    }

                    closeModal('filterCriterionModal');
                    renderFiltersList();
                    syncState();
                }

                function removeActiveModalFilter() {
                    const idx = parseInt(document.getElementById('modal_filter_index').value, 10);
                    if (idx >= 0) {
                        viewState.filters.splice(idx, 1);
                    }
                    closeModal('filterCriterionModal');
                    renderFiltersList();
                    syncState();
                }

                function removeFilter(idx) {
                    viewState.filters.splice(idx, 1);
                    renderFiltersList();
                    syncState();
                }

                // Sort Criteria Handlers
                function openAddSortModal() {
                    const field = prompt('Enter sort field (created_at, title, updated_at):', 'created_at');
                    if (!field) return;
                    const order = prompt('Direction (ASC or DESC):', 'DESC');
                    viewState.sorts.push({ field, order: (order || 'DESC').toUpperCase() });
                    renderSortsList();
                    syncState();
                }

                function removeSort(idx) {
                    viewState.sorts.splice(idx, 1);
                    renderSortsList();
                    syncState();
                }

                function closeModal(id) {
                    document.getElementById(id).style.display = 'none';
                }

                function escapeHtml(str) {
                    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                }

                function getRadioVal(name) {
                    const el = document.querySelector(`input[name="${name}"]:checked`);
                    return el ? el.value : '';
                }

                function setRadioVal(name, val) {
                    const el = document.querySelector(`input[name="${name}"][value="${val}"]`);
                    if (el) el.checked = true;
                }

                function syncState() {
                    viewState.format = document.getElementById('cfg_format').value;
                    viewState.grid_columns = parseInt(document.getElementById('cfg_grid_columns').value, 10) || 3;
                    viewState.items_per_page = parseInt(document.getElementById('cfg_items_per_page').value, 10) || 0;
                    viewState.use_pager = document.getElementById('cfg_use_pager').checked ? 1 : 0;
                    viewState.display_title = document.getElementById('cfg_display_title').value;
                    viewState.empty_text = document.getElementById('cfg_empty_text').value;

                    const payload = {
                        base_table: currentBaseTable,
                        content_type: currentContentType,
                        displays: {
                            default: viewState
                        }
                    };
                    document.getElementById('config_payload').value = JSON.stringify(payload);
                }

                function fetchLivePreview() {
                    syncState();
                    const container = document.getElementById('livePreviewContainer');
                    container.innerHTML = '<div style="color:#64748b; padding:15px; text-align:center;">Compiling and executing query...</div>';

                    const fd = new FormData();
                    fd.append('csrf_token', document.getElementById('views_csrf_token').value);
                    fd.append('config_payload', document.getElementById('config_payload').value);
                    fd.append('machine_name', document.getElementById('view_machine_name').value);

                    fetch('<?= base_path("admin/views.php?action=ajax_preview") ?>', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.text())
                    .then(html => {
                        container.innerHTML = html;
                    })
                    .catch(err => {
                        container.innerHTML = '<div style="color:#c00;">Preview error: ' + err + '</div>';
                    });
                }

                // Initialize
                reloadVariables();
                renderFieldsList();
                renderFiltersList();
                renderSortsList();
                syncState();
                </script>

            <?php else: ?>
                <!-- ALL VIEWS LIST OVERVIEW -->
                <div class="page-header-actions">
                    <div>
                        <h1>Structure &raquo; Views</h1>
                        <p style="color:#64748b; font-size:0.88rem; margin-top:-12px;">
                            Configure dynamic content queries, filterable pages, sortable tables, and block widgets.
                        </p>
                    </div>
                    <div>
                        <a href="<?= base_path('admin/views.php?action=create') ?>" class="btn-primary">+ Add New View</a>
                    </div>
                </div>

                <?php render_flash_messages(); ?>

                <section class="card">
                    <table>
                        <thead>
                            <tr>
                                <th>View Title</th>
                                <th>Machine Identifier</th>
                                <th>Target & Placement</th>
                                <th>Format</th>
                                <th>Shortcode Embed</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($all_views)): ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; color:#64748b; padding:25px;">
                                        No custom views created yet. Click "+ Add New View" above.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_views as $v): ?>
                                    <?php 
                                        $d = $v['configuration']['displays']['default'] ?? []; 
                                        $v_type = $v['display_type'] ?? 'block';
                                        $v_path = $v['path'] ?? '';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><a href="<?= base_path('admin/views.php?action=edit&id=' . (int)$v['id']) ?>"><?= htmlspecialchars($v['name']) ?></a></strong>
                                            <?php if (!empty($v['description'])): ?>
                                                <div style="font-size:0.8rem; color:#64748b; margin-top:2px;"><?= htmlspecialchars($v['description']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?= htmlspecialchars($v['machine_name']) ?></code></td>
                                        <td>
                                            <?php if ($v_type === 'page'): ?>
                                                <span class="tag-pill" style="background:#e0f2fe; color:#0369a1; font-weight:700;">PAGE</span>
                                                <div style="margin-top:2px;"><a href="<?= base_path($v_path) ?>" target="_blank" style="font-size:0.82rem; font-family:monospace;">/<?= htmlspecialchars($v_path) ?></a></div>
                                            <?php else: ?>
                                                <span class="tag-pill" style="background:#fef3c7; color:#92400e; font-weight:700;">BLOCK</span>
                                                <div style="margin-top:2px;"><a href="<?= base_path('admin/blocks.php') ?>" style="font-size:0.8rem;">In Blocks &raquo;</a></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= strtoupper(htmlspecialchars($d['format'] ?? 'cards')) ?></td>
                                        <td><code>[view:<?= htmlspecialchars($v['machine_name']) ?>]</code></td>
                                        <td>
                                            <span class="badge-status <?= $v['status'] ? 'status-published' : 'status-archived' ?>">
                                                <?= $v['status'] ? 'Active' : 'Disabled' ?>
                                            </span>
                                        </td>
                                        <td style="text-align:right; white-space:nowrap;">
                                            <a href="<?= base_path('admin/views.php?action=edit&id=' . (int)$v['id']) ?>" class="btn-secondary" style="font-size:0.78rem; padding:4px 8px;">Edit</a>
                                            <a href="<?= base_path('admin/views.php?action=delete&id=' . (int)$v['id'] . '&csrf_token=' . get_csrf_token()) ?>" 
                                               data-confirm="Are you sure you want to delete this view and any associated block placement?"
                                               data-confirm-title="Delete View"
                                               data-confirm-btn="Delete"
                                               style="color:#c00; font-size:0.8rem; margin-left:8px; text-decoration:none;">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>

        </main>
    </div>
</body>
</html>