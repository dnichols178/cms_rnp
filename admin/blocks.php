<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/cache.php';

require_permission('administer_site');

$db = get_db();
$action = $_GET['action'] ?? 'list';
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Standard defined theme regions (machine_name => Human Label)
$predefined_regions = [
    'header'         => 'Header Region',
    'sidebar'        => 'Sidebar Region',
    'content_top'    => 'Content Top Region',
    'content_bottom' => 'Content Bottom Region',
    'footer'         => 'Footer Region',
];

// 1. Handle Move Up / Move Down Arrow Actions
if ($action === 'move' && $edit_id > 0) {
    $token = $_GET['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('403 Forbidden: Invalid or expired security token.');
    }

    $dir = $_GET['dir'] ?? 'up';

    $stmt = $db->prepare('SELECT id, region FROM blocks WHERE id = :id');
    $stmt->execute(['id' => $edit_id]);
    $current_block = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($current_block) {
        $reg = $current_block['region'];
        // Fetch all blocks in this region ordered by current weight and id
        $stmt = $db->prepare('SELECT id FROM blocks WHERE region = :region ORDER BY weight ASC, id ASC');
        $stmt->execute(['region' => $reg]);
        $region_block_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $pos = array_search($edit_id, $region_block_ids, false);
        if ($pos !== false) {
            $swap_pos = ($dir === 'up') ? $pos - 1 : $pos + 1;
            if ($swap_pos >= 0 && $swap_pos < count($region_block_ids)) {
                // Swap positions in array
                $temp = $region_block_ids[$pos];
                $region_block_ids[$pos] = $region_block_ids[$swap_pos];
                $region_block_ids[$swap_pos] = $temp;

                // Re-sequence weights cleanly (10, 20, 30...) to prevent collisions
                $upd = $db->prepare('UPDATE blocks SET weight = :w WHERE id = :id');
                $w = 10;
                foreach ($region_block_ids as $bid) {
                    $upd->execute(['w' => $w, 'id' => $bid]);
                    $w += 10;
                }

                cache_clear();
                set_flash('success', 'Block order updated successfully.');
            }
        }
        header('Location: ' . base_path('admin/blocks.php#region-' . urlencode($reg)));
        exit;
    }
}

// 2. Handle Status Toggle
if ($action === 'toggle' && $edit_id > 0) {
    $token = $_GET['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('403 Forbidden: Invalid or expired security token.');
    }

    $stmt = $db->prepare('UPDATE blocks SET status = IF(status = 1, 0, 1) WHERE id = :id');
    $stmt->execute(['id' => $edit_id]);
    cache_clear();
    set_flash('success', 'Block status updated.');
    header('Location: ' . base_path('admin/blocks.php'));
    exit;
}

// 3. Handle Block Deletion
if ($action === 'delete' && $edit_id > 0) {
    $token = $_GET['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('403 Forbidden: Invalid or expired security token.');
    }

    $stmt = $db->prepare('DELETE FROM blocks WHERE id = :id');
    $stmt->execute(['id' => $edit_id]);
    cache_clear();
    watchdog('block', "Deleted block ID #{$edit_id}.", 'notice');
    set_flash('success', 'Block permanently removed.');
    header('Location: ' . base_path('admin/blocks.php'));
    exit;
}

// 4. Handle Form Submission: Create or Update Block
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_block'])) {
    validate_csrf();

    $id               = (int)($_POST['block_id'] ?? 0);
    $title            = trim($_POST['title'] ?? '');
    $region           = trim($_POST['region'] ?? 'sidebar');
    $body             = trim($_POST['body'] ?? '');
    $weight           = (int)($_POST['weight'] ?? 0);
    $status           = isset($_POST['status']) ? 1 : 0;
    $visibility_mode  = in_array($_POST['visibility_mode'] ?? '', ['show_only', 'show_except'], true) ? $_POST['visibility_mode'] : 'show_only';
    $visibility_pages = trim($_POST['visibility_pages'] ?? '');

    $types = isset($_POST['visibility_types']) && is_array($_POST['visibility_types'])
        ? implode(',', array_filter(array_map('trim', $_POST['visibility_types'])))
        : '';

    $roles = isset($_POST['visibility_roles']) && is_array($_POST['visibility_roles'])
        ? implode(',', array_filter(array_map('trim', $_POST['visibility_roles'])))
        : '';

    if ($title === '') {
        set_flash('error', 'Block title cannot be blank.');
    } else {
        if ($id > 0) {
            $stmt = $db->prepare('
                UPDATE blocks 
                SET title = :title, region = :region, body = :body, weight = :weight, 
                    status = :status, visibility_pages = :visibility_pages, 
                    visibility_mode = :visibility_mode, visibility_types = :visibility_types, 
                    visibility_roles = :visibility_roles
                WHERE id = :id
            ');
            $stmt->execute([
                'title'            => $title,
                'region'           => $region,
                'body'             => $body,
                'weight'           => $weight,
                'status'           => $status,
                'visibility_pages' => $visibility_pages,
                'visibility_mode'  => $visibility_mode,
                'visibility_types' => $types,
                'visibility_roles' => $roles,
                'id'               => $id,
            ]);
            set_flash('success', "Block '{$title}' updated successfully.");
            watchdog('block', "Updated block '{$title}' (ID #{$id}).", 'notice');
        } else {
            // New blocks get assigned the next incrementing weight in that region
            $max_w = (int)$db->query("SELECT MAX(weight) FROM blocks WHERE region = " . $db->quote($region))->fetchColumn();
            $weight = $weight !== 0 ? $weight : ($max_w + 10);

            $stmt = $db->prepare('
                INSERT INTO blocks 
                (title, region, body, weight, status, visibility_pages, visibility_mode, visibility_types, visibility_roles)
                VALUES 
                (:title, :region, :body, :weight, :status, :visibility_pages, :visibility_mode, :visibility_types, :visibility_roles)
            ');
            $stmt->execute([
                'title'            => $title,
                'region'           => $region,
                'body'             => $body,
                'weight'           => $weight,
                'status'           => $status,
                'visibility_pages' => $visibility_pages,
                'visibility_mode'  => $visibility_mode,
                'visibility_types' => $types,
                'visibility_roles' => $roles,
            ]);
            $new_id = (int)$db->lastInsertId();
            set_flash('success', "New block '{$title}' created.");
            watchdog('block', "Created block '{$title}' (ID #{$new_id}).", 'notice');
        }

        cache_clear();
        header('Location: ' . base_path('admin/blocks.php#region-' . urlencode($region)));
        exit;
    }
}

// 5. Fetch All Blocks and Group by Region
$all_blocks = $db->query('SELECT * FROM blocks ORDER BY weight ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);

$active_regions = $predefined_regions;
foreach ($all_blocks as $b) {
    $r = $b['region'];
    if (!isset($active_regions[$r])) {
        $active_regions[$r] = ucwords(str_replace(['_', '-'], ' ', $r)) . ' Region';
    }
}

$blocks_by_region = [];
foreach (array_keys($active_regions) as $reg_key) {
    $blocks_by_region[$reg_key] = [];
}
foreach ($all_blocks as $b) {
    $reg = $b['region'] ?? 'sidebar';
    $blocks_by_region[$reg][] = $b;
}

$available_types = get_content_types();
$available_roles = $db->query('SELECT machine_name, label FROM roles ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Block Visibility Matrix & Regions - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>

        <main class="admin-main" style="max-width: 1350px;">
            <div class="page-header-actions" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div>
                    <h1 style="margin: 0 0 4px 0;">Structure &raquo; Block Layout</h1>
                    <p style="color: #64748b; font-size: 0.88rem; margin: 0;">
                        Manage regional blocks, reorder placements using arrow controls, and adjust visibility rules.
                    </p>
                </div>
                <div>
                    <button type="button" class="btn-primary" onclick="openBlockModal()">+ Add New Block</button>
                </div>
            </div>

            <?php render_flash_messages(); ?>

            <!-- REGION TABLES -->
            <?php foreach ($active_regions as $region_key => $region_label): ?>
                <?php $blocks = $blocks_by_region[$region_key] ?? []; ?>
                <section class="region-card" id="region-<?= htmlspecialchars($region_key) ?>">
                    <header class="region-header">
                        <h2>
                            <?= htmlspecialchars($region_label) ?>
                            <span class="region-code-badge"><?= htmlspecialchars($region_key) ?></span>
                            <span style="font-size: 0.8rem; font-weight: normal; color: #64748b;">(<?= count($blocks) ?> block<?= count($blocks) === 1 ? '' : 's' ?>)</span>
                        </h2>
                        <button type="button" class="btn-secondary" style="font-size: 0.8rem; padding: 4px 10px;" onclick="openBlockModal({region: '<?= htmlspecialchars($region_key) ?>'})">
                            + Add to <?= htmlspecialchars(ucfirst(explode(' ', $region_label)[0])) ?>
                        </button>
                    </header>

                    <div class="table-responsive">
                        <table style="margin: 0; width: 100%;">
                            <thead>
                                <tr>
                                    <th style="width: 25%;">Block Title</th>
                                    <th style="width: 14%; text-align: center;">Order</th>
                                    <th style="width: 33%;">Visibility Criteria</th>
                                    <th style="width: 12%; text-align: center;">Status</th>
                                    <th style="width: 16%; text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($blocks)): ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; color: #94a3b8; padding: 22px; font-style: italic;">
                                            No blocks assigned to the <strong><?= htmlspecialchars($region_label) ?></strong>.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($blocks as $idx => $blk): ?>
                                        <?php
                                            $blk_types = array_filter(explode(',', $blk['visibility_types'] ?? ''));
                                            $blk_roles = array_filter(explode(',', $blk['visibility_roles'] ?? ''));
                                            $page_rules = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $blk['visibility_pages'] ?? ''))));
                                            $is_first = ($idx === 0);
                                            $is_last = ($idx === count($blocks) - 1);
                                        ?>
                                        <tr>
                                            <td>
                                                <strong style="color: var(--frnp-navy, #0b2545); font-size: 0.95rem;">
                                                    <?= htmlspecialchars($blk['title']) ?>
                                                </strong>
                                                <?php if (str_contains($blk['body'], '[view:')): ?>
                                                    <div style="font-size: 0.75rem; color: #d97706; margin-top: 2px; font-family: monospace;">Embedded View</div>
                                                <?php elseif (str_contains($blk['body'], '[registration_form:')): ?>
                                                    <div style="font-size: 0.75rem; color: #0284c7; margin-top: 2px; font-family: monospace;">Embedded Registration</div>
                                                <?php elseif (str_contains($blk['body'], '[similar_content')): ?>
                                                    <div style="font-size: 0.75rem; color: #059669; margin-top: 2px; font-family: monospace;">Similar Articles</div>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Up / Down Order Buttons -->
                                            <td style="text-align: center;">
                                                <div class="order-controls">
                                                    <?php if (!$is_first): ?>
                                                        <a href="<?= base_path('admin/blocks.php?action=move&id=' . (int)$blk['id'] . '&dir=up&csrf_token=' . get_csrf_token()) ?>" 
                                                           class="btn-arrow" title="Move Up">&uarr;</a>
                                                    <?php else: ?>
                                                        <span class="btn-arrow disabled" title="Top of region">&uarr;</span>
                                                    <?php endif; ?>

                                                    <?php if (!$is_last): ?>
                                                        <a href="<?= base_path('admin/blocks.php?action=move&id=' . (int)$blk['id'] . '&dir=down&csrf_token=' . get_csrf_token()) ?>" 
                                                           class="btn-arrow" title="Move Down">&darr;</a>
                                                    <?php else: ?>
                                                        <span class="btn-arrow disabled" title="Bottom of region">&darr;</span>
                                                    <?php endif; ?>

                                                    <span style="font-family: monospace; font-size: 0.8rem; color: #64748b; margin-left: 5px;">
                                                        <?= (int)$blk['weight'] ?>
                                                    </span>
                                                </div>
                                            </td>

                                            <td style="font-size: 0.84rem; color: #334155;">
                                                <div>
                                                    <strong style="font-size: 0.75rem; text-transform: uppercase; color: #64748b;">Pages (<?= $blk['visibility_mode'] === 'show_except' ? 'All except' : 'Only on' ?>):</strong>
                                                    <?php if (empty($page_rules)): ?>
                                                        <span style="color: #64748b;">All pages</span>
                                                    <?php else: ?>
                                                        <?php foreach ($page_rules as $rule): ?>
                                                            <span class="visibility-tag"><?= htmlspecialchars($rule) ?></span>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if (!empty($blk_types)): ?>
                                                    <div style="margin-top: 3px;">
                                                        <strong style="font-size: 0.75rem; text-transform: uppercase; color: #64748b;">Types:</strong>
                                                        <?php foreach ($blk_types as $t): ?>
                                                            <span class="visibility-tag" style="background:#f1f5f9; color:#334155;"><?= htmlspecialchars($t) ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($blk_roles)): ?>
                                                    <div style="margin-top: 3px;">
                                                        <strong style="font-size: 0.75rem; text-transform: uppercase; color: #64748b;">Roles:</strong>
                                                        <?php foreach ($blk_roles as $r): ?>
                                                            <span class="role-tag"><?= htmlspecialchars($r) ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <td style="text-align: center;">
                                                <a href="<?= base_path('admin/blocks.php?action=toggle&id=' . (int)$blk['id'] . '&csrf_token=' . get_csrf_token()) ?>" 
                                                   style="text-decoration: none;" title="Toggle active status">
                                                    <span class="badge-status <?= $blk['status'] ? 'status-published' : 'status-archived' ?>">
                                                        <?= $blk['status'] ? 'Active' : 'Disabled' ?>
                                                    </span>
                                                </a>
                                            </td>

                                            <td style="text-align: right; white-space: nowrap;">
                                                <button type="button" class="btn-secondary" style="font-size: 0.8rem; padding: 4px 8px;" 
                                                        onclick='openBlockModal(<?= json_encode($blk, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                                    Edit
                                                </button>

                                                <a href="<?= base_path('admin/blocks.php?action=delete&id=' . (int)$blk['id'] . '&csrf_token=' . get_csrf_token()) ?>" 
                                                   onclick="return confirm('Permanently delete the block &quot;<?= htmlspecialchars(addslashes($blk['title'])) ?>&quot;?');"
                                                   style="color: #dc2626; font-size: 0.8rem; margin-left: 8px; text-decoration: none;">
                                                    Delete
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endforeach; ?>
        </main>
    </div>

    <!-- MODAL: ADD / EDIT BLOCK -->
    <div id="blockModal" class="modal-overlay">
        <div class="modal-dialog">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 16px;">
                <h3 id="modalTitle" style="margin: 0; color: var(--frnp-navy, #0b2545); font-size: 1.25rem;">Add Block</h3>
                <button type="button" onclick="closeBlockModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;">&times;</button>
            </div>

            <form method="POST" action="<?= base_path('admin/blocks.php') ?>">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="save_block" value="1">
                <input type="hidden" name="block_id" id="modal_block_id" value="0">

                <div style="display: grid; grid-template-columns: 2fr 1.2fr 0.8fr; gap: 15px;">
                    <div class="form-row">
                        <label>Block Title *</label>
                        <input type="text" name="title" id="modal_title" required placeholder="e.g. Community Outreach">
                    </div>
                    <div class="form-row">
                        <label>Target Region *</label>
                        <select name="region" id="modal_region">
                            <?php foreach ($active_regions as $rk => $rl): ?>
                                <option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($rl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <label>Weight (Order)</label>
                        <input type="number" name="weight" id="modal_weight" value="0">
                    </div>
                </div>

                <div class="form-row" style="margin-top: 5px;">
                    <label>Block Body Content / Embed Shortcode</label>
                    <textarea name="body" id="modal_body" rows="6" placeholder="Enter HTML or embed tags like [view:machine_name] or [similar_content:2]"></textarea>
                    <small style="color: #64748b;">Supports full HTML markup, <code>[view:name]</code>, and <code>[similar_content:limit]</code>.</small>
                </div>

                <h4 style="color: var(--frnp-navy, #0b2545); margin: 20px 0 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; font-size: 0.98rem;">
                    Visibility Rules
                </h4>

                <div class="form-row">
                    <label>Page Visibility Mode</label>
                    <select name="visibility_mode" id="modal_vis_mode">
                        <option value="show_only">Only the listed pages</option>
                        <option value="show_except">All pages except those listed</option>
                    </select>
                </div>

                <div class="form-row">
                    <label>Pages (One per line)</label>
                    <textarea name="visibility_pages" id="modal_vis_pages" rows="3" placeholder="<front>&#10;news/*&#10;about-us"></textarea>
                    <small style="color: #64748b;">Specify paths relative to site root. Use <code>&lt;front&gt;</code> for the home page and <code>*</code> as a wildcard.</small>
                </div>

                <div class="form-row" style="margin-top: 15px;">
                    <label>Restrict to Content Types (Optional)</label>
                    <div class="checkbox-group-grid">
                        <?php foreach ($available_types as $ct): ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="visibility_types[]" class="chk-type" value="<?= htmlspecialchars($ct['type']) ?>">
                                <span><?= htmlspecialchars($ct['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-row" style="margin-top: 15px;">
                    <label>Restrict to User Roles (Optional)</label>
                    <div class="checkbox-group-grid">
                        <?php foreach ($available_roles as $role): ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="visibility_roles[]" class="chk-role" value="<?= htmlspecialchars($role['machine_name']) ?>">
                                <span><?= htmlspecialchars($role['label']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-row" style="margin-top: 15px;">
                    <label class="checkbox-label" style="font-weight: 600; color: var(--frnp-navy, #0b2545);">
                        <input type="checkbox" name="status" id="modal_status" value="1" checked>
                        <span>Active & Published</span>
                    </label>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px; border-top: 1px solid #e2e8f0; padding-top: 15px;">
                    <button type="button" class="btn-secondary" onclick="closeBlockModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Save Block</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openBlockModal(data = {}) {
            const isEdit = !!(data && data.id);
            document.getElementById('modalTitle').innerText = isEdit ? 'Edit Block: ' + (data.title || '') : 'Add New Block';
            document.getElementById('modal_block_id').value = isEdit ? data.id : 0;
            document.getElementById('modal_title').value = data.title || '';
            document.getElementById('modal_region').value = data.region || 'sidebar';
            document.getElementById('modal_weight').value = data.weight !== undefined ? data.weight : 0;
            document.getElementById('modal_body').value = data.body || '';
            document.getElementById('modal_vis_mode').value = data.visibility_mode || 'show_only';
            document.getElementById('modal_vis_pages').value = data.visibility_pages || '';
            document.getElementById('modal_status').checked = data.status === undefined || parseInt(data.status, 10) === 1;

            const selectedTypes = (data.visibility_types || '').split(',').map(s => s.trim());
            document.querySelectorAll('.chk-type').forEach(el => {
                el.checked = selectedTypes.includes(el.value);
            });

            const selectedRoles = (data.visibility_roles || '').split(',').map(s => s.trim());
            document.querySelectorAll('.chk-role').forEach(el => {
                el.checked = selectedRoles.includes(el.value);
            });

            document.getElementById('blockModal').style.display = 'flex';
        }

        function closeBlockModal() {
            document.getElementById('blockModal').style.display = 'none';
        }

        window.onclick = function(e) {
            const modal = document.getElementById('blockModal');
            if (e.target === modal) {
                closeBlockModal();
            }
        };
    </script>
</body>
</html>
