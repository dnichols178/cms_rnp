<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_site');

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matrix = $_POST['perm'] ?? []; // [role_id => [perm_id => "1"]]
    
    // Clear and rebuild role_permissions for non-admin roles
    $roles = $db->query('SELECT id, machine_name FROM roles')->fetchAll();
    foreach ($roles as $r) {
        if ($r['machine_name'] === 'administrator') continue; // Administrator always has everything
        $del = $db->prepare('DELETE FROM role_permissions WHERE role_id = :rid');
        $del->execute(['rid' => $r['id']]);

        if (isset($matrix[$r['id']]) && is_array($matrix[$r['id']])) {
            $ins = $db->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
            foreach (array_keys($matrix[$r['id']]) as $perm_id) {
                $ins->execute([$r['id'], (int)$perm_id]);
            }
        }
    }

    set_flash('success', 'Permission matrix updated successfully.');
    header('Location: ' . base_path('admin/permissions.php'));
    exit;
}

$roles = $db->query('SELECT * FROM roles ORDER BY id ASC')->fetchAll();
$permissions = $db->query('SELECT * FROM permissions ORDER BY id ASC')->fetchAll();

// Map current state
$current_map = [];
$rp = $db->query('SELECT role_id, permission_id FROM role_permissions')->fetchAll();
foreach ($rp as $row) {
    $current_map[$row['role_id']][$row['permission_id']] = true;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Permission Matrix - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>
        <main class="admin-main">
            <h1>People &raquo; Permissions Matrix</h1>
            <?php render_flash_messages(); ?>

            <form method="POST" class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Permission</th>
                            <?php foreach ($roles as $r): ?>
                                <th><?= htmlspecialchars($r['label']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($permissions as $p): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($p['machine_name']) ?></strong>
                                <small style="display:block; color:#666;"><?= htmlspecialchars($p['description']) ?></small>
                            </td>
                            <?php foreach ($roles as $r): ?>
                                <td style="text-align: center;">
                                    <?php if ($r['machine_name'] === 'administrator'): ?>
                                        <input type="checkbox" checked disabled>
                                    <?php else: ?>
                                        <input type="checkbox" name="perm[<?= $r['id'] ?>][<?= $p['id'] ?>]" value="1"
                                            <?= isset($current_map[$r['id']][$p['id']]) ? 'checked' : '' ?>>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="margin-top: 20px;">
                    <button type="submit" class="btn-primary">Save Permissions</button>
                </div>
            </form>
        </main>
    </div>
</body>
</html>