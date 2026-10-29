<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_site');

$db = get_db();

// Handle redirect deletion (PRG Pattern)
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    validate_csrf();
    $del = $db->prepare('DELETE FROM url_redirects WHERE id = :id');
    $del->execute(['id' => (int)$_GET['id']]);
    set_flash('success', 'Redirect deleted successfully.');
    header('Location: ' . base_path('admin/redirects.php'));
    exit;
}

// Handle adding new redirect rule (PRG Pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_redirect') {
    validate_csrf();
    $source = trim($_POST['source_path'] ?? '', '/');
    $target = trim($_POST['target_path'] ?? '');
    $code   = (int)($_POST['status_code'] ?? 301);

    if ($source !== '' && $target !== '') {
        $stmt = $db->prepare('INSERT INTO url_redirects (source_path, target_path, status_code) VALUES (?, ?, ?)');
        $stmt->execute([$source, $target, $code]);
        set_flash('success', "Redirect rule created for '/{$source}'.");
    } else {
        set_flash('error', 'Both source path and target URL are required.');
    }
    header('Location: ' . base_path('admin/redirects.php'));
    exit;
}

$redirects = $db->query('SELECT * FROM url_redirects ORDER BY created_at DESC')->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>URL Redirects - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>
        <main class="admin-main">
            <h1>Configuration &raquo; URL Redirects</h1>
            <?php render_flash_messages(); ?>

            <section class="card">
                <h3>Active Redirect Rules</h3>
                <table>
                    <thead>
                        <tr><th>From Path</th><th>To Target</th><th>Status Code</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($redirects)): ?>
                            <tr><td colspan="4" style="text-align:center; color:#777;">No redirects configured.</td></tr>
                        <?php else: ?>
                            <?php foreach ($redirects as $r): ?>
                            <tr>
                                <td><code>/<?= htmlspecialchars($r['source_path']) ?></code></td>
                                <td><strong><?= htmlspecialchars($r['target_path']) ?></strong></td>
                                <td>HTTP <?= $r['status_code'] ?></td>
                                <td>
                                    <a href="<?= base_path('admin/redirects.php?action=delete&id=' . (int)$r['id'] . '&csrf_token=' . get_csrf_token()) ?>" 
                                       data-confirm="Delete this redirect rule?" 
                                       data-confirm-title="Delete Redirect"
                                       data-confirm-btn="Delete"
                                       style="color:#c00; text-decoration: none;">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h3>Add New Redirect</h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="add_redirect">
                    <div class="form-row">
                        <label>Source Path (Relative to <?= htmlspecialchars(base_path()) ?>, without leading slash) *</label>
                        <input type="text" name="source_path" required placeholder="e.g. old-environmental-page">
                    </div>
                    <div class="form-row">
                        <label>Target URL / Path *</label>
                        <input type="text" name="target_path" required placeholder="e.g. <?= htmlspecialchars(base_path('program-areas')) ?> or https://energy.gov">
                    </div>
                    <div class="form-row">
                        <label>Redirect Type</label>
                        <select name="status_code">
                            <option value="301">301 - Permanent</option>
                            <option value="302">302 - Temporary</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary">Save Redirect</button>
                </form>
            </section>
        </main>
    </div>
</body>
</html>