<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';

require_permission('administer_site');

$db = get_db();

// 1. Action: Delete Block
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    validate_csrf();
    $del = $db->prepare('DELETE FROM blocks WHERE id = :id');
    $del->execute(['id' => (int)$_GET['id']]);
    watchdog('blocks', 'Deleted block ID: ' . (int)$_GET['id'], 'notice');
    set_flash('success', 'Block removed successfully.');
    header('Location: ' . base_path('admin/blocks.php'));
    exit;
}

// 2. Action: Save Block (Add or Edit via Modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_block_modal') {
    validate_csrf();
    $block_id   = (int)($_POST['block_id'] ?? 0);
    $title      = trim($_POST['title'] ?? '');
    $region     = trim($_POST['region'] ?? 'sidebar');
    $body       = $_POST['body'] ?? '';
    $weight     = (int)($_POST['weight'] ?? 0);
    $status     = isset($_POST['status']) ? 1 : 0;
    
    // Visibility Matrix Rules
    $pages      = trim($_POST['visibility_pages'] ?? '');
    $mode       = in_array($_POST['visibility_mode'] ?? '', ['show_only', 'show_except'], true) ? $_POST['visibility_mode'] : 'show_only';
    $types      = implode(',', $_POST['visibility_types'] ?? []);
    $roles      = implode(',', $_POST['visibility_roles'] ?? []);

    if ($title === '') {
        set_flash('error', 'Block Title is required.');
    } else {
        if ($block_id > 0) {
            // Update Existing Block
            $stmt = $db->prepare('
                UPDATE blocks 
                SET title = ?, region = ?, body = ?, weight = ?, status = ?, 
                    visibility_pages = ?, visibility_mode = ?, visibility_types = ?, visibility_roles = ?
                WHERE id = ?
            ');
            $stmt->execute([$title, $region, $body, $weight, $status, $pages, $mode, $types, $roles, $block_id]);
            watchdog('blocks', "Updated block '{$title}' (#{$block_id})", 'notice');
            set_flash('success', "Block '{$title}' updated successfully.");
        } else {
            // Insert New Block
            $stmt = $db->prepare('
                INSERT INTO blocks (title, region, body, weight, status, visibility_pages, visibility_mode, visibility_types, visibility_roles) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$title, $region, $body, $weight, $status, $pages, $mode, $types, $roles]);
            watchdog('blocks', "Created block '{$title}'", 'notice');
            set_flash('success', "Block '{$title}' created in region '{$region}'.");
        }

        if (function_exists('cache_clear')) {
            cache_clear();
        }
    }

    header('Location: ' . base_path('admin/blocks.php'));
    exit;
}

$blocks = $db->query('SELECT * FROM blocks ORDER BY region ASC, weight ASC')->fetchAll(PDO::FETCH_ASSOC);
$all_roles = $db->query('SELECT machine_name, label FROM roles ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
$all_types = get_content_types();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Block Visibility Matrix - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <div class="page-header-actions">
                <div>
                    <h1>Structure &raquo; Block Visibility Matrix</h1>
                    <p style="color: #64748b; font-size: 0.88rem; margin-top: -12px;">
                        Manage component blocks, designated placement regions, and granular visibility rules.
                    </p>
                </div>
                <button type="button" class="btn-primary" onclick="openAddBlockModal()">+ Add Matrix Block</button>
            </div>

            <?php render_flash_messages(); ?>

            <section class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Block Title</th>
                            <th>Region</th>
                            <th>Weight</th>
                            <th>Visibility Rules</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($blocks)): ?>
                            <tr><td colspan="6" style="text-align:center; color:#64748b; padding:25px;">No custom blocks configured. Click "+ Add Matrix Block" above.</td></tr>
                        <?php else: ?>
                            <?php foreach ($blocks as $b): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($b['title']) ?></strong>
                                    <div style="font-size:0.78rem; color:#64748b; margin-top:2px; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                        <?= htmlspecialchars(strip_tags($b['body'])) ?>
                                    </div>
                                </td>
                                <td><span class="region-badge"><?= htmlspecialchars($b['region']) ?></span></td>
                                <td><?= (int)$b['weight'] ?></td>
                                <td style="font-size:0.8rem; line-height: 1.4;">
                                    <div><strong>Mode:</strong> <?= $b['visibility_mode'] === 'show_only' ? 'Only listed pages' : 'All except listed' ?></div>
                                    <?php if (!empty($b['visibility_pages'])): ?>
                                        <div style="margin-top:2px;">
                                            <strong>Pages:</strong> 
                                            <?php foreach (array_filter(array_map('trim', explode("\n", str_replace("\r", "", $b['visibility_pages'])))) as $pg): ?>
                                                <span class="rule-meta-tag"><?= htmlspecialchars($pg) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($b['visibility_types'])): ?>
                                        <div style="margin-top:2px;">
                                            <strong>Types:</strong> 
                                            <?php foreach (explode(',', $b['visibility_types']) as $vt): ?>
                                                <span class="rule-meta-tag"><?= htmlspecialchars($vt) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($b['visibility_roles'])): ?>
                                        <div style="margin-top:2px;">
                                            <strong>Roles:</strong> 
                                            <?php foreach (explode(',', $b['visibility_roles']) as $vr): ?>
                                                <span class="rule-meta-tag"><?= htmlspecialchars($vr) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-status <?= $b['status'] ? 'status-published' : 'status-draft' ?>">
                                        <?= $b['status'] ? 'Active' : 'Disabled' ?>
                                    </span>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <button type="button" class="btn-secondary" style="font-size:0.8rem; padding:4px 8px;" 
                                            onclick='openEditBlockModal(<?= json_encode($b) ?>)'>Edit</button>
                                    <a href="<?= base_path('admin/blocks.php?action=delete&id=' . (int)$b['id'] . '&csrf_token=' . get_csrf_token()) ?>" 
                                       data-confirm="Permanently remove this block?" 
                                       data-confirm-title="Delete Block"
                                       data-confirm-btn="Delete"
                                       style="color:#c00; font-size:0.8rem; margin-left:8px; text-decoration:none;">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>

    <!-- Unified Modal Dialog (Add & Edit Block) -->
    <div id="blockModalOverlay" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modalBlockTitle">Configure Matrix Block</h3>
                <button type="button" class="modal-close-btn" onclick="closeBlockModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="action" value="save_block_modal">
                <input type="hidden" name="block_id" id="modal_block_id" value="0">

                <div class="modal-body">
                    <!-- 1. General Settings -->
                    <div class="form-row">
                        <label>Block Title *</label>
                        <input type="text" name="title" id="modal_block_title" required placeholder="e.g. Safety Hotline Callout">
                    </div>

                    <div style="display:grid; grid-template-columns: 1.5fr 1fr; gap:14px;">
                        <div class="form-row">
                            <label>Placement Region *</label>
                            <select name="region" id="modal_block_region">
                                <option value="sidebar">Sidebar (Right Column)</option>
                                <option value="content_top">Main Content: Top (Above Content)</option>
                                <option value="content_bottom">Main Content: Bottom (Below Content)</option>
                                <option value="home_columns">Home 3-Column Section</option>
                            </select>
                        </div>
                        <div class="form-row">
                            <label>Weight / Order</label>
                            <input type="number" name="weight" id="modal_block_weight" value="0">
                        </div>
                    </div>

                    <div class="form-row">
                        <label>Content (HTML & Tokens Supported) *</label>
                        <textarea name="body" id="modal_block_body" rows="4" required placeholder="<p>Notice: [site:name] updates for [current-date:Y]...</p>"></textarea>
                    </div>

                    <!-- Token Insert Chips -->
                    <div style="margin-top: 6px; padding: 8px 10px; background: #fafbfc; border: 1px dashed var(--border-color); border-radius: var(--radius-sm); display: flex; flex-wrap: wrap; gap: 4px; align-items: center;">
                        <span style="font-size: 0.72rem; font-weight: 700; color: #475569; text-transform: uppercase; margin-right: 4px;">Insert Token:</span>
                        <button type="button" class="token-btn" onclick="insertTokenToBlock('[site:name]')">[site:name]</button>
                        <button type="button" class="token-btn" onclick="insertTokenToBlock('[site:url]')">[site:url]</button>
                        <button type="button" class="token-btn" onclick="insertTokenToBlock('[current-date:Y]')">[current-date:Y]</button>
                        <button type="button" class="token-btn" onclick="insertTokenToBlock('[node:title]')">[node:title]</button>
                    </div>

                    <!-- 2. Visibility Matrix Rules -->
                    <div class="fieldset-box">
                        <h4>Visibility Matrix Rules</h4>

                        <div class="form-row">
                            <label>Page Visibility Mode</label>
                            <select name="visibility_mode" id="modal_block_visibility_mode">
                                <option value="show_only">Show only on listed pages</option>
                                <option value="show_except">Show on all pages except listed</option>
                            </select>
                        </div>

                        <div class="form-row">
                            <label>Pages / Paths (One per line)</label>
                            <textarea name="visibility_pages" id="modal_block_visibility_pages" rows="3" placeholder="&lt;front&gt;&#10;about-us&#10;news/*"></textarea>
                            <small>Specify <code>&lt;front&gt;</code> for the home page, or <code>news/*</code> for wildcard path matching. Leave blank for all pages.</small>
                        </div>

                        <div class="form-row">
                            <label>Content Types (Leave unchecked for all)</label>
                            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 4px;">
                                <?php foreach ($all_types as $ct): ?>
                                    <label style="font-weight: normal; font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                                        <input type="checkbox" name="visibility_types[]" value="<?= htmlspecialchars($ct['type']) ?>" class="chk-type">
                                        <?= htmlspecialchars($ct['name']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="form-row" style="margin-bottom:0;">
                            <label>User Roles (Leave unchecked for everyone)</label>
                            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 4px;">
                                <label style="font-weight: normal; font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                                    <input type="checkbox" name="visibility_roles[]" value="anonymous" class="chk-role"> Anonymous
                                </label>
                                <?php foreach ($all_roles as $r): ?>
                                    <label style="font-weight: normal; font-size: 0.85rem; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                                        <input type="checkbox" name="visibility_roles[]" value="<?= htmlspecialchars($r['machine_name']) ?>" class="chk-role">
                                        <?= htmlspecialchars($r['label']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-row" style="margin-top: 16px;">
                        <label style="font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="status" id="modal_block_status" value="1" checked>
                            <span>Active / Enabled</span>
                        </label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeBlockModal()">Cancel</button>
                    <button type="submit" class="btn-primary" id="modalSubmitBtn">Save Block</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddBlockModal() {
            document.getElementById('modalBlockTitle').innerText = 'Add Matrix Block';
            document.getElementById('modal_block_id').value = '0';
            document.getElementById('modalSubmitBtn').innerText = 'Save Block';

            document.getElementById('modal_block_title').value = '';
            document.getElementById('modal_block_region').value = 'sidebar';
            document.getElementById('modal_block_weight').value = '0';
            document.getElementById('modal_block_body').value = '';
            document.getElementById('modal_block_visibility_mode').value = 'show_only';
            document.getElementById('modal_block_visibility_pages').value = '';
            document.getElementById('modal_block_status').checked = true;

            // Uncheck all type and role checkboxes
            document.querySelectorAll('.chk-type').forEach(cb => cb.checked = false);
            document.querySelectorAll('.chk-role').forEach(cb => cb.checked = false);

            document.getElementById('blockModalOverlay').classList.add('active');
            document.getElementById('modal_block_title').focus();
        }

        function openEditBlockModal(block) {
            document.getElementById('modalBlockTitle').innerText = 'Edit Block: ' + block.title;
            document.getElementById('modal_block_id').value = block.id;
            document.getElementById('modalSubmitBtn').innerText = 'Save Changes';

            document.getElementById('modal_block_title').value = block.title;
            document.getElementById('modal_block_region').value = block.region || 'sidebar';
            document.getElementById('modal_block_weight').value = block.weight || '0';
            document.getElementById('modal_block_body').value = block.body || '';
            document.getElementById('modal_block_visibility_mode').value = block.visibility_mode || 'show_only';
            document.getElementById('modal_block_visibility_pages').value = block.visibility_pages || '';
            document.getElementById('modal_block_status').checked = (parseInt(block.status, 10) === 1);

            // Populate Content Type checkboxes
            const types = (block.visibility_types || '').split(',').map(s => s.trim());
            document.querySelectorAll('.chk-type').forEach(cb => {
                cb.checked = types.includes(cb.value);
            });

            // Populate Role checkboxes
            const roles = (block.visibility_roles || '').split(',').map(s => s.trim());
            document.querySelectorAll('.chk-role').forEach(cb => {
                cb.checked = roles.includes(cb.value);
            });

            document.getElementById('blockModalOverlay').classList.add('active');
            document.getElementById('modal_block_title').focus();
        }

        function closeBlockModal() {
            document.getElementById('blockModalOverlay').classList.remove('active');
        }

        function insertTokenToBlock(token) {
            const textarea = document.getElementById('modal_block_body');
            const start = textarea.selectionStart || textarea.value.length;
            const end = textarea.selectionEnd || textarea.value.length;
            const val = textarea.value;
            textarea.value = val.substring(0, start) + token + val.substring(end);
            textarea.focus();
            textarea.selectionStart = textarea.selectionEnd = start + token.length;
        }
    </script>
</body>
</html>