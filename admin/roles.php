<?php
// admin/roles.php - Role Management Console
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';

if (function_exists('require_permission')) {
    require_permission('manage_users');
} else {
    require_login();
}

$db = function_exists('get_db') ? get_db() : ($pdo ?? ($GLOBALS['db'] ?? null));
$page_title = 'Role Management';

// CSRF token generation
$csrf_token = function_exists('get_csrf_token') 
    ? get_csrf_token() 
    : ($_SESSION['csrf_token'] ?? ($_SESSION['csrf_token'] = bin2hex(random_bytes(16))));

// Inspect schema for table columns and linking tables dynamically
$role_cols = $db->query("SHOW COLUMNS FROM roles")->fetchAll(PDO::FETCH_COLUMN);
$has_machine_name = in_array('machine_name', $role_cols);
$has_label = in_array('label', $role_cols);
$has_desc = in_array('description', $role_cols);

$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$has_user_roles = in_array('user_roles', $tables);
$has_role_permissions = in_array('role_permissions', $tables);

/**
 * Determine if a role represents the protected system administrator
 */
function is_admin_role(array $role): bool {
    if ((int)$role['id'] === 1) {
        return true;
    }
    $m = strtolower(trim((string)($role['machine_name'] ?? $role['name'] ?? '')));
    $l = strtolower(trim((string)($role['label'] ?? $role['name'] ?? '')));
    return in_array($m, ['admin', 'administrator', 'superadmin'], true)
        || in_array($l, ['administrator', 'site administrator', 'admin'], true);
}

$alert_msg = '';
$alert_type = 'success';

// ---------------------------------------------------------
// POST Handlers (Create, Edit, Delete)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    if (function_exists('validate_csrf')) {
        validate_csrf();
    } else {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            die('CSRF token validation failed.');
        }
    }

    $action = $_POST['action'] ?? '';

    // 1. CREATE ROLE
    if ($action === 'create') {
        $label = trim($_POST['label'] ?? '');
        $machine_name = strtolower(trim($_POST['machine_name'] ?? ''));
        $description = trim($_POST['description'] ?? '');

        // Auto-generate machine name if left empty
        if ($machine_name === '' && $label !== '') {
            $machine_name = preg_replace('/[^a-z0-9_]+/', '_', strtolower($label));
            $machine_name = trim($machine_name, '_');
        }

        // Validate reserved words
        if (in_array($machine_name, ['admin', 'administrator', 'superadmin', 'root'], true)) {
            $alert_msg = 'The machine name "' . htmlspecialchars($machine_name) . '" is reserved and cannot be created.';
            $alert_type = 'danger';
        } elseif ($label === '' || $machine_name === '') {
            $alert_msg = 'Both Role Label and Machine Name are required.';
            $alert_type = 'danger';
        } elseif (!preg_match('/^[a-z0-9_]+$/', $machine_name)) {
            $alert_msg = 'Machine name can only contain lowercase alphanumeric characters and underscores.';
            $alert_type = 'danger';
        } else {
            // Check for uniqueness
            $check_field = $has_machine_name ? 'machine_name' : 'name';
            $check = $db->prepare("SELECT id FROM roles WHERE {$check_field} = ? LIMIT 1");
            $check->execute([$machine_name]);

            if ($check->fetch()) {
                $alert_msg = 'A role with machine name "' . htmlspecialchars($machine_name) . '" already exists.';
                $alert_type = 'danger';
            } else {
                $fields = [];
                $vals = [];

                if ($has_machine_name) {
                    $fields['machine_name'] = $machine_name;
                }
                if ($has_label) {
                    $fields['label'] = $label;
                }
                if (!$has_machine_name && in_array('name', $role_cols)) {
                    $fields['name'] = $machine_name;
                }
                if ($has_desc) {
                    $fields['description'] = $description;
                }

                $f_keys = array_keys($fields);
                $placeholders = implode(', ', array_fill(0, count($f_keys), '?'));
                $sql = "INSERT INTO roles (" . implode(', ', $f_keys) . ") VALUES ($placeholders)";
                
                $stmt = $db->prepare($sql);
                $stmt->execute(array_values($fields));

                $alert_msg = 'Role "' . htmlspecialchars($label) . '" successfully created.';
            }
        }
    }

    // 2. EDIT ROLE
    elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $description = trim($_POST['description'] ?? '');

        // Fetch current role
        $stmt = $db->prepare("SELECT * FROM roles WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $target_role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target_role) {
            $alert_msg = 'Selected role does not exist.';
            $alert_type = 'danger';
        } elseif (is_admin_role($target_role)) {
            // STRICT PROTECTION: Block editing admin role
            $alert_msg = 'The Administrator role is system-protected and cannot be edited.';
            $alert_type = 'danger';
        } elseif ($label === '') {
            $alert_msg = 'Role Label cannot be blank.';
            $alert_type = 'danger';
        } else {
            $updates = [];
            $params = [];

            if ($has_label) {
                $updates[] = "label = ?";
                $params[] = $label;
            } elseif (in_array('name', $role_cols)) {
                $updates[] = "name = ?";
                $params[] = $label;
            }

            if ($has_desc) {
                $updates[] = "description = ?";
                $params[] = $description;
            }

            $params[] = $id;
            $sql = "UPDATE roles SET " . implode(', ', $updates) . " WHERE id = ?";
            $up_stmt = $db->prepare($sql);
            $up_stmt->execute($params);

            $alert_msg = 'Role "' . htmlspecialchars($label) . '" updated successfully.';
        }
    }

    // 3. DELETE ROLE
    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        // Fetch current role
        $stmt = $db->prepare("SELECT * FROM roles WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $target_role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target_role) {
            $alert_msg = 'Role not found or already deleted.';
            $alert_type = 'danger';
        } elseif (is_admin_role($target_role)) {
            // STRICT PROTECTION: Block deleting admin role
            $alert_msg = 'Security Policy Violation: The Administrator role is system-protected and cannot be deleted.';
            $alert_type = 'danger';
        } else {
            // Verify if users are currently assigned to this role
            $assigned_count = 0;
            if ($has_user_roles) {
                $u_stmt = $db->prepare("SELECT COUNT(*) FROM user_roles WHERE role_id = ?");
                $u_stmt->execute([$id]);
                $assigned_count = (int)$u_stmt->fetchColumn();
            }

            if ($assigned_count > 0) {
                $role_name_str = htmlspecialchars($target_role['label'] ?? $target_role['machine_name'] ?? 'this role');
                $alert_msg = "Cannot delete '{$role_name_str}': There are currently {$assigned_count} user(s) assigned to this role. Please reassign those users in User Management first.";
                $alert_type = 'danger';
            } else {
                // Remove role_permissions mappings if table exists
                if ($has_role_permissions) {
                    $del_perm = $db->prepare("DELETE FROM role_permissions WHERE role_id = ?");
                    $del_perm->execute([$id]);
                }

                // Delete role
                $del_role = $db->prepare("DELETE FROM roles WHERE id = ?");
                $del_role->execute([$id]);

                $alert_msg = 'Role successfully deleted.';
            }
        }
    }
}

// ---------------------------------------------------------
// Query All Roles & User Counts
// ---------------------------------------------------------
$roles_query = "SELECT * FROM roles ORDER BY id ASC";
$roles_raw = $db->query($roles_query)->fetchAll(PDO::FETCH_ASSOC);

$roles = [];
foreach ($roles_raw as $r) {
    $rid = (int)$r['id'];
    $m_name = $r['machine_name'] ?? ($r['name'] ?? '');
    $l_name = $r['label'] ?? ($r['name'] ?? 'Role #' . $rid);
    $desc = $r['description'] ?? '';

    // Calculate assigned users
    $user_count = 0;
    if ($has_user_roles) {
        $c_stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM user_roles WHERE role_id = ?");
        $c_stmt->execute([$rid]);
        $user_count = (int)$c_stmt->fetchColumn();
    }

    $roles[] = [
        'id'           => $rid,
        'machine_name' => $m_name,
        'label'        => $l_name,
        'description'  => $desc,
        'user_count'   => $user_count,
        'is_admin'     => is_admin_role($r)
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Admin</title>
    <link rel="stylesheet" href="<?= base_path() ?>/assets/css/admin.css">
    <style>
        .role-badge-locked {
            background: #fef3c7;
            color: #92400e;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 4px;
            border: 1px solid #fde68a;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .role-badge-custom {
            background: #e0f2fe;
            color: #0369a1;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 4px;
            border: 1px solid #bae6fd;
        }
        .code-pill {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            background: #f1f5f9;
            color: #334155;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.82rem;
            border: 1px solid #e2e8f0;
        }
        .user-count-pill {
            background: #f8fafc;
            color: #475569;
            font-size: 0.8rem;
            padding: 2px 8px;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            font-weight: 600;
        }
        
        /* Modal Backdrop and Box */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(2px);
            z-index: 1050;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-card {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 520px;
            overflow: hidden;
            animation: modalFadeIn 0.15s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }
        .modal-header {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            margin: 0;
            font-size: 1.15rem;
            color: #1e293b;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: #64748b;
            cursor: pointer;
            line-height: 1;
            padding: 4px;
        }
        .modal-close:hover { color: #0f172a; }
        .modal-body { padding: 20px; }
        .modal-footer {
            padding: 12px 20px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
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
                <small class="text-muted" style="color:#718096;">Define user roles, manage editorial titles, and inspect privilege assignments.</small>
            </div>
            <div class="admin-actions" style="display:flex; gap:10px;">
                <a href="<?= base_path() ?>/admin/permissions.php" class="btn btn-secondary">Permissions Matrix ↗</a>
                <button type="button" class="btn btn-primary" onclick="openCreateModal()">+ Add New Role</button>
            </div>
        </div>

        <?php if (!empty($alert_msg)): ?>
            <div class="alert alert-<?= $alert_type ?>" style="padding:12px 16px; margin-bottom:18px; border-radius:4px; <?= $alert_type === 'danger' ? 'background:#fef2f2; color:#991b1b; border:1px solid #fecaca;' : 'background:#def7ec; color:#03543f; border:1px solid #bcf0da;' ?>">
                <?= htmlspecialchars($alert_msg) ?>
            </div>
        <?php endif; ?>

        <div class="card" style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:0; overflow:hidden;">
            <div class="table-responsive">
                <table class="table" style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9rem;">
                    <thead>
                        <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; color:#4a5568;">
                            <th style="padding:12px 16px; width:60px;">ID</th>
                            <th style="padding:12px 16px;">Role Name</th>
                            <th style="padding:12px 16px;">Machine Identifier</th>
                            <th style="padding:12px 16px;">Description</th>
                            <th style="padding:12px 16px; width:130px;">Assigned Users</th>
                            <th style="padding:12px 16px; width:120px;">Role Type</th>
                            <th style="padding:12px 16px; text-align:right; width:160px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($roles)): ?>
                            <tr>
                                <td colspan="7" style="padding:32px; text-align:center; color:#94a3b8;">
                                    No roles found in the database.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($roles as $r): ?>
                                <tr style="border-bottom:1px solid #edf2f7; <?= $r['is_admin'] ? 'background:#fffdfa;' : '' ?>">
                                    <td style="padding:12px 16px; color:#64748b; font-weight:600;">
                                        #<?= $r['id'] ?>
                                    </td>
                                    <td style="padding:12px 16px;">
                                        <strong style="color:#0f172a; font-size:0.95rem;"><?= htmlspecialchars($r['label']) ?></strong>
                                    </td>
                                    <td style="padding:12px 16px;">
                                        <span class="code-pill"><?= htmlspecialchars($r['machine_name']) ?></span>
                                    </td>
                                    <td style="padding:12px 16px; color:#64748b; font-size:0.85rem;">
                                        <?= !empty($r['description']) ? htmlspecialchars($r['description']) : '<em style="color:#cbd5e1;">None provided</em>' ?>
                                    </td>
                                    <td style="padding:12px 16px;">
                                        <span class="user-count-pill"><?= $r['user_count'] ?> user<?= $r['user_count'] === 1 ? '' : 's' ?></span>
                                    </td>
                                    <td style="padding:12px 16px;">
                                        <?php if ($r['is_admin']): ?>
                                            <span class="role-badge-locked" title="Protected system administrator role">🔒 Admin</span>
                                        <?php else: ?>
                                            <span class="role-badge-custom">User Role</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:12px 16px; text-align:right; white-space:nowrap;">
                                        <?php if ($r['is_admin']): ?>
                                            <span style="font-size:0.8rem; color:#94a3b8; font-style:italic;" title="Admin role cannot be modified or deleted">
                                                Locked
                                            </span>
                                        <?php else: ?>
                                            <button type="button" 
                                                    class="btn btn-secondary" 
                                                    style="padding:4px 8px; font-size:0.8rem;"
                                                    onclick="openEditModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['label'])) ?>', '<?= htmlspecialchars(addslashes($r['machine_name'])) ?>', '<?= htmlspecialchars(addslashes($r['description'])) ?>')">
                                                Edit
                                            </button>
                                            <button type="button" 
                                                    class="btn btn-danger" 
                                                    style="padding:4px 8px; font-size:0.8rem; background:#fee2e2; color:#991b1b; border:1px solid #fecaca;"
                                                    onclick="openDeleteModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['label'])) ?>', <?= $r['user_count'] ?>)">
                                                Delete
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================= -->
<!-- Modal: Create New Role                                    -->
<!-- ========================================================= -->
<div id="createRoleModal" class="modal-overlay">
    <div class="modal-card">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="create">

            <div class="modal-header">
                <h3>Create New Role</h3>
                <button type="button" class="modal-close" onclick="closeModal('createRoleModal')">&times;</button>
            </div>

            <div class="modal-body">
                <div class="form-group" style="margin-bottom:16px;">
                    <label for="create_label" style="display:block; font-weight:600; color:#334155; margin-bottom:6px; font-size:0.88rem;">
                        Role Name / Label <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="text" 
                           name="label" 
                           id="create_label" 
                           class="form-control" 
                           required 
                           placeholder="e.g., Content Editor"
                           onkeyup="handleAutoSlug(this.value)"
                           style="width:100%; box-sizing:border-box; padding:8px 12px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.92rem;">
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label for="create_machine_name" style="display:block; font-weight:600; color:#334155; margin-bottom:6px; font-size:0.88rem;">
                        Machine Name (Identifier) <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="text" 
                           name="machine_name" 
                           id="create_machine_name" 
                           class="form-control" 
                           required 
                           placeholder="e.g., content_editor"
                           pattern="[a-z0-9_]+"
                           style="width:100%; box-sizing:border-box; padding:8px 12px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.92rem; font-family:monospace;">
                    <small style="color:#64748b; font-size:0.75rem; display:block; margin-top:4px;">
                        Lowercase alphanumeric characters and underscores only. Used in system permission logic.
                    </small>
                </div>

                <div class="form-group">
                    <label for="create_description" style="display:block; font-weight:600; color:#334155; margin-bottom:6px; font-size:0.88rem;">
                        Description
                    </label>
                    <textarea name="description" 
                              id="create_description" 
                              rows="3" 
                              class="form-control" 
                              placeholder="Briefly describe the responsibilities associated with this role..."
                              style="width:100%; box-sizing:border-box; padding:8px 12px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.9rem; font-family:inherit;"></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('createRoleModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Role</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================= -->
<!-- Modal: Edit Existing Role                                 -->
<!-- ========================================================= -->
<div id="editRoleModal" class="modal-overlay">
    <div class="modal-card">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id" value="">

            <div class="modal-header">
                <h3>Edit Role</h3>
                <button type="button" class="modal-close" onclick="closeModal('editRoleModal')">&times;</button>
            </div>

            <div class="modal-body">
                <div class="form-group" style="margin-bottom:16px;">
                    <label style="display:block; font-weight:600; color:#334155; margin-bottom:6px; font-size:0.88rem;">
                        Machine Identifier
                    </label>
                    <input type="text" 
                           id="edit_machine_name" 
                           class="form-control" 
                           disabled 
                           style="width:100%; box-sizing:border-box; padding:8px 12px; border:1px solid #e2e8f0; border-radius:4px; font-size:0.92rem; font-family:monospace; background:#f8fafc; color:#64748b; cursor:not-allowed;">
                    <small style="color:#64748b; font-size:0.75rem; display:block; margin-top:4px;">
                        Machine keys cannot be modified once created to preserve system permission mappings.
                    </small>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label for="edit_label" style="display:block; font-weight:600; color:#334155; margin-bottom:6px; font-size:0.88rem;">
                        Role Name / Label <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="text" 
                           name="label" 
                           id="edit_label" 
                           class="form-control" 
                           required 
                           style="width:100%; box-sizing:border-box; padding:8px 12px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.92rem;">
                </div>

                <div class="form-group">
                    <label for="edit_description" style="display:block; font-weight:600; color:#334155; margin-bottom:6px; font-size:0.88rem;">
                        Description
                    </label>
                    <textarea name="description" 
                              id="edit_description" 
                              rows="3" 
                              class="form-control" 
                              style="width:100%; box-sizing:border-box; padding:8px 12px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.9rem; font-family:inherit;"></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editRoleModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================= -->
<!-- Modal: Confirm Delete Role                                -->
<!-- ========================================================= -->
<div id="deleteRoleModal" class="modal-overlay">
    <div class="modal-card">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete_id" value="">

            <div class="modal-header" style="background:#fef2f2; border-bottom:1px solid #fee2e2;">
                <h3 style="color:#991b1b;">Confirm Role Deletion</h3>
                <button type="button" class="modal-close" onclick="closeModal('deleteRoleModal')">&times;</button>
            </div>

            <div class="modal-body">
                <p style="color:#1f2937; margin-top:0; font-size:0.95rem; line-height:1.5;">
                    Are you sure you want to permanently delete the role <strong id="delete_role_name"></strong>?
                </p>
                
                <div id="delete_warning_box" style="display:none; padding:12px; background:#fffbeb; border:1px solid #fef3c7; border-radius:4px; color:#92400e; font-size:0.85rem; margin-top:10px;">
                    ⚠️ <strong>Assigned Users Detected:</strong> This role currently has <span id="delete_user_count">0</span> user(s) assigned. Deletion is disabled until these users are reassigned in User Management.
                </div>
                
                <p style="color:#64748b; font-size:0.85rem; margin-bottom:0; margin-top:12px;">
                    This action cannot be undone. Any permission entries tied to this role in the permission matrix will be permanently purged.
                </p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deleteRoleModal')">Cancel</button>
                <button type="submit" id="delete_submit_btn" class="btn btn-danger" style="background:#dc2626; color:#ffffff;">
                    Delete Role
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('create_label').value = '';
    document.getElementById('create_machine_name').value = '';
    document.getElementById('create_description').value = '';
    document.getElementById('createRoleModal').classList.add('active');
    document.getElementById('create_label').focus();
}

function handleAutoSlug(text) {
    const slugField = document.getElementById('create_machine_name');
    if (slugField.dataset.manual !== 'true') {
        slugField.value = text.toLowerCase()
            .replace(/[^a-z0-9_]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }
}

document.getElementById('create_machine_name').addEventListener('input', function() {
    this.dataset.manual = this.value.trim() !== '' ? 'true' : 'false';
});

function openEditModal(id, label, machineName, description) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_label').value = label;
    document.getElementById('edit_machine_name').value = machineName;
    document.getElementById('edit_description').value = description;
    document.getElementById('editRoleModal').classList.add('active');
    document.getElementById('edit_label').focus();
}

function openDeleteModal(id, label, userCount) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_role_name').textContent = `"${label}"`;

    const warningBox = document.getElementById('delete_warning_box');
    const submitBtn = document.getElementById('delete_submit_btn');
    const countSpan = document.getElementById('delete_user_count');

    if (userCount > 0) {
        warningBox.style.display = 'block';
        countSpan.textContent = userCount;
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.5';
        submitBtn.style.cursor = 'not-allowed';
    } else {
        warningBox.style.display = 'none';
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.style.cursor = 'pointer';
    }

    document.getElementById('deleteRoleModal').classList.add('active');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
    }
}

// Close modals when clicking backdrop
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal(this.id);
        }
    });
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            closeModal(modal.id);
        });
    }
});
</script>
</body>
</html>
