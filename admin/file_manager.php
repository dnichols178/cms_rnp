<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';

require_permission('create_content');

$base_upload_dir = realpath(__DIR__ . '/../uploads');
if (!$base_upload_dir || !is_dir($base_upload_dir)) {
    @mkdir(__DIR__ . '/../uploads', 0755, true);
    $base_upload_dir = realpath(__DIR__ . '/../uploads');
}

// 1. Resolve & Secure Active Working Directory (Prevent Traversal Attacks)
$rel_subpath = trim($_GET['path'] ?? '', '/\\');
$rel_subpath = str_replace(['..', "\0"], '', $rel_subpath);
$target_dir = realpath($base_upload_dir . ($rel_subpath !== '' ? '/' . $rel_subpath : ''));

if (!$target_dir || !str_starts_with($target_dir, $base_upload_dir) || !is_dir($target_dir)) {
    $target_dir = $base_upload_dir;
    $rel_subpath = '';
}

$message = '';
$error = '';
$is_picker = isset($_GET['picker']) && $_GET['picker'] === '1';

// 2. Action: Create Folder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_folder') {
    validate_csrf();
    $folder_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($_POST['folder_name'] ?? ''));
    if ($folder_name === '') {
        $error = 'Please provide a valid directory name.';
    } else {
        $new_dir = $target_dir . '/' . $folder_name;
        if (file_exists($new_dir)) {
            $error = "Directory '{$folder_name}' already exists.";
        } else {
            mkdir($new_dir, 0755);
            watchdog('media', "Created directory: {$rel_subpath}/{$folder_name}", 'notice');
            $message = "Directory '{$folder_name}' created.";
        }
    }
}

// 3. Action: Handle Uploads (Multipart or AJAX Dropped Files)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_files') {
    validate_csrf();
    $allowed = ['pdf', 'docx', 'xlsx', 'csv', 'txt', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'zip'];
    $uploaded_files = $_FILES['files'] ?? null;
    $count = 0;

    if ($uploaded_files && is_array($uploaded_files['name'])) {
        for ($i = 0; $i < count($uploaded_files['name']); $i++) {
            if ($uploaded_files['error'][$i] === UPLOAD_ERR_OK) {
                $orig_name = $uploaded_files['name'][$i];
                $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

                if (!in_array($ext, $allowed, true)) {
                    $error = "Disallowed extension: .{$ext}";
                    continue;
                }

                $clean_base = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($orig_name, PATHINFO_FILENAME));
                $target_filename = sprintf('%s_%s.%s', $clean_base, bin2hex(random_bytes(3)), $ext);
                $destination = $target_dir . '/' . $target_filename;

                if (move_uploaded_file($uploaded_files['tmp_name'][$i], $destination)) {
                    $count++;
                }
            }
        }
        if ($count > 0) {
            $message = "Uploaded {$count} file(s) to /" . htmlspecialchars($rel_subpath ?: 'uploads') . ".";
        }
    }
}

// 4. Action: Rename
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rename_item') {
    validate_csrf();
    $old_name = basename($_POST['old_name'] ?? '');
    $new_name = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', trim($_POST['new_name'] ?? ''));

    if ($old_name !== '' && $new_name !== '' && file_exists($target_dir . '/' . $old_name)) {
        rename($target_dir . '/' . $old_name, $target_dir . '/' . $new_name);
        $message = "Renamed '{$old_name}' to '{$new_name}'.";
    }
}

// 5. Action: Delete Item
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['item'])) {
    validate_csrf();
    require_permission('administer_site');
    $item_name = basename($_GET['item']);
    $item_path = $target_dir . '/' . $item_name;

    if (file_exists($item_path)) {
        if (is_dir($item_path)) {
            $files_in_dir = array_diff(scandir($item_path), ['.', '..']);
            if (empty($files_in_dir)) {
                rmdir($item_path);
                $message = "Directory '{$item_name}' removed.";
            } else {
                $error = "Folder '{$item_name}' is not empty. Please clear child files first.";
            }
        } else {
            unlink($item_path);
            $message = "File '{$item_name}' removed.";
        }
    }
}

// 6. Build Recursive Folder Tree for Left Navigation Pane
function build_directory_tree(string $base_dir, string $rel_path = ''): array {
    $dir = $base_dir . ($rel_path !== '' ? '/' . $rel_path : '');
    $items = [];
    if (!is_dir($dir)) return $items;
    
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..' || $item === '.htaccess') continue;
        $full = $dir . '/' . $item;
        if (is_dir($full)) {
            $current_rel = ($rel_path !== '' ? $rel_path . '/' : '') . $item;
            $items[] = [
                'name'     => $item,
                'rel_path' => $current_rel,
                'children' => build_directory_tree($base_dir, $current_rel)
            ];
        }
    }
    return $items;
}
$dir_tree = build_directory_tree($base_upload_dir);

// 7. Read Current Directory Items
$raw_entries = scandir($target_dir);
$folders = [];
$files = [];

foreach ($raw_entries as $e) {
    if ($e === '.' || $e === '..' || $e === '.htaccess') continue;
    $full = $target_dir . '/' . $e;
    $url = base_path('uploads/' . ($rel_subpath !== '' ? $rel_subpath . '/' : '') . rawurlencode($e));

    if (is_dir($full)) {
        $folders[] = [
            'name'     => $e,
            'rel_path' => ($rel_subpath !== '' ? $rel_subpath . '/' : '') . $e,
            'modified' => filemtime($full),
        ];
    } else {
        $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
        $is_img = in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'], true);
        $size = filesize($full);
        $dim = '';
        if ($is_img && function_exists('getimagesize')) {
            $img_meta = @getimagesize($full);
            if ($img_meta) {
                $dim = $img_meta[0] . ' &times; ' . $img_meta[1];
            }
        }

        $files[] = [
            'name'     => $e,
            'size'     => $size,
            'size_str' => ($size >= 1048576) ? number_format($size / 1048576, 2) . ' MB' : number_format($size / 1024, 1) . ' KB',
            'modified' => date('Y-m-d H:i', filemtime($full)),
            'url'      => $url,
            'is_img'   => $is_img,
            'dim'      => $dim,
            'ext'      => $ext
        ];
    }
}

// Breadcrumb Segments
$breadcrumbs = [];
$parts = array_filter(explode('/', $rel_subpath));
$accum = '';
foreach ($parts as $p) {
    $accum = ($accum === '') ? $p : $accum . '/' . $p;
    $breadcrumbs[] = ['name' => $p, 'path' => $accum];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>IMCE / elFinder File Manager - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body class="<?= $is_picker ? 'picker-mode' : '' ?>">
    <div class="admin-wrapper" style="<?= $is_picker ? 'min-height: auto;' : '' ?>">
        <?php if (!$is_picker): ?>
            <?php require __DIR__ . '/nav.php'; ?>
        <?php endif; ?>

        <main class="admin-main" style="<?= $is_picker ? 'padding: 15px;' : '' ?>">
            <?php if (!$is_picker): ?>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                    <h1>Media &raquo; File Manager (IMCE)</h1>
                    <span style="font-size:0.85rem; color:#64748b;">Unified Storage Browser</span>
                </div>
            <?php endif; ?>

            <?php render_flash_messages(); ?>
            <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="notice" style="background:#fee; border-color:#c00; color:#c00;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <!-- IMCE / elFinder Explorer Interface -->
            <div class="imce-container">
                <!-- Top Command Toolbar -->
                <div class="imce-toolbar">
                    <button type="button" class="imce-btn imce-btn-primary" onclick="openModal('uploadModal')">
                        &#8679; Upload
                    </button>
                    <button type="button" class="imce-btn" onclick="openModal('folderModal')">
                        &#128193; New Folder
                    </button>
                    <div class="imce-divider"></div>
                    <button type="button" class="imce-btn" id="btnInsert" onclick="insertSelected()" disabled>
                        &#10003; Insert / Send
                    </button>
                    <button type="button" class="imce-btn" id="btnCopyUrl" onclick="copySelectedUrl()" disabled>
                        Copy URL
                    </button>
                    <button type="button" class="imce-btn" id="btnDownload" onclick="downloadSelected()" disabled>
                        Download
                    </button>
                    <button type="button" class="imce-btn" id="btnRename" onclick="openRenameModal()" disabled>
                        Rename
                    </button>
                    <?php if (user_has_permission('administer_site')): ?>
                        <button type="button" class="imce-btn" id="btnDelete" onclick="deleteSelected()" style="color:#c00;" disabled>
                            Delete
                        </button>
                    <?php endif; ?>
                    <div class="imce-divider"></div>
                    <input type="text" id="filterInput" placeholder="Quick filter..." onkeyup="filterView()" 
                           style="padding: 5px 10px; font-size: 0.8rem; border: 1px solid var(--border-strong); border-radius: 4px; width: 170px;">
                </div>

                <!-- Main File Manager Workspace -->
                <div class="imce-body">
                    <!-- Left-Pane: Directory Tree -->
                    <aside class="imce-tree-pane">
                        <div style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 8px; padding-left: 6px;">
                            Directories
                        </div>
                        <ul class="tree-node tree-root">
                            <li>
                                <a href="?path=<?= $is_picker ? '&picker=1' : '' ?>" class="tree-link <?= $rel_subpath === '' ? 'active' : '' ?>">
                                    &#128194; uploads
                                </a>
                                <?php
                                function renderTreeHtml(array $tree, string $active_path, bool $is_picker): void {
                                    if (empty($tree)) return;
                                    echo '<ul class="tree-node">';
                                    foreach ($tree as $branch) {
                                        $isActive = ($active_path === $branch['rel_path']);
                                        $url = '?path=' . urlencode($branch['rel_path']) . ($is_picker ? '&picker=1' : '');
                                        echo '<li>';
                                        echo '<a href="' . $url . '" class="tree-link ' . ($isActive ? 'active' : '') . '">';
                                        echo '&#128193; ' . htmlspecialchars($branch['name']);
                                        echo '</a>';
                                        renderTreeHtml($branch['children'], $active_path, $is_picker);
                                        echo '</li>';
                                    }
                                    echo '</ul>';
                                }
                                renderTreeHtml($dir_tree, $rel_subpath, $is_picker);
                                ?>
                            </li>
                        </ul>
                    </aside>

                    <!-- Right-Pane: Files & Folders Grid -->
                    <div class="imce-content-pane">
                        <div class="imce-path-bar">
                            <div>
                                <span>Location: </span>
                                <a href="?<?= $is_picker ? 'picker=1' : '' ?>">uploads</a>
                                <?php foreach ($breadcrumbs as $bc): ?>
                                    &raquo; <a href="?path=<?= urlencode($bc['path']) ?><?= $is_picker ? '&picker=1' : '' ?>"><?= htmlspecialchars($bc['name']) ?></a>
                                <?php endforeach; ?>
                            </div>
                            <span id="itemCount" style="font-size:0.78rem;"><?= count($folders) + count($files) ?> item(s)</span>
                        </div>

                        <div class="imce-files-container" id="imceGrid">
                            <!-- Folders -->
                            <?php foreach ($folders as $fol): ?>
                                <div class="imce-file-card" data-type="folder" data-name="<?= htmlspecialchars($fol['name']) ?>" 
                                     data-url="?path=<?= urlencode($fol['rel_path']) ?><?= $is_picker ? '&picker=1' : '' ?>"
                                     ondblclick="window.location.href=this.getAttribute('data-url')" 
                                     onclick="selectItem(this, false)">
                                    <div class="file-thumb-box">
                                        <div class="folder-icon">&#128193;</div>
                                    </div>
                                    <div class="file-name-label"><?= htmlspecialchars($fol['name']) ?></div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Files -->
                            <?php foreach ($files as $f): ?>
                                <div class="imce-file-card" data-type="file" data-name="<?= htmlspecialchars($f['name']) ?>" 
                                     data-url="<?= htmlspecialchars($f['url']) ?>" 
                                     data-size="<?= htmlspecialchars($f['size_str']) ?>" 
                                     data-date="<?= htmlspecialchars($f['modified']) ?>"
                                     data-dim="<?= htmlspecialchars($f['dim']) ?>"
                                     data-isimg="<?= $f['is_img'] ? '1' : '0' ?>"
                                     ondblclick="insertSelected()"
                                     onclick="selectItem(this, true)">
                                    <div class="file-thumb-box">
                                        <?php if ($f['is_img']): ?>
                                            <img src="<?= $f['url'] ?>" alt="" loading="lazy">
                                        <?php else: ?>
                                            <span class="ext-badge"><?= htmlspecialchars($f['ext']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="file-name-label" title="<?= htmlspecialchars($f['name']) ?>">
                                        <?= htmlspecialchars($f['name']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Status & Metadata Footer Bar -->
                <div class="imce-status-bar">
                    <div id="selectionStatus">No item selected</div>
                    <div id="selectionMeta"></div>
                </div>
            </div>
        </main>
    </div>

    <!-- 1. Upload Modal -->
    <div id="uploadModal" class="imce-modal">
        <div class="imce-modal-card">
            <div class="imce-modal-header">
                <h3 style="margin: 0; font-size: 1rem; color: #0b2545;">Upload Assets</h3>
                <button type="button" onclick="closeModal('uploadModal')" style="background:none; border:none; font-size:1.4rem; cursor:pointer;">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="imce-modal-body">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="upload_files">
                    <div class="form-row">
                        <label>Target Directory: <code>/<?= htmlspecialchars($rel_subpath ?: 'uploads') ?></code></label>
                        <input type="file" name="files[]" multiple required style="padding: 10px 0;">
                        <small>Permitted extensions: PDF, DOCX, XLSX, PNG, JPG, JPEG, WEBP, SVG, ZIP.</small>
                    </div>
                </div>
                <div class="imce-modal-footer">
                    <button type="button" class="imce-btn" onclick="closeModal('uploadModal')">Cancel</button>
                    <button type="submit" class="imce-btn imce-btn-primary">Execute Upload</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. New Folder Modal -->
    <div id="folderModal" class="imce-modal">
        <div class="imce-modal-card">
            <div class="imce-modal-header">
                <h3 style="margin: 0; font-size: 1rem; color: #0b2545;">Create Directory</h3>
                <button type="button" onclick="closeModal('folderModal')" style="background:none; border:none; font-size:1.4rem; cursor:pointer;">&times;</button>
            </div>
            <form method="POST">
                <div class="imce-modal-body">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="create_folder">
                    <div class="form-row">
                        <label>Folder Name *</label>
                        <input type="text" name="folder_name" placeholder="e.g. 2026_reports" required>
                    </div>
                </div>
                <div class="imce-modal-footer">
                    <button type="button" class="imce-btn" onclick="closeModal('folderModal')">Cancel</button>
                    <button type="submit" class="imce-btn imce-btn-primary">Create Folder</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 3. Rename Modal -->
    <div id="renameModal" class="imce-modal">
        <div class="imce-modal-card">
            <div class="imce-modal-header">
                <h3 style="margin: 0; font-size: 1rem; color: #0b2545;">Rename Item</h3>
                <button type="button" onclick="closeModal('renameModal')" style="background:none; border:none; font-size:1.4rem; cursor:pointer;">&times;</button>
            </div>
            <form method="POST">
                <div class="imce-modal-body">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="rename_item">
                    <input type="hidden" name="old_name" id="rename_old_name">
                    <div class="form-row">
                        <label>New Name *</label>
                        <input type="text" name="new_name" id="rename_new_name" required>
                    </div>
                </div>
                <div class="imce-modal-footer">
                    <button type="button" class="imce-btn" onclick="closeModal('renameModal')">Cancel</button>
                    <button type="submit" class="imce-btn imce-btn-primary">Rename</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentSelected = null;

        function selectItem(card, isFile) {
            document.querySelectorAll('.imce-file-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            currentSelected = card;

            // Enable action bar buttons
            document.getElementById('btnInsert').disabled = !isFile;
            document.getElementById('btnCopyUrl').disabled = !isFile;
            document.getElementById('btnDownload').disabled = !isFile;
            document.getElementById('btnRename').disabled = false;
            const btnDel = document.getElementById('btnDelete');
            if (btnDel) btnDel.disabled = false;

            // Update Status and Metadata readout
            const name = card.getAttribute('data-name');
            document.getElementById('selectionStatus').innerHTML = `<strong>Selected:</strong> ${name}`;

            if (isFile) {
                const size = card.getAttribute('data-size');
                const date = card.getAttribute('data-date');
                const dim = card.getAttribute('data-dim');
                let metaStr = `${size} &bull; ${date}`;
                if (dim) metaStr += ` &bull; ${dim} px`;
                document.getElementById('selectionMeta').innerHTML = metaStr;
            } else {
                document.getElementById('selectionMeta').innerHTML = 'Directory (Double-click to open)';
            }
        }

        function insertSelected() {
            if (!currentSelected || currentSelected.getAttribute('data-type') !== 'file') return;
            const url = currentSelected.getAttribute('data-url');
            const name = currentSelected.getAttribute('data-name');
            const isImg = currentSelected.getAttribute('data-isimg') === '1';

            // Check if opened as an iframe or popup dialog for an editor / parent form
            if (window.opener && !window.opener.closed) {
                if (window.opener.imceCallback) {
                    window.opener.imceCallback(url, name, isImg);
                    window.close();
                    return;
                }
            } else if (window.parent && window.parent !== window && window.parent.imceCallback) {
                window.parent.imceCallback(url, name, isImg);
                return;
            }

            // Fallback: Copy URL and display alert
            navigator.clipboard.writeText(url).then(() => {
                alert(`File path copied to clipboard:\n${url}`);
            });
        }

        function copySelectedUrl() {
            if (!currentSelected) return;
            const url = currentSelected.getAttribute('data-url');
            navigator.clipboard.writeText(url).then(() => {
                const btn = document.getElementById('btnCopyUrl');
                const orig = btn.innerText;
                btn.innerText = 'Copied!';
                setTimeout(() => btn.innerText = orig, 1200);
            });
        }

        function downloadSelected() {
            if (!currentSelected) return;
            const url = currentSelected.getAttribute('data-url');
            const a = document.createElement('a');
            a.href = url;
            a.download = currentSelected.getAttribute('data-name');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }

        function openRenameModal() {
            if (!currentSelected) return;
            const name = currentSelected.getAttribute('data-name');
            document.getElementById('rename_old_name').value = name;
            document.getElementById('rename_new_name').value = name;
            openModal('renameModal');
        }

        function deleteSelected() {
            if (!currentSelected) return;
            const name = currentSelected.getAttribute('data-name');
            const type = currentSelected.getAttribute('data-type');
            
            confirmModal(`Permanently delete this ${type}: "${name}"?`, function() {
                const curPath = <?= json_encode($rel_subpath) ?>;
                const csrf = <?= json_encode(get_csrf_token()) ?>;
                window.location.href = `?path=${encodeURIComponent(curPath)}&action=delete&item=${encodeURIComponent(name)}&csrf_token=${csrf}`;
            }, {
                title: 'Delete Storage Item',
                confirmText: 'Delete',
                danger: true
            });
        }

        function filterView() {
            const query = document.getElementById('filterInput').value.toLowerCase();
            const cards = document.querySelectorAll('#imceGrid .imce-file-card');
            cards.forEach(card => {
                const name = card.getAttribute('data-name').toLowerCase();
                card.style.display = name.includes(query) ? 'flex' : 'none';
            });
        }

        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
    </script>
</body>
</html>