<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
if (file_exists(__DIR__ . '/../includes/SEO.php')) {
    require_once __DIR__ . '/../includes/SEO.php';
} elseif (file_exists(__DIR__ . '/../includes/seo.php')) {
    require_once __DIR__ . '/../includes/seo.php';
}
require_permission('create_content');

$db = function_exists('get_db') ? get_db() : ($pdo ?? ($GLOBALS['db'] ?? null));
$page_title = 'Content Repository';

// ---------------------------------------------------------
// 1. Sorting Parameter Resolution & Whitelist Validation
// ---------------------------------------------------------
$allowed_sorts = [
    'title'      => 'n.title',
    'modified'   => 'n.updated_at',
    'updated_at' => 'n.updated_at',
    'created'    => 'n.created_at',
    'created_at' => 'n.created_at'
];

$sort_by = $_GET['sort'] ?? 'modified';
if (!array_key_exists($sort_by, $allowed_sorts)) {
    $sort_by = 'modified';
}

$sort_dir = strtoupper($_GET['dir'] ?? 'DESC');
if (!in_array($sort_dir, ['ASC', 'DESC'], true)) {
    $sort_dir = ($sort_by === 'title') ? 'ASC' : 'DESC';
}

$order_clause = $allowed_sorts[$sort_by] . ' ' . $sort_dir;

// Helper to construct header sorting URLs while preserving other GET filters
function sort_url(string $column, string $current_sort, string $current_dir): string {
    $params = $_GET;
    $params['sort'] = $column;
    
    // Toggle direction if currently sorted by this column; otherwise default
    if ($current_sort === $column || ($column === 'modified' && $current_sort === 'updated_at') || ($column === 'created' && $current_sort === 'created_at')) {
        $params['dir'] = ($current_dir === 'ASC') ? 'desc' : 'asc';
    } else {
        $params['dir'] = ($column === 'title') ? 'asc' : 'desc';
    }
    
    return '?' . http_build_query($params);
}

// ---------------------------------------------------------
// 2. Filter Parameters & Dynamic Query Building
// ---------------------------------------------------------
$filter_type   = trim($_GET['type'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$filter_author = trim($_GET['author'] ?? '');
$filter_search = trim($_GET['search'] ?? '');

$where_clauses = [];
$params = [];

if ($filter_type !== '') {
    $where_clauses[] = 'n.type = :type';
    $params[':type'] = $filter_type;
}

if ($filter_status !== '') {
    if ($filter_status === 'published' || $filter_status === '1') {
        $where_clauses[] = "(n.status = 'published' OR n.status = '1')";
    } elseif ($filter_status === 'draft' || $filter_status === '0') {
        $where_clauses[] = "(n.status = 'draft' OR n.status = '0')";
    } else {
        $where_clauses[] = 'n.status = :status';
        $params[':status'] = $filter_status;
    }
}

if ($filter_author !== '') {
    $where_clauses[] = 'n.uid = :author';
    $params[':author'] = (int)$filter_author;
}

if ($filter_search !== '') {
    $where_clauses[] = '(n.title LIKE :search OR n.slug LIKE :search_slug)';
    $params[':search'] = '%' . $filter_search . '%';
    $params[':search_slug'] = '%' . $filter_search . '%';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// ---------------------------------------------------------
// 3. Bulk Actions Handler
// ---------------------------------------------------------
$alert_msg = '';
$alert_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'], $_POST['selected_nodes'])) {
    $action = $_POST['bulk_action'];
    $selected_ids = array_filter(array_map('intval', (array)$_POST['selected_nodes']));

    if (!empty($selected_ids)) {
        $in_placeholders = implode(',', array_fill(0, count($selected_ids), '?'));

        if ($action === 'publish') {
            $stmt = $db->prepare("UPDATE nodes SET status = 'published', updated_at = NOW() WHERE id IN ($in_placeholders)");
            $stmt->execute($selected_ids);
            $alert_msg = count($selected_ids) . ' node(s) successfully published.';
        } elseif ($action === 'unpublish') {
            $stmt = $db->prepare("UPDATE nodes SET status = 'draft', updated_at = NOW() WHERE id IN ($in_placeholders)");
            $stmt->execute($selected_ids);
            $alert_msg = count($selected_ids) . ' node(s) successfully marked as draft.';
        } elseif ($action === 'delete') {
            $stmt = $db->prepare("DELETE FROM nodes WHERE id IN ($in_placeholders)");
            $stmt->execute($selected_ids);
            $alert_msg = count($selected_ids) . ' node(s) successfully deleted.';
            $alert_type = 'warning';
        }
    }
}

// ---------------------------------------------------------
// 4. Fetch Available Filter Options, Content Types & Nodes
// ---------------------------------------------------------
$content_types = $db->query("SELECT * FROM content_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$authors = $db->query("SELECT DISTINCT u.id, u.username FROM users u INNER JOIN nodes n ON n.uid = u.id ORDER BY u.username ASC")->fetchAll(PDO::FETCH_ASSOC);

// Primary query with dynamic WHERE and validated ORDER BY
$sql = "
    SELECT n.*, 
           COALESCE(ct.name, n.type) AS type_label,
           COALESCE(u.username, 'System') AS author_name
    FROM nodes n
    LEFT JOIN content_types ct ON ct.type = n.type
    LEFT JOIN users u ON u.id = n.uid
    $where_sql
    ORDER BY $order_clause
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Admin</title>
    <link rel="stylesheet" href="<?= base_path() ?>/assets/css/admin.css">
    <style>
        .filter-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px; margin-bottom: 20px; }
        .filter-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 140px; }
        .filter-group label { display: block; font-size: 0.8rem; font-weight: 600; color: #4a5568; margin-bottom: 4px; }
        .filter-group select, .filter-group input { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 0.88rem; box-sizing: border-box; }
        
        /* Sortable Table Headers */
        th.sortable { white-space: nowrap; }
        th.sortable a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
        th.sortable a:hover { color: #3182ce; }
        .sort-indicator { font-size: 0.8rem; color: #a0aec0; }
        .sort-indicator.active { color: #3182ce; font-weight: bold; }
        
        .bulk-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .badge-status { padding: 3px 8px; border-radius: 3px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .badge-published { background: #def7ec; color: #03543f; }
        .badge-draft { background: #fef08a; color: #713f12; }
        .badge-review { background: #e0f2fe; color: #075985; }
        .badge-archived { background: #f1f5f9; color: #475569; }

        /* Content Type Dropdown Menu */
        .content-type-menu {
            display: none;
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            min-width: 250px;
            z-index: 1000;
            overflow: hidden;
            text-align: left;
        }
        .content-type-item {
            display: block;
            padding: 10px 14px;
            color: #1e293b;
            text-decoration: none;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.15s ease;
        }
        .content-type-item:last-child {
            border-bottom: none;
        }
        .content-type-item:hover {
            background: #f8fafc;
        }
    </style>
</head>
<body class="admin-body">
<div class="admin-wrapper">
    <?php require __DIR__ . '/nav.php'; ?>

    <div class="admin-main">
        <div class="admin-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #e2e8f0; padding-bottom:14px;">
            <div>
                <h1 class="page-title" style="margin:0; font-size:1.5rem;"><?= htmlspecialchars($page_title) ?></h1>
                <small class="text-muted" style="color:#718096;">Manage, edit, publish, and audit site content nodes.</small>
            </div>
            <div class="admin-actions" style="position: relative;">
                <button type="button" class="btn btn-primary" id="createContentBtn" onclick="toggleContentTypeMenu(event)">
                    + Create Content <span style="font-size: 0.72rem; margin-left: 5px;">▼</span>
                </button>
                <div id="contentTypeDropdown" class="content-type-menu">
                    <div style="padding: 8px 14px; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; background: #f8fafc; border-bottom: 1px solid #e2e8f0; letter-spacing: 0.5px;">
                        Select Content Type
                    </div>
                    <?php if (!empty($content_types)): ?>
                        <?php foreach ($content_types as $ct): ?>
                            <a href="<?= base_path() ?>/admin/node_edit.php?type=<?= urlencode((string)$ct['type']) ?>" class="content-type-item">
                                <strong style="color: #0f172a; display: block; font-size: 0.92rem;"><?= htmlspecialchars($ct['name']) ?></strong>
                                <?php if (!empty($ct['description'])): ?>
                                    <div style="color: #64748b; font-size: 0.78rem; margin-top: 2px; line-height: 1.35;"><?= htmlspecialchars($ct['description']) ?></div>
                                <?php else: ?>
                                    <small style="color: #94a3b8; font-size: 0.75rem;">Type: <?= htmlspecialchars($ct['type']) ?></small>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <a href="<?= base_path() ?>/admin/node_edit.php?type=page" class="content-type-item">
                            <strong style="color: #0f172a; display: block; font-size: 0.92rem;">Basic Page</strong>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($alert_msg)): ?>
            <div class="alert alert-<?= $alert_type ?>" style="padding:12px; margin-bottom:16px; border-radius:4px; <?= $alert_type === 'warning' ? 'background:#feecdc; color:#8a2c0d;' : 'background:#def7ec; color:#03543f;' ?>">
                <?= htmlspecialchars($alert_msg) ?>
            </div>
        <?php endif; ?>

        <!-- Granular Filter & Sort Bar -->
        <div class="filter-card">
            <form method="GET" action="" class="filter-row">
                <div class="filter-group" style="flex: 2; min-width: 180px;">
                    <label for="search">Keyword Search</label>
                    <input type="text" name="search" id="search" placeholder="Filter by title or slug..." value="<?= htmlspecialchars($filter_search) ?>">
                </div>

                <div class="filter-group">
                    <label for="type">Content Type</label>
                    <select name="type" id="type">
                        <option value="">All Types</option>
                        <?php foreach ($content_types as $ct): ?>
                            <option value="<?= htmlspecialchars($ct['type']) ?>" <?= $filter_type === $ct['type'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ct['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="status">Status</label>
                    <select name="status" id="status">
                        <option value="">All Statuses</option>
                        <option value="published" <?= in_array($filter_status, ['published', '1'], true) ? 'selected' : '' ?>>Published</option>
                        <option value="draft" <?= in_array($filter_status, ['draft', '0'], true) ? 'selected' : '' ?>>Draft</option>
                        <option value="in_review" <?= $filter_status === 'in_review' ? 'selected' : '' ?>>In Review</option>
                        <option value="archived" <?= $filter_status === 'archived' ? 'selected' : '' ?>>Archived</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="author">Author</label>
                    <select name="author" id="author">
                        <option value="">All Authors</option>
                        <?php foreach ($authors as $auth): ?>
                            <option value="<?= (int)$auth['id'] ?>" <?= $filter_author === (string)$auth['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($auth['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Sort By Dropdown -->
                <div class="filter-group">
                    <label for="sort">Sort By</label>
                    <select name="sort" id="sort">
                        <option value="modified" <?= in_array($sort_by, ['modified', 'updated_at']) ? 'selected' : '' ?>>Modified Date</option>
                        <option value="created" <?= in_array($sort_by, ['created', 'created_at']) ? 'selected' : '' ?>>Created Date</option>
                        <option value="title" <?= $sort_by === 'title' ? 'selected' : '' ?>>Title (Alphabetical)</option>
                    </select>
                </div>

                <!-- Direction Dropdown -->
                <div class="filter-group" style="max-width: 130px;">
                    <label for="dir">Order</label>
                    <select name="dir" id="dir">
                        <option value="DESC" <?= $sort_dir === 'DESC' ? 'selected' : '' ?>>Descending</option>
                        <option value="ASC" <?= $sort_dir === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                    </select>
                </div>

                <div style="display:flex; gap:8px;">
                    <button type="submit" class="btn btn-secondary" style="padding: 8px 16px;">Filter & Sort</button>
                    <?php if (!empty($_GET)): ?>
                        <a href="<?= base_path() ?>/admin/nodes.php" class="btn btn-secondary" style="padding: 8px 12px; text-decoration:none;" title="Reset Filters">✕</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Node Results & Bulk Operations Form -->
        <form method="POST" action="" id="nodesForm">
            <div class="bulk-bar">
                <div style="display:flex; align-items:center; gap:8px;">
                    <select name="bulk_action" style="padding:6px 10px; border:1px solid #cbd5e0; border-radius:4px; font-size:0.88rem;">
                        <option value="">Bulk Actions</option>
                        <option value="publish">Publish</option>
                        <option value="unpublish">Unpublish (Set to Draft)</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button type="submit" class="btn btn-secondary" style="padding:6px 12px; font-size:0.85rem;" onclick="return confirmBulkAction();">Apply</button>
                </div>
                <div style="font-size:0.85rem; color:#718096;">
                    Showing <strong><?= count($nodes) ?></strong> item(s)
                </div>
            </div>

            <div class="card" style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:0; overflow:hidden;">
                <div class="table-responsive">
                    <table class="table" style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9rem;">
                        <thead>
                            <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; color:#4a5568;">
                                <th style="padding:12px; width:30px; text-align:center;">
                                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                                </th>
                                
                                <!-- Sortable Title Header -->
                                <th class="sortable" style="padding:12px;">
                                    <a href="<?= sort_url('title', $sort_by, $sort_dir) ?>" title="Sort by Title">
                                        Title
                                        <span class="sort-indicator <?= $sort_by === 'title' ? 'active' : '' ?>">
                                            <?= $sort_by === 'title' ? ($sort_dir === 'ASC' ? '▲' : '▼') : '↕' ?>
                                        </span>
                                    </a>
                                </th>

                                <th style="padding:12px;">Type</th>
                                <th style="padding:12px;">Author</th>
                                <th style="padding:12px;">Status</th>
                                
                                <!-- Sortable Created Date Header -->
                                <th class="sortable" style="padding:12px;">
                                    <a href="<?= sort_url('created', $sort_by, $sort_dir) ?>" title="Sort by Created Date">
                                        Created
                                        <span class="sort-indicator <?= in_array($sort_by, ['created', 'created_at']) ? 'active' : '' ?>">
                                            <?= in_array($sort_by, ['created', 'created_at']) ? ($sort_dir === 'ASC' ? '▲' : '▼') : '↕' ?>
                                        </span>
                                    </a>
                                </th>

                                <!-- Sortable Modified Date Header -->
                                <th class="sortable" style="padding:12px;">
                                    <a href="<?= sort_url('modified', $sort_by, $sort_dir) ?>" title="Sort by Modified Date">
                                        Modified
                                        <span class="sort-indicator <?= in_array($sort_by, ['modified', 'updated_at']) ? 'active' : '' ?>">
                                            <?= in_array($sort_by, ['modified', 'updated_at']) ? ($sort_dir === 'ASC' ? '▲' : '▼') : '↕' ?>
                                        </span>
                                    </a>
                                </th>

                                <th style="padding:12px; text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($nodes)): ?>
                                <tr>
                                    <td colspan="8" style="padding:32px; text-align:center; color:#a0aec0;">
                                        No content nodes found matching your criteria.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($nodes as $node_item): ?>
                                    <tr style="border-bottom:1px solid #edf2f7;">
                                        <td style="padding:12px; text-align:center;">
                                            <input type="checkbox" name="selected_nodes[]" value="<?= (int)$node_item['id'] ?>" class="node-checkbox">
                                        </td>
                                        <td style="padding:12px;">
                                            <a href="<?= base_path() ?>/admin/node_edit.php?id=<?= (int)$node_item['id'] ?>" style="font-weight:600; color:#2b6cb0; text-decoration:none;">
                                                <?= htmlspecialchars($node_item['title']) ?>
                                            </a>
                                            <div style="font-size:0.75rem; color:#718096; margin-top:2px;">
                                                /<?= htmlspecialchars($node_item['slug']) ?>
                                            </div>
                                        </td>
                                        <td style="padding:12px;">
                                            <span style="background:#edf2f7; color:#4a5568; padding:2px 8px; border-radius:3px; font-size:0.8rem;">
                                                <?= htmlspecialchars($node_item['type_label']) ?>
                                            </span>
                                        </td>
                                        <td style="padding:12px; color:#4a5568;">
                                            <?= htmlspecialchars($node_item['author_name']) ?>
                                        </td>
                                        <td style="padding:12px;">
                                            <?php
                                                $st = strtolower((string)$node_item['status']);
                                                if ($st === 'published' || $st === '1'):
                                            ?>
                                                <span class="badge-status badge-published">Published</span>
                                            <?php elseif ($st === 'in_review'): ?>
                                                <span class="badge-status badge-review">In Review</span>
                                            <?php elseif ($st === 'archived'): ?>
                                                <span class="badge-status badge-archived">Archived</span>
                                            <?php else: ?>
                                                <span class="badge-status badge-draft">Draft</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding:12px; white-space:nowrap; color:#4a5568; font-size:0.85rem;">
                                            <?= date('M j, Y', strtotime($node_item['created_at'])) ?><br>
                                            <small style="color:#a0aec0;"><?= date('g:i A', strtotime($node_item['created_at'])) ?></small>
                                        </td>
                                        <td style="padding:12px; white-space:nowrap; color:#4a5568; font-size:0.85rem;">
                                            <?= date('M j, Y', strtotime($node_item['updated_at'])) ?><br>
                                            <small style="color:#a0aec0;"><?= date('g:i A', strtotime($node_item['updated_at'])) ?></small>
                                        </td>
                                        <td style="padding:12px; text-align:right; white-space:nowrap;">
                                            <a href="<?= base_path() ?>/admin/node_edit.php?id=<?= (int)$node_item['id'] ?>" class="btn btn-secondary" style="padding:4px 8px; font-size:0.8rem;">Edit</a>
                                            <a href="<?= base_path() ?>/<?= htmlspecialchars($node_item['slug']) ?>" target="_blank" class="btn btn-secondary" style="padding:4px 8px; font-size:0.8rem;" title="View Live">↗</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function toggleContentTypeMenu(event) {
    event.stopPropagation();
    const menu = document.getElementById('contentTypeDropdown');
    if (!menu) return;
    menu.style.display = (menu.style.display === 'none' || menu.style.display === '') ? 'block' : 'none';
}

document.addEventListener('click', function(e) {
    const menu = document.getElementById('contentTypeDropdown');
    const btn = document.getElementById('createContentBtn');
    if (menu && menu.style.display === 'block') {
        if (!menu.contains(e.target) && !btn.contains(e.target)) {
            menu.style.display = 'none';
        }
    }
});

function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.node-checkbox');
    checkboxes.forEach(cb => cb.checked = master.checked);
}

function confirmBulkAction() {
    const action = document.querySelector('select[name="bulk_action"]').value;
    const checked = document.querySelectorAll('.node-checkbox:checked');
    if (!action) {
        alert('Please select a bulk action.');
        return false;
    }
    if (checked.length === 0) {
        alert('Please select at least one item.');
        return false;
    }
    if (action === 'delete') {
        return confirm(`Are you sure you want to permanently delete the ${checked.length} selected node(s)?`);
    }
    return true;
}
</script>
</body>
</html>
