<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_site');

$custom_dir = __DIR__ . '/../custom_pages';
if (!is_dir($custom_dir)) {
    mkdir($custom_dir, 0755, true);
}

// 1. Create a New Custom Page File
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_page') {
    validate_csrf();
    $slug = strtolower(trim(preg_replace('/[^a-z0-9_-]/', '', str_replace(' ', '-', $_POST['slug'] ?? ''))));
    $title = trim($_POST['title'] ?? 'Custom Function Page');

    if ($slug === '') {
        set_flash('error', 'Valid page slug is required.');
    } else {
        $target_file = $custom_dir . '/' . $slug . '.php';
        if (file_exists($target_file)) {
            set_flash('error', "A custom page with the filename '{$slug}.php' already exists.");
        } else {
            $starter_code = "<?php\ndeclare(strict_types=1);\n\n/**\n * Custom Page: {$title}\n * Route: " . base_path($slug) . "\n */\n\n\$page_title = \"" . addslashes($title) . " | Four Rivers Nuclear Partnership\";\n\$node['title'] = \"" . addslashes($title) . "\";\n\n\$db = get_db();\n?>\n\n<div class=\"custom-page-container\">\n    <p>Custom page logic and database hooks go here.</p>\n</div>\n";
            file_put_contents($target_file, $starter_code);
            watchdog('custom_pages', "Created custom page file: {$slug}.php", 'notice');
            set_flash('success', "Custom page '{$slug}.php' created successfully.");
        }
    }

    header('Location: ' . base_path('admin/custom_pages.php'));
    exit;
}

// 2. Discover Existing Custom Pages
$pages = [];
foreach (scandir($custom_dir) as $f) {
    if (str_ends_with($f, '.php')) {
        $slug = substr($f, 0, -4);
        $filepath = $custom_dir . '/' . $f;
        $pages[] = [
            'file'     => $f,
            'slug'     => $slug,
            'url'      => base_path($slug),
            'size'     => filesize($filepath),
            'modified' => filemtime($filepath)
        ];
    }
}

// 3. Fetch Tour Submissions if Table Exists
$db = get_db();
$tour_reservations = [];
try {
    $tour_reservations = $db->query("SELECT * FROM tour_reservations ORDER BY created_at DESC LIMIT 10")->fetchAll();
} catch (\Throwable $e) {
    // Table not created yet until first visit
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Custom Pages & Forms - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    <style>
        .grid-layout { display: grid; grid-template-columns: 1.5fr 1fr; gap: 25px; }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <h1>Structure &raquo; Custom Pages & Handlers</h1>
            <?php render_flash_messages(); ?>

            <div class="grid-layout">
                <!-- Page Files Table -->
                <section class="card">
                    <h3>Registered Custom Pages (<code>/custom_pages/</code>)</h3>
                    <p style="font-size:0.85rem; color:#666; margin-bottom:15px;">
                        Drop any PHP script into <code>/custom_pages/</code> to run arbitrary queries and logic wrapped in the FRNP layout.
                    </p>

                    <table>
                        <thead>
                            <tr><th>File</th><th>Clean URL Route</th><th>Size</th><th>Modified</th><th>Link</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pages)): ?>
                                <tr><td colspan="5" style="text-align:center; color:#777;">No custom page scripts found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($pages as $p): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($p['file']) ?></strong></td>
                                    <td><code><?= htmlspecialchars($p['url']) ?></code></td>
                                    <td><?= number_format($p['size'] / 1024, 1) ?> KB</td>
                                    <td style="font-size:0.8rem; color:#666;"><?= date('M j, Y H:i', $p['modified']) ?></td>
                                    <td><a href="<?= htmlspecialchars($p['url']) ?>" style="color:var(--frnp-navy); font-weight:600;">Visit &raquo;</a></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <!-- Create Page Starter -->
                <section class="card">
                    <h3>Create Custom Page Scaffold</h3>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="create_page">

                        <div class="form-row">
                            <label>Page Title *</label>
                            <input type="text" name="title" required placeholder="e.g. Contractor Badge Portal">
                        </div>

                        <div class="form-row">
                            <label>URL Slug (Filename) *</label>
                            <input type="text" name="slug" required placeholder="e.g. contractor-badge-portal">
                            <small style="color:#666;">Will create <code>custom_pages/[slug].php</code> accessible at <code><?= base_path('[slug]') ?></code>.</small>
                        </div>

                        <button type="submit" class="btn-primary" style="margin-top:10px;">Generate Scaffold</button>
                    </form>
                </section>
            </div>

            <!-- Tour Reservations Inbound Submissions -->
            <?php if (!empty($tour_reservations)): ?>
                <section class="card" style="margin-top:25px;">
                    <h3>Recent Tour Reservations</h3>
                    <table>
                        <thead>
                            <tr><th>Status</th><th>Submitted</th><th>Guest Name</th><th>Organization</th><th>Tour Date</th><th>Party</th><th>Email / Phone</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tour_reservations as $tr): ?>
                            <tr>
                                <td><span class="badge-status status-published"><?= strtoupper(htmlspecialchars($tr['status'])) ?></span></td>
                                <td style="font-size:0.8rem; color:#666;"><?= date('M j, Y H:i', strtotime($tr['created_at'])) ?></td>
                                <td><strong><?= htmlspecialchars($tr['full_name']) ?></strong></td>
                                <td><?= htmlspecialchars($tr['organization'] ?: '—') ?></td>
                                <td><strong><?= date('M j, Y', strtotime($tr['tour_date'])) ?></strong></td>
                                <td><?= $tr['attendees'] ?></td>
                                <td style="font-size:0.85rem;">
                                    <a href="mailto:<?= htmlspecialchars($tr['email']) ?>"><?= htmlspecialchars($tr['email']) ?></a><br>
                                    <span style="color:#666;"><?= htmlspecialchars($tr['phone']) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>