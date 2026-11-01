<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/cache.php';

require_permission('administer_site');

$db = get_db();
$base_upload_dir = realpath(__DIR__ . '/../uploads');
if (!$base_upload_dir || !is_dir($base_upload_dir)) {
    mkdir(__DIR__ . '/../uploads', 0755, true);
    $base_upload_dir = realpath(__DIR__ . '/../uploads');
}

// Subfolder navigation within uploads/
$rel_subpath = trim(str_replace(['../', '..\\'], '', $_GET['dir'] ?? ''), '/\\');
$target_dir = $base_upload_dir . ($rel_subpath !== '' ? '/' . $rel_subpath : '');
$target_dir = realpath($target_dir);

// Prevent directory traversal outside uploads
if (!$target_dir || !str_starts_with($target_dir, $base_upload_dir)) {
    $target_dir = $base_upload_dir;
    $rel_subpath = '';
}

// Target input field passed from opener if any
$target_input = trim($_GET['target'] ?? '');
$redirect_url = base_path('admin/file_manager.php' . ($rel_subpath !== '' ? '?dir=' . urlencode($rel_subpath) : '') . ($target_input ? ($rel_subpath !== '' ? '&' : '?') . 'target=' . urlencode($target_input) : ''));

// 1. Handle File Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_upload']) && isset($_FILES['upload_file'])) {
    validate_csrf();
    $file = $_FILES['upload_file'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $orig_name = basename($file['name']);
        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

        $disallowed = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'cgi', 'pl', 'py', 'exe', 'sh', 'bash', 'bat', 'htaccess'];
        if (in_array($ext, $disallowed, true)) {
            set_flash('error', "Security Warning: Uploading .{$ext} executable files is prohibited.");
        } else {
            $safe_name = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($orig_name, PATHINFO_FILENAME));
            $final_name = $safe_name . '.' . $ext;
            $dest = $target_dir . '/' . $final_name;

            $c = 1;
            while (file_exists($dest)) {
                $final_name = $safe_name . '_' . $c . '.' . $ext;
                $dest = $target_dir . '/' . $final_name;
                $c++;
            }

            if (move_uploaded_file($file['tmp_name'], $dest)) {
                chmod($dest, 0644);
                watchdog('media', "Uploaded file: {$final_name}", 'notice');
                set_flash('success', "File '{$final_name}' uploaded successfully.");
            } else {
                set_flash('error', 'Failed to move uploaded file to destination.');
            }
        }
    } else {
        set_flash('error', 'File upload failed with error code: ' . $file['error']);
    }
    header('Location: ' . $redirect_url);
    exit;
}

// 2. Handle File Replacement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_replace']) && isset($_FILES['replace_file'])) {
    validate_csrf();
    $target_filename = basename(trim($_POST['target_file'] ?? ''));
    $file = $_FILES['replace_file'];

    if ($target_filename === '') {
        set_flash('error', 'Target file to replace was not specified.');
    } elseif ($file['error'] === UPLOAD_ERR_OK) {
        $new_ext = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
        $disallowed = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'cgi', 'pl', 'py', 'exe', 'sh', 'bash', 'bat', 'htaccess'];

        if (in_array($new_ext, $disallowed, true)) {
            set_flash('error', "Security Warning: Replacing with a .{$new_ext} executable file is prohibited.");
        } else {
            $dest = $target_dir . '/' . $target_filename;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                chmod($dest, 0644);
                watchdog('media', "Replaced file: {$target_filename}", 'notice');
                set_flash('success', "File '{$target_filename}' has been replaced successfully.");
            } else {
                set_flash('error', "Failed to replace file '{$target_filename}'.");
            }
        }
    } else {
        set_flash('error', 'Replacement file upload failed with error code: ' . $file['error']);
    }
    header('Location: ' . $redirect_url);
    exit;
}

// 3. Handle Create Directory
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_folder'])) {
    validate_csrf();
    $folder_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($_POST['folder_name'] ?? ''));

    if ($folder_name === '') {
        set_flash('error', 'Folder name cannot be blank.');
    } else {
        $new_folder_path = $target_dir . '/' . $folder_name;
        if (file_exists($new_folder_path)) {
            set_flash('error', "Directory '{$folder_name}' already exists.");
        } else {
            if (mkdir($new_folder_path, 0755, true)) {
                watchdog('media', "Created directory: {$folder_name}", 'notice');
                set_flash('success', "Directory '{$folder_name}' created successfully.");
            } else {
                set_flash('error', "Failed to create directory '{$folder_name}'.");
            }
        }
    }
    header('Location: ' . $redirect_url);
    exit;
}

// 4. Handle Rename File or Folder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_rename'])) {
    validate_csrf();
    $old_name = basename(trim($_POST['old_name'] ?? ''));
    $new_name = trim($_POST['new_name'] ?? '');

    $source = $target_dir . '/' . $old_name;
    if (!file_exists($source) || $old_name === '') {
        set_flash('error', 'Item to rename could not be found.');
    } else {
        $is_dir = is_dir($source);
        if ($is_dir) {
            $clean_new_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $new_name);
        } else {
            $ext = pathinfo($old_name, PATHINFO_EXTENSION);
            $new_base = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($new_name, PATHINFO_FILENAME));
            $clean_new_name = $new_base . ($ext !== '' ? '.' . $ext : '');
        }

        $dest = $target_dir . '/' . $clean_new_name;
        if (file_exists($dest)) {
            set_flash('error', "An item named '{$clean_new_name}' already exists.");
        } elseif (rename($source, $dest)) {
            watchdog('media', "Renamed '{$old_name}' to '{$clean_new_name}'.", 'notice');
            set_flash('success', "Renamed to '{$clean_new_name}'.");
        } else {
            set_flash('error', "Failed to rename '{$old_name}'.");
        }
    }
    header('Location: ' . $redirect_url);
    exit;
}

// 5. Handle Delete File / Directory
if (isset($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['file'])) {
    validate_csrf();
    $del_name = basename($_GET['file']);
    $del_path = $target_dir . '/' . $del_name;

    if (file_exists($del_path)) {
        if (is_dir($del_path)) {
            if (@rmdir($del_path)) {
                watchdog('media', "Deleted directory: {$del_name}", 'notice');
                set_flash('success', "Directory '{$del_name}' deleted.");
            } else {
                set_flash('error', "Directory '{$del_name}' is not empty or cannot be deleted.");
            }
        } else {
            if (@unlink($del_path)) {
                watchdog('media', "Deleted file: {$del_name}", 'notice');
                set_flash('success', "File '{$del_name}' deleted.");
            } else {
                set_flash('error', "Failed to delete file '{$del_name}'.");
            }
        }
    }
    header('Location: ' . $redirect_url);
    exit;
}

// 6. Scan Folder Tree for Sidebar Navigation
function scan_folder_tree(string $base_dir, string $sub = ''): array {
    $dir = $base_dir . ($sub !== '' ? '/' . $sub : '');
    $res = [];
    $items = @scandir($dir) ?: [];
    foreach ($items as $it) {
        if ($it === '.' || $it === '..' || $it === '.htaccess') continue;
        $full = $dir . '/' . $it;
        if (is_dir($full)) {
            $rel = ($sub !== '' ? $sub . '/' : '') . $it;
            $res[] = [
                'name' => $it,
                'path' => $rel,
                'children' => scan_folder_tree($base_dir, $rel)
            ];
        }
    }
    return $res;
}
$folder_tree = scan_folder_tree($base_upload_dir);

// 7. Scan Current Workspace Directory
$entries = scandir($target_dir) ?: [];
$folders = [];
$files = [];

foreach ($entries as $e) {
    if ($e === '.' || $e === '..' || $e === '.htaccess') continue;
    $full = $target_dir . '/' . $e;
    $sub_url_part = ($rel_subpath !== '' ? $rel_subpath . '/' : '');
    $web_url = base_path('uploads/' . $sub_url_part . rawurlencode($e));

    if (is_dir($full)) {
        $folders[] = [
            'name'     => $e,
            'rel_path' => ($rel_subpath !== '' ? $rel_subpath . '/' : '') . $e,
            'modified' => filemtime($full)
        ];
    } else {
        $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
        $is_img = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true);
        $files[] = [
            'name'     => $e,
            'url'      => $web_url,
            'size'     => filesize($full),
            'modified' => filemtime($full),
            'is_img'   => $is_img,
            'ext'      => $ext
        ];
    }
}

// Compute parent path for Up navigation
$parent_subpath = null;
if ($rel_subpath !== '') {
    $parts = explode('/', $rel_subpath);
    array_pop($parts);
    $parent_subpath = implode('/', $parts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>File Manager & Media Assets - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>

        <main class="admin-main" style="max-width: 1250px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div>
                    <h1 style="margin: 0;">File Manager & Assets</h1>
                    <p style="color: #64748b; font-size: 0.86rem; margin: 2px 0 0 0;">
                        Upload files, manage directories, copy links, and inspect assets.
                    </p>
                </div>
                <div style="font-family: monospace; font-size: 0.86rem; color: #475569; background: #f1f5f9; padding: 6px 12px; border-radius: 4px; border: 1px solid #cbd5e1;">
                    uploads/<?= htmlspecialchars($rel_subpath) ?>
                </div>
            </div>

            <?php render_flash_messages(); ?>

            <div class="fm-container">
                <!-- SIDEBAR DIRECTORY TREE -->
                <div class="fm-sidebar">
                    <h4>Folders</h4>
                    <a href="<?= base_path('admin/file_manager.php' . ($target_input ? '?target=' . urlencode($target_input) : '')) ?>" 
                       class="fm-folder-link <?= $rel_subpath === '' ? 'active' : '' ?>">
                        <span>&#128193;</span> uploads/
                    </a>
                    
                    <?php
                    $render_tree = function(array $nodes, int $depth = 1) use (&$render_tree, $rel_subpath, $target_input) {
                        foreach ($nodes as $node) {
                            $is_act = ($rel_subpath === $node['path']);
                            $pad = $depth * 14;
                            $url = base_path('admin/file_manager.php?dir=' . urlencode($node['path']) . ($target_input ? '&target=' . urlencode($target_input) : ''));
                            echo "<div style=\"padding-left: {$pad}px;\">";
                            echo "<a href=\"{$url}\" class=\"fm-folder-link " . ($is_act ? 'active' : '') . "\">";
                            echo "<span>&#128194;</span> " . htmlspecialchars($node['name']) . "/";
                            echo "</a>";
                            if (!empty($node['children'])) {
                                $render_tree($node['children'], $depth + 1);
                            }
                            echo "</div>";
                        }
                    };
                    $render_tree($folder_tree);
                    ?>
                </div>

                <!-- MAIN WORKSPACE -->
                <div class="fm-main">
                    <!-- TOOLBAR -->
                    <div class="fm-toolbar">
                        <div class="fm-actions">
                            <button type="button" class="btn-primary" onclick="openModal('uploadModal')" style="padding: 5px 14px; font-size: 0.85rem;">
                                &#8679; Upload
                            </button>
                            <button type="button" class="btn-secondary" onclick="openReplaceModal()" style="padding: 7px 14px; font-size: 0.85rem;">
                                &#8644; Replace
                            </button>
                            <button type="button" class="btn-secondary" onclick="openModal('newFolderModal')" style="padding: 7px 14px; font-size: 0.85rem;">
                                + New Folder
                            </button>
                            <button type="button" class="btn-secondary" onclick="openRenameModal()" style="padding: 7px 14px; font-size: 0.85rem;">
                                Rename
                            </button>
                            <button type="button" class="btn-secondary" onclick="downloadSelected()" style="padding: 7px 14px; font-size: 0.85rem;">
                                Download
                            </button>
                            <button type="button" class="btn-secondary" onclick="copySelectedUrl()" style="padding: 7px 14px; font-size: 0.85rem;">
                                Copy URL
                            </button>
                            <button type="button" class="btn-secondary" onclick="deleteSelected()" style="padding: 7px 14px; font-size: 0.85rem; color: #dc2626;">
                                Delete
                            </button>
                        </div>

                        <!-- RIGHT CONTROLS: FILTER & VIEW TOGGLES -->
                        <div class="fm-tools-right">
                            <input type="text" 
                                   id="fmFilter" 
                                   class="fm-filter-input" 
                                   placeholder="Filter files & folders..." 
                                   oninput="filterItems(this.value)">

                            <div class="view-toggle-group">
                                <button type="button" 
                                        id="btnViewGrid" 
                                        class="btn-view-toggle active" 
                                        title="Grid View (Thumbnails)" 
                                        onclick="setViewMode('grid')">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M3 3h8v8H3zm10 0h8v8h-8zM3 13h8v8H3zm10 0h8v8h-8z"/>
                                    </svg>
                                </button>
                                <button type="button" 
                                        id="btnViewList" 
                                        class="btn-view-toggle" 
                                        title="List View" 
                                        onclick="setViewMode('list')">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- GRID / LIST ITEMS CONTAINER -->
                    <div class="fm-grid" id="fmGrid">

                        <!-- Directories -->
                        <?php foreach ($folders as $fld): ?>
                            <div class="fm-item" 
                                 data-name="<?= htmlspecialchars($fld['name']) ?>"
                                 data-is-dir="1"
                                 data-rel-path="<?= htmlspecialchars($fld['rel_path']) ?>"
                                 onclick="selectItem(this)"
                                 ondblclick="location.href='<?= base_path('admin/file_manager.php?dir=' . urlencode($fld['rel_path']) . ($target_input ? '&target=' . urlencode($target_input) : '')) ?>'">
                                <div class="fm-thumb">
                                    <span style="font-size: 2.2rem; color: #eab308;">&#128193;</span>
                                </div>
                                <div class="fm-filename" title="<?= htmlspecialchars($fld['name']) ?>">
                                    <?= htmlspecialchars($fld['name']) ?>/
                                </div>
                                <div class="fm-meta-size">Folder</div>
                                <div class="fm-meta-date"><?= date('M j, Y H:i', $fld['modified']) ?></div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Files -->
                        <?php foreach ($files as $f): ?>
                            <div class="fm-item" 
                                 data-name="<?= htmlspecialchars($f['name']) ?>" 
                                 data-url="<?= htmlspecialchars($f['url']) ?>" 
                                 data-is-dir="0"
                                 data-is-img="<?= $f['is_img'] ? '1' : '0' ?>"
                                 onclick="selectItem(this)"
                                 ondblclick="copySelectedUrl()">
                                <div class="fm-thumb">
                                    <?php if ($f['is_img']): ?>
                                        <img src="<?= htmlspecialchars($f['url']) ?>" alt="<?= htmlspecialchars($f['name']) ?>" loading="lazy">
                                    <?php else: ?>
                                        <span style="font-size: 2rem; color: #64748b;">&#128196;</span>
                                    <?php endif; ?>
                                </div>
                                <div class="fm-filename" title="<?= htmlspecialchars($f['name']) ?>">
                                    <?= htmlspecialchars($f['name']) ?>
                                </div>
                                <div class="fm-meta-size">
                                    <?= number_format($f['size'] / 1024, 1) ?> KB
                                </div>
                                <div class="fm-meta-date">
                                    <?= date('M j, Y H:i', $f['modified']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if (empty($files) && empty($folders) && $parent_subpath === null): ?>
                            <div id="fmEmpty" style="grid-column: 1 / -1; text-align: center; color: #94a3b8; padding: 60px 20px; font-style: italic;">
                                Directory is empty. Click "+ Upload" above to add files.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- 1. MODAL: UPLOAD FILE -->
    <div id="uploadModal" class="modal-overlay">
        <div class="modal-dialog">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 15px;">
                <h3 style="margin: 0; color: var(--frnp-navy, #0b2545); font-size: 1.15rem;">Upload File</h3>
                <button type="button" onclick="closeModal('uploadModal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" action="<?= htmlspecialchars($redirect_url) ?>">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="action_upload" value="1">
                <div class="form-row" style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Select File</label>
                    <input type="file" name="upload_file" required style="width: 100%; padding: 8px; border: 1px dashed #cbd5e1; border-radius: 4px; background: #f8fafc;">
                    <small style="color: #64748b; margin-top: 6px; display: block;">Destination: <code>uploads/<?= htmlspecialchars($rel_subpath) ?></code></small>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeModal('uploadModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Upload File</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. MODAL: REPLACE FILE (WITH IN-MODAL MISMATCH WARNING) -->
    <div id="replaceModal" class="modal-overlay">
        <div class="modal-dialog">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 15px;">
                <h3 style="margin: 0; color: var(--frnp-navy, #0b2545); font-size: 1.15rem;">Replace File</h3>
                <button type="button" onclick="closeModal('replaceModal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;">&times;</button>
            </div>
            <form id="replaceForm" method="POST" enctype="multipart/form-data" action="<?= htmlspecialchars($redirect_url) ?>" onsubmit="return handleReplaceSubmit()">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="action_replace" value="1">
                <input type="hidden" name="target_file" id="modal_replace_target_file" value="">

                <div class="form-row" style="margin-bottom: 14px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 4px; color: #64748b; font-size: 0.85rem;">Replacing Target File</label>
                    <div id="modal_replace_target_label" style="font-family: monospace; font-size: 0.9rem; padding: 7px 10px; background: #f1f5f9; border-radius: 4px; border: 1px solid #cbd5e1; color: #0b2545; font-weight: 600;"></div>
                </div>

                <div class="form-row" style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Select Replacement File</label>
                    <input type="file" id="replace_file_input" name="replace_file" required onchange="onReplaceFileChange(this)" style="width: 100%; padding: 8px; border: 1px dashed #cbd5e1; border-radius: 4px; background: #f8fafc;">
                    <small style="color: #64748b; margin-top: 6px; display: block;">The existing file content will be overwritten while preserving all existing links and URLs.</small>
                </div>

                <!-- In-Modal Warning Box for Filename Mismatch -->
                <div id="replace_warning_box" style="display: none; background: #fffbeb; border: 1px solid #fef3c7; border-left: 4px solid #f59e0b; padding: 14px; border-radius: 4px; margin-bottom: 18px;">
                    <div style="font-weight: 700; color: #92400e; font-size: 0.88rem; margin-bottom: 4px;">File Name Mismatch</div>
                    <p style="margin: 0 0 10px 0; font-size: 0.84rem; color: #78350f; line-height: 1.45;">
                        The uploaded file (<code id="chosen_filename_label" style="font-weight: 700; background: #fef3c7; padding: 1px 4px; border-radius: 3px;"></code>) has a different name than the original (<code id="target_filename_label" style="font-weight: 700; background: #fef3c7; padding: 1px 4px; border-radius: 3px;"></code>).
                    </p>
                    <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 0.85rem; color: #78350f; font-weight: 600; cursor: pointer;">
                        <input type="checkbox" id="confirm_rename_checkbox" name="confirm_rename" value="1" style="margin-top: 2px;">
                        <span>Confirm: Replace <span id="target_confirm_label" style="text-decoration: underline;"></span> and rename the uploaded file to match.</span>
                    </label>
                    <div id="confirm_error_msg" style="display: none; color: #dc2626; font-size: 0.8rem; margin-top: 6px; font-weight: 600;">
                        Please check the confirmation box above to proceed.
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeModal('replaceModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Replace File</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 3. MODAL: NEW FOLDER -->
    <div id="newFolderModal" class="modal-overlay">
        <div class="modal-dialog">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 15px;">
                <h3 style="margin: 0; color: var(--frnp-navy, #0b2545); font-size: 1.15rem;">Create New Folder</h3>
                <button type="button" onclick="closeModal('newFolderModal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;">&times;</button>
            </div>
            <form method="POST" action="<?= htmlspecialchars($redirect_url) ?>">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="action_create_folder" value="1">
                <div class="form-row" style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">Folder Name *</label>
                    <input type="text" name="folder_name" required placeholder="e.g. documents" autofocus style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 4px;">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeModal('newFolderModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Create Folder</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 4. MODAL: RENAME -->
    <div id="renameModal" class="modal-overlay">
        <div class="modal-dialog">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 15px;">
                <h3 style="margin: 0; color: var(--frnp-navy, #0b2545); font-size: 1.15rem;">Rename Item</h3>
                <button type="button" onclick="closeModal('renameModal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;">&times;</button>
            </div>
            <form method="POST" action="<?= htmlspecialchars($redirect_url) ?>">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="action_rename" value="1">
                <input type="hidden" name="old_name" id="modal_old_name" value="">
                
                <div class="form-row" style="margin-bottom: 12px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 4px; color: #64748b; font-size: 0.85rem;">Current Name</label>
                    <div id="modal_rename_current_label" style="font-family: monospace; font-size: 0.9rem; padding: 6px 10px; background: #f1f5f9; border-radius: 4px;"></div>
                </div>

                <div class="form-row" style="margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 6px;">New Name *</label>
                    <input type="text" name="new_name" id="modal_new_name" required autofocus style="width: 100%; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 4px; font-family: monospace;">
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeModal('renameModal')">Cancel</button>
                    <button type="submit" class="btn-primary">Apply Rename</button>
                </div>
            </form>
        </div>
    </div>

    <!-- NOTIFICATION TOAST -->
    <div id="fmToast" class="fm-toast"></div>

    <script>
        let currentSelected = null;
        const csrfToken = "<?= get_csrf_token() ?>";

        function showToast(message) {
            const toast = document.getElementById('fmToast');
            toast.innerText = message;
            toast.style.display = 'flex';
            setTimeout(() => {
                toast.style.display = 'none';
            }, 3000);
        }

        function setViewMode(mode) {
            const grid = document.getElementById('fmGrid');
            const btnGrid = document.getElementById('btnViewGrid');
            const btnList = document.getElementById('btnViewList');
            
            if (mode === 'list') {
                grid.classList.add('list-mode');
                btnList.classList.add('active');
                btnGrid.classList.remove('active');
                localStorage.setItem('frnp_fm_view', 'list');
            } else {
                grid.classList.remove('list-mode');
                btnGrid.classList.add('active');
                btnList.classList.remove('active');
                localStorage.setItem('frnp_fm_view', 'grid');
            }
        }

        // Initialize view mode from localStorage (defaults to grid)
        document.addEventListener('DOMContentLoaded', () => {
            const savedView = localStorage.getItem('frnp_fm_view') || 'grid';
            setViewMode(savedView);
        });

        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function selectItem(el) {
            document.querySelectorAll('.fm-item').forEach(item => item.classList.remove('selected'));
            el.classList.add('selected');
            currentSelected = {
                name: el.getAttribute('data-name'),
                url: el.getAttribute('data-url'),
                isDir: el.getAttribute('data-is-dir') === '1',
                isImg: el.getAttribute('data-is-img') === '1'
            };
        }

        // Live Grid/List Item Filter
        function filterItems(query) {
            const q = query.toLowerCase().trim();
            const items = document.querySelectorAll('.fm-item');
            items.forEach(el => {
                const name = (el.getAttribute('data-name') || '').toLowerCase();
                if (name === '..') return;
                el.style.display = (q === '' || name.includes(q)) ? '' : 'none';
            });
        }

        // Universal Clipboard Writer (Works across HTTP and HTTPS)
        function writeToClipboard(text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            } else {
                const textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                textArea.style.top = '-999999px';
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                } catch (err) {
                    console.error('ExecCommand error:', err);
                }
                document.body.removeChild(textArea);
                return Promise.resolve();
            }
        }

        function copySelectedUrl() {
            if (!currentSelected || currentSelected.isDir) {
                alert('Please click to select a file first.');
                return;
            }
            writeToClipboard(currentSelected.url).then(() => {
                showToast('Copied URL: ' + currentSelected.url);
            });
        }

        function downloadSelected() {
            if (!currentSelected || currentSelected.isDir) {
                alert('Please select a file to download first.');
                return;
            }
            const a = document.createElement('a');
            a.href = currentSelected.url;
            a.download = currentSelected.name;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }

        function openRenameModal() {
            if (!currentSelected) {
                alert('Please select a file or folder to rename first.');
                return;
            }
            document.getElementById('modal_old_name').value = currentSelected.name;
            document.getElementById('modal_rename_current_label').innerText = currentSelected.name;
            document.getElementById('modal_new_name').value = currentSelected.name;
            openModal('renameModal');
        }

        function openReplaceModal() {
            if (!currentSelected) {
                alert('Please select a file to replace first.');
                return;
            }
            if (currentSelected.isDir) {
                alert('Folders cannot be replaced with a single file. Please select a file.');
                return;
            }
            document.getElementById('modal_replace_target_file').value = currentSelected.name;
            document.getElementById('modal_replace_target_label').innerText = currentSelected.name;
            document.getElementById('replace_file_input').value = '';

            // Reset in-modal warning box state
            document.getElementById('replace_warning_box').style.display = 'none';
            document.getElementById('confirm_rename_checkbox').checked = false;
            document.getElementById('confirm_error_msg').style.display = 'none';

            openModal('replaceModal');
        }

        function onReplaceFileChange(input) {
            const targetName = document.getElementById('modal_replace_target_file').value;
            const warningBox = document.getElementById('replace_warning_box');
            const chk = document.getElementById('confirm_rename_checkbox');
            const errMsg = document.getElementById('confirm_error_msg');
            errMsg.style.display = 'none';

            if (!input.files || input.files.length === 0) {
                warningBox.style.display = 'none';
                chk.checked = false;
                return;
            }

            const chosenName = input.files[0].name;
            if (chosenName !== targetName) {
                document.getElementById('chosen_filename_label').innerText = chosenName;
                document.getElementById('target_filename_label').innerText = targetName;
                document.getElementById('target_confirm_label').innerText = targetName;
                chk.checked = false;
                warningBox.style.display = 'block';
            } else {
                warningBox.style.display = 'none';
                chk.checked = true;
            }
        }

        function handleReplaceSubmit() {
            const input = document.getElementById('replace_file_input');
            const targetName = document.getElementById('modal_replace_target_file').value;
            const warningBox = document.getElementById('replace_warning_box');
            const chk = document.getElementById('confirm_rename_checkbox');
            const errMsg = document.getElementById('confirm_error_msg');

            if (!input.files || input.files.length === 0) {
                alert('Please choose a replacement file.');
                return false;
            }

            const chosenName = input.files[0].name;
            if (chosenName !== targetName) {
                if (!chk.checked) {
                    warningBox.style.display = 'block';
                    errMsg.style.display = 'block';
                    chk.focus();
                    return false;
                }
            }

            return true;
        }

        function deleteSelected() {
            if (!currentSelected) {
                alert('Please select an item to delete.');
                return;
            }
            const itemType = currentSelected.isDir ? 'folder and its contents' : 'file';
            if (confirm(`Permanently delete the ${itemType} "${currentSelected.name}"?`)) {
                window.location.href = `<?= $redirect_url ?>${location.search ? '&' : '?'}action=delete&file=${encodeURIComponent(currentSelected.name)}&csrf_token=${csrfToken}`;
            }
        }

        window.onclick = function(e) {
            ['uploadModal', 'replaceModal', 'newFolderModal', 'renameModal'].forEach(id => {
                const modal = document.getElementById(id);
                if (e.target === modal) {
                    closeModal(id);
                }
            });
        };
    </script>
</body>
</html>
