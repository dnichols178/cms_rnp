<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('create_content');

$upload_dir = __DIR__ . '/../uploads/files/';
$message = '';
$error = '';

// Handle file deletion (PRG Pattern)
if (isset($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['file'])) {
    require_permission('administer_site');
    $file_to_delete = basename($_GET['file']);
    $target = $upload_dir . $file_to_delete;
    if (file_exists($target) && unlink($target)) {
        set_flash('success', 'File removed successfully.');
    } else {
        set_flash('error', 'Unable to delete file.');
    }
    header('Location: ' . base_path('admin/media.php'));
    exit;
}

// Handle file upload (PRG Pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['upload_file'])) {
    $file = $_FILES['upload_file'];
    $allowed = ['pdf', 'docx', 'xlsx', 'png', 'jpg', 'jpeg', 'webp', 'txt'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed, true)) {
        set_flash('error', 'Disallowed extension. Allowed: ' . implode(', ', $allowed));
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        set_flash('error', 'Upload failed with error code: ' . $file['error']);
    } else {
        $clean_name = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $filename = sprintf('%s_%s.%s', $clean_name, bin2hex(random_bytes(4)), $ext);
        $destination = $upload_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            set_flash('success', "File '{$filename}' uploaded successfully.");
        } else {
            set_flash('error', 'Failed to write file to directory. Check folder permissions.');
        }
    }
    header('Location: ' . base_path('admin/media.php'));
    exit;
}

// Scan uploaded directory
$files = [];
if (is_dir($upload_dir)) {
    $scanned = scandir($upload_dir, SCANDIR_SORT_DESCENDING);
    foreach ($scanned as $item) {
        if ($item === '.' || $item === '..' || $item === '.htaccess') continue;
        $path = $upload_dir . $item;
        $files[] = [
            'name'     => $item,
            'size'     => filesize($path),
            'modified' => filemtime($path),
            'url'      => base_path('uploads/files/' . rawurlencode($item))
        ];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Media & Documents - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>
        <main class="admin-main">
            <h1>Document & Media Repository</h1>
            <?php render_flash_messages(); ?>
            <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?><div class="notice">File removed successfully.</div><?php endif; ?>
            <?php if ($error): ?><div class="notice" style="background:#fee; border-color:#c00; color:#c00;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <section class="card">
                <h3>Upload New Document / Asset</h3>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-row">
                        <label>Select File (PDF, DOCX, XLSX, Images)</label>
                        <input type="file" name="upload_file" required style="padding: 6px;">
                    </div>
                    <button type="submit" class="btn-primary">Upload Document</button>
                </form>
            </section>

            <section class="card">
                <h3>Stored Documents</h3>
                <table>
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Size</th>
                            <th>Uploaded</th>
                            <th>Direct Path</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($files)): ?>
                            <tr><td colspan="5" style="text-align:center; color:#777;">No uploaded assets found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($files as $f): ?>
                            <tr>
                                <td>
                                    <strong><a href="<?= $f['url'] ?>" target="_blank"><?= htmlspecialchars($f['name']) ?></a></strong>
                                </td>
                                <td><?= number_format($f['size'] / 1024, 1) ?> KB</td>
                                <td><?= date('M j, Y H:i', $f['modified']) ?></td>
                                <td>
                                    <input type="text" readonly value="<?= $f['url'] ?>" style="font-size: 0.8rem; padding: 4px; width: 260px;" onclick="this.select();">
                                </td>
                                <td>
                                    <a href="<?= $f['url'] ?>" target="_blank" class="btn-secondary" style="font-size:0.8rem; padding:4px 8px;">View</a>
                                    <?php if (user_has_permission('administer_site')): ?>
                                        <a href="<?= base_path('admin/media.php?action=delete&file=' . urlencode($f['name'])) ?>" 
                                           data-confirm="Permanently delete this file?" 
                                           data-confirm-title="Delete Media File"
                                           data-confirm-btn="Delete"
                                           style="color:#c00; margin-left: 8px; font-size: 0.85rem; text-decoration: none;">Delete</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</body>
</html>