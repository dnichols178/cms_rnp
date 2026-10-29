<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('create_content');

$db = get_db();

// Handle individual deletion if requested and permitted
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    require_permission('delete_any_content');
    validate_csrf();
    $del = $db->prepare('DELETE FROM nodes WHERE id = :id');
    $del->execute(['id' => (int)$_GET['id']]);
    set_flash('success', 'Content node removed successfully.');
    header('Location: ' . base_path('admin/nodes.php'));
    exit;
}

// Handle Bulk Actions (Status Change, Unpublish, or Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && str_starts_with($_POST['action'], 'bulk_')) {
    validate_csrf();
    $node_ids = array_map('intval', $_POST['node_ids'] ?? []);

    if (empty($node_ids)) {
        set_flash('error', 'No content nodes were selected.');
    } else {
        $action = $_POST['action'];
        if ($action === 'bulk_delete') {
            require_permission('delete_any_content');
            $placeholders = implode(',', array_fill(0, count($node_ids), '?'));
            $del_stmt = $db->prepare("DELETE FROM nodes WHERE id IN ({$placeholders})");
            $del_stmt->execute($node_ids);
            set_flash('success', count($node_ids) . ' content node(s) permanently deleted.');
        } elseif ($action === 'bulk_unpublish') {
            require_permission('edit_any_content');
            $placeholders = implode(',', array_fill(0, count($node_ids), '?'));
            $params = array_merge(['draft'], $node_ids);
            $upd_stmt = $db->prepare("UPDATE nodes SET status = ? WHERE id IN ({$placeholders})");
            $upd_stmt->execute($params);
            set_flash('success', 'Successfully unpublished ' . count($node_ids) . ' node(s) (set to draft).');
        } elseif (str_starts_with($action, 'bulk_status_')) {
            $new_status = substr($action, strlen('bulk_status_'));
            $allowed_statuses = ['published', 'draft', 'in_review', 'archived'];
            
            if (in_array($new_status, $allowed_statuses, true)) {
                require_permission('edit_any_content');
                $placeholders = implode(',', array_fill(0, count($node_ids), '?'));
                $params = array_merge([$new_status], $node_ids);
                $upd_stmt = $db->prepare("UPDATE nodes SET status = ? WHERE id IN ({$placeholders})");
                $upd_stmt->execute($params);
                set_flash('success', 'Status updated to "' . ucfirst(str_replace('_', ' ', $new_status)) . '" for ' . count($node_ids) . ' node(s).');
            } else {
                set_flash('error', 'Invalid target publication status.');
            }
        }
    }
    header('Location: ' . base_path('admin/nodes.php'));
    exit;
}

// 1. Capture Filter Inputs
$filter_type   = trim($_GET['type'] ?? '');
$filter_author = (isset($_GET['author']) && $_GET['author'] !== '') ? (int)$_GET['author'] : null;
$filter_status = trim($_GET['status'] ?? '');
$date_basis    = in_array($_GET['date_basis'] ?? '', ['created', 'updated'], true) ? $_GET['date_basis'] : 'updated';
$date_from     = trim($_GET['date_from'] ?? '');
$date_to       = trim($_GET['date_to'] ?? '');

$has_filter = ($filter_type !== '' || $filter_author !== null || $filter_status !== '' || $date_from !== '' || $date_to !== '');

// 2. Build Dynamic Parameterized Query
$where = [];
$params = [];

if ($filter_type !== '') {
    $where[] = 'n.type = :type';
    $params['type'] = $filter_type;
}

if ($filter_author !== null) {
    $where[] = 'n.uid = :author';
    $params['author'] = $filter_author;
}

if ($filter_status !== '') {
    $where[] = 'n.status = :status';
    $params['status'] = $filter_status;
}

// Apply date bounds to selected column (updated_at vs created_at)
$date_col = ($date_basis === 'created') ? 'n.created_at' : 'n.updated_at';

if ($date_from !== '') {
    $where[] = "{$date_col} >= :date_from";
    $params['date_from'] = $date_from . ' 00:00:00';
}

if ($date_to !== '') {
    $where[] = "{$date_col} <= :date_to";
    $params['date_to'] = $date_to . ' 23:59:59';
}

$sql = '
    SELECT n.id, n.type, n.title, n.slug, n.status, n.created_at, n.updated_at, 
           ct.name AS content_type_label, u.username
    FROM nodes n
    JOIN content_types ct ON ct.type = n.type
    JOIN users u ON u.id = n.uid
';

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY n.updated_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$nodes = $stmt->fetchAll();

// 3. Lookup Datasets for Dropdowns
$types = get_content_types();
$authors = $db->query('SELECT id, username FROM users ORDER BY username ASC')->fetchAll();
$statuses = [
    'published' => 'Published',
    'draft'     => 'Draft',
    'in_review' => 'In Review',
    'archived'  => 'Archived'
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Content Manager - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>
        <main class="admin-main">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h1>Content Repository</h1>
                <div class="dropdown-add">
                    <span style="font-size: 0.88rem; font-weight: 600; color: #475569;">+ Add Content: </span>
                    <?php foreach ($types as $t): ?>
                        <a href="<?= base_path('admin/node_edit.php?type=' . urlencode($t['type'])) ?>" class="btn-primary" style="margin-left: 6px; padding: 6px 12px; font-size: 0.85rem;">
                            <?= htmlspecialchars($t['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php render_flash_messages(); ?>

            <!-- Node Filtering Bar -->
            <section class="card" style="padding: 16px 20px; margin-bottom: 20px;">
                <form method="GET" action="<?= base_path('admin/nodes.php') ?>">
                    <div class="filter-grid">
                        <!-- Content Type -->
                        <div class="form-row">
                            <label>Type</label>
                            <select name="type">
                                <option value="">All Types</option>
                                <?php foreach ($types as $t): ?>
                                    <option value="<?= htmlspecialchars($t['type']) ?>" <?= $filter_type === $t['type'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Author -->
                        <div class="form-row">
                            <label>Author</label>
                            <select name="author">
                                <option value="">All Authors</option>
                                <?php foreach ($authors as $a): ?>
                                    <option value="<?= (int)$a['id'] ?>" <?= $filter_author === (int)$a['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($a['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Status -->
                        <div class="form-row">
                            <label>Status</label>
                            <select name="status">
                                <option value="">All Statuses</option>
                                <?php foreach ($statuses as $k => $label): ?>
                                    <option value="<?= $k ?>" <?= $filter_status === $k ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Date Target Selection -->
                        <div class="form-row">
                            <label>Date Field</label>
                            <select name="date_basis">
                                <option value="updated" <?= $date_basis === 'updated' ? 'selected' : '' ?>>Modified Date</option>
                                <option value="created" <?= $date_basis === 'created' ? 'selected' : '' ?>>Created Date</option>
                            </select>
                        </div>

                        <!-- Date Range: From -->
                        <div class="form-row">
                            <label>From</label>
                            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                        </div>

                        <!-- Date Range: To -->
                        <div class="form-row">
                            <label>To</label>
                            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                        </div>

                        <!-- Actions -->
                        <div class="filter-actions">
                            <button type="submit" class="btn-primary" style="padding: 8px 18px;">Filter</button>
                            <?php if ($has_filter): ?>
                                <a href="<?= base_path('admin/nodes.php') ?>" class="btn-secondary" style="padding: 8px 14px; text-decoration: none;">Reset</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </section>

            <!-- Node Listing with Bulk Operations -->
            <form method="POST" action="<?= base_path('admin/nodes.php') ?>" id="bulkForm" onsubmit="handleBulkSubmit(event)">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">

                <section class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <div class="meta-counter" style="margin-bottom: 0;">
                            Showing <strong><?= count($nodes) ?></strong> node<?= count($nodes) === 1 ? '' : 's' ?>
                            <?= $has_filter ? 'matching active criteria' : 'total' ?>
                        </div>
                    </div>

                    <!-- Bulk Actions Toolbar -->
                    <?php if (!empty($nodes)): ?>
                        <div class="bulk-actions-bar">
                            <span style="font-size: 0.84rem; font-weight: 700; color: var(--primary-color);">Bulk Operations:</span>
                            <select name="action" id="bulkActionSelect" style="width: 220px; padding: 6px 10px; font-size: 0.85rem;">
                                <option value="">-- Choose Action --</option>
                                <option value="bulk_status_published">Set Status: Published</option>
                                <option value="bulk_status_draft">Set Status: Draft</option>
                                <option value="bulk_status_in_review">Set Status: In Review</option>
                                <option value="bulk_status_archived">Set Status: Archived</option>
                                <option value="bulk_unpublish">Unpublish Selected Nodes</option>
                                <?php if (user_has_permission('delete_any_content')): ?>
                                    <option value="bulk_delete" style="color: #c00;">Delete Selected Nodes</option>
                                <?php endif; ?>
                            </select>
                            <button type="submit" class="btn-secondary" style="padding: 6px 16px; font-size: 0.85rem;">Apply to Selected</button>
                        </div>
                    <?php endif; ?>

                    <table>
                        <thead>
                            <tr>
                                <th style="width: 36px; text-align: center;">
                                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                                </th>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Author</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Modified</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($nodes)): ?>
                                <tr><td colspan="8" style="text-align:center; color:#777; padding:24px;">No content nodes found matching your criteria.</td></tr>
                            <?php else: ?>
                                <?php foreach ($nodes as $n): ?>
                                <tr>
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="node_ids[]" value="<?= (int)$n['id'] ?>" class="node-checkbox">
                                    </td>
                                    <td>
                                        <strong><a href="<?= base_path(htmlspecialchars($n['slug'])) ?>"><?= htmlspecialchars($n['title']) ?></a></strong>
                                        <div style="font-size: 0.8rem; color: #666;">/<?= htmlspecialchars($n['slug']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($n['content_type_label']) ?></td>
                                    <td><?= htmlspecialchars($n['username']) ?></td>
                                    <td>
                                        <?php 
                                            $badgeClass = match($n['status']) {
                                                'published', '1' => 'status-published',
                                                'in_review'      => 'status-review',
                                                'archived'       => 'status-archived',
                                                default          => 'status-draft',
                                            };
                                        ?>
                                        <span class="badge-status <?= $badgeClass ?>">
                                            <?= htmlspecialchars(strtoupper(str_replace('_', ' ', (string)$n['status']))) ?>
                                        </span>
                                    </td>
                                    <td><?= date('M j, Y', strtotime($n['created_at'])) ?></td>
                                    <td>
                                        <span title="<?= htmlspecialchars($n['updated_at']) ?>">
                                            <?= date('M j, Y H:i', strtotime($n['updated_at'])) ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <a href="<?= base_path('admin/node_edit.php?id=' . $n['id']) ?>" class="btn-secondary" style="font-size:0.8rem; padding:4px 8px;">Edit</a>
                                        <?php if (user_has_permission('delete_any_content')): ?>
                                            <a href="<?= base_path('admin/nodes.php?action=delete&id=' . $n['id'] . '&csrf_token=' . get_csrf_token()) ?>" 
                                               data-confirm="Permanently delete this content node?" 
                                               data-confirm-title="Delete Content Node"
                                               data-confirm-btn="Delete"
                                               style="color: #c00; font-size: 0.8rem; margin-left: 8px; text-decoration: none;">Delete</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            </form>
        </main>
    </div>

    <script>
        function toggleSelectAll(master) {
            const checkboxes = document.querySelectorAll('.node-checkbox');
            checkboxes.forEach(cb => cb.checked = master.checked);
        }

        function handleBulkSubmit(e) {
            e.preventDefault();
            const actionSelect = document.getElementById('bulkActionSelect');
            const checkedBoxes = document.querySelectorAll('.node-checkbox:checked');

            if (!actionSelect.value) {
                alert('Please select a bulk operation action.');
                return false;
            }

            if (checkedBoxes.length === 0) {
                alert('Please select at least one content node.');
                return false;
            }

            let msg = '';
            let title = 'Confirm Bulk Action';
            let btnText = 'Apply';
            let isDanger = false;

            if (actionSelect.value === 'bulk_delete') {
                msg = `Permanently delete ${checkedBoxes.length} selected node(s)?`;
                title = 'Delete Content Nodes';
                btnText = 'Delete';
                isDanger = true;
            } else if (actionSelect.value === 'bulk_unpublish') {
                msg = `Unpublish ${checkedBoxes.length} selected node(s)?`;
                title = 'Unpublish Nodes';
                btnText = 'Unpublish';
                isDanger = false;
            } else {
                msg = `Apply publication status update to ${checkedBoxes.length} selected node(s)?`;
                title = 'Update Status';
                btnText = 'Apply';
                isDanger = false;
            }

            confirmModal(msg, function() {
                document.getElementById('bulkForm').submit();
            }, {
                title: title,
                confirmText: btnText,
                danger: isDanger
            });
        }
    </script>
</body>
</html>