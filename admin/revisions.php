<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('create_content');

$node_id = (int)($_GET['id'] ?? 0);
$node = get_node_by_id($node_id);

if (!$node) {
    die('Content node not found.');
}

if (isset($_GET['action']) && $_GET['action'] === 'rollback' && isset($_GET['rev_id'])) {
    require_permission('edit_any_content');
    validate_csrf();
    $rev_id = (int)$_GET['rev_id'];
    if (rollback_node_revision($rev_id, current_user()['id'])) {
        set_flash('success', "Successfully rolled back node to Revision #{$rev_id}.");
    } else {
        set_flash('error', "Failed to roll back node to Revision #{$rev_id}.");
    }
    header('Location: ' . base_path('admin/revisions.php?id=' . $node_id));
    exit;
}

$revisions = get_node_revisions($node_id);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Revisions: <?= htmlspecialchars($node['title']) ?> - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <h1>Revisions for: "<?= htmlspecialchars($node['title']) ?>"</h1>
            <p style="margin-bottom: 20px;"><a href="<?= base_path('admin/node_edit.php') ?>?type=<?= urlencode($node['type']) ?>&id=<?= $node['id'] ?>" class="btn-secondary">&laquo; Back to Node Editor</a></p>

            <?php render_flash_messages(); ?>

            <section class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Revision #</th>
                            <th>Timestamp</th>
                            <th>Editor</th>
                            <th>Log Message</th>
                            <th>Saved Title</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($revisions as $index => $rev): ?>
                        <tr style="<?= $index === 0 ? 'background: #f7fafc; font-weight: 500;' : '' ?>">
                            <td>#<?= $rev['id'] ?> <?= $index === 0 ? '<span class="badge-status status-published" style="margin-left: 5px;">Current</span>' : '' ?></td>
                            <td><?= date('Y-m-d H:i:s', strtotime($rev['created_at'])) ?></td>
                            <td><?= htmlspecialchars($rev['username']) ?></td>
                            <td><?= htmlspecialchars($rev['log_message'] ?? 'No notes') ?></td>
                            <td><?= htmlspecialchars($rev['title']) ?></td>
                            <td>
                                <?php if ($index > 0): ?>
                                    <a href="<?= base_path('admin/revisions.php') ?>?id=<?= $node['id'] ?>&action=rollback&rev_id=<?= $rev['id'] ?>&csrf_token=<?= get_csrf_token() ?>" 
                                       data-confirm="Roll back to this exact version? Current changes will be archived in revisions." 
                                       data-confirm-title="Roll Back Revision"
                                       data-confirm-btn="Rollback"
                                       data-confirm-danger="false"
                                       class="btn-secondary" style="font-size: 0.8rem; padding: 3px 8px;">Rollback</a>
                                <?php else: ?>
                                    <em style="color: #888;">Active Version</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>