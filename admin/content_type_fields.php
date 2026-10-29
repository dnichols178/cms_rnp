<?php declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_permission('administer_content_types');

$db = get_db();
$type_key = trim($_GET['type'] ?? '');
$content_type = get_content_type($type_key);
if (!$content_type) {
    die('Content type not found.');
}

$message = '';
$error = '';
$schema = $content_type['fields_schema'] ?? [];

// Discover all unique existing fields configured across other content types
$all_content_types = get_content_types();
$current_field_names = array_column($schema, 'name');
$existing_fields_pool = [];

foreach ($all_content_types as $ct) {
    $ct_schema = json_decode($ct['fields_schema'] ?? '[]', true);
    if (!is_array($ct_schema)) continue;
    foreach ($ct_schema as $f) {
        $fname = $f['name'] ?? '';
        if ($fname === '' || $fname === 'body') continue;
        // Exclude fields already present on this content type
        if (in_array($fname, $current_field_names, true)) continue;
        if (!isset($existing_fields_pool[$fname])) {
            $existing_fields_pool[$fname] = [
                'name'          => $fname,
                'label'         => $f['label'] ?? $fname,
                'type'          => $f['type'] ?? 'text',
                'label_display' => $f['label_display'] ?? 'inline',
                'help_text'     => $f['help_text'] ?? '',
                'required'      => !empty($f['required']) ? 1 : 0,
                'options'       => $f['options'] ?? [],
                'upload_dir'    => $f['upload_dir'] ?? '',
                'source_type'   => $ct['name']
            ];
        }
    }
}
ksort($existing_fields_pool);

// 1. Action: Delete Field
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['field'])) {
    validate_csrf();
    $field_to_delete = trim($_GET['field']);
    $new_schema = array_values(array_filter($schema, fn($f) => $f['name'] !== $field_to_delete));
    
    $has_body = ($field_to_delete === 'body') ? 0 : $content_type['has_body'];
    $upd = $db->prepare('UPDATE content_types SET fields_schema = ?, has_body = ? WHERE type = ?');
    $upd->execute([json_encode($new_schema), $has_body, $type_key]);

    watchdog('content_type', "Field '{$field_to_delete}' removed from '{$type_key}'.", 'notice');
    set_flash('success', "Field '{$field_to_delete}' removed successfully.");
    header('Location: ' . base_path('admin/content_type_fields.php?type=') . urlencode($type_key));
    exit;
}

// 2. Action: Save Field Order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_order') {
    validate_csrf();
    $weights = $_POST['weight'] ?? [];
    foreach ($schema as &$field) {
        if (isset($weights[$field['name']])) {
            $field['weight'] = (int)$weights[$field['name']];
        }
    }
    unset($field);
    usort($schema, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
    $upd = $db->prepare('UPDATE content_types SET fields_schema = ? WHERE type = ?');
    $upd->execute([json_encode($schema), $type_key]);
    set_flash('success', 'Field order saved successfully.');
    header('Location: ' . base_path('admin/content_type_fields.php?type=') . urlencode($type_key));
    exit;
}

// 3. Action: Add or Edit Field (Modal Submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_field_modal') {
    validate_csrf();
    $is_edit       = ($_POST['modal_mode'] ?? '') === 'edit';
    $raw_name      = trim($_POST['field_name'] ?? '');
    $label         = trim($_POST['field_label'] ?? '');
    $type          = trim($_POST['field_type'] ?? 'text');
    $label_display = trim($_POST['label_display'] ?? 'inline');
    $help_text     = trim($_POST['help_text'] ?? '');
    $is_required   = isset($_POST['is_required']) ? 1 : 0;
    $raw_options   = trim($_POST['options'] ?? '');
    $upload_dir    = '';

    if ($type === 'file' || $type === 'image') {
        $raw_dir = trim($_POST['upload_dir'] ?? '');
        $clean_dir = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $raw_dir);
        $clean_dir = trim(preg_replace('#/+#', '/', $clean_dir), '/');
        $upload_dir = $clean_dir !== '' ? $clean_dir : 'files';

        $uploads_root = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
        $target_dir_path = $uploads_root . '/' . $upload_dir;
        if (!is_dir($target_dir_path)) {
            if (!mkdir($target_dir_path, 0755, true) && !is_dir($target_dir_path)) {
                watchdog('content_type', "Failed to create upload directory at: {$target_dir_path}", 'error');
            } else {
                watchdog('content_type', "Created upload directory: uploads/{$upload_dir}", 'notice');
            }
        }
    }

    if ($label === '') {
        $error = 'Field Label is required.';
    } else {
        $parsed_options = [];
        if ($type === 'select' && $raw_options !== '') {
            foreach (explode("\n", str_replace("\r", "", $raw_options)) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (str_contains($line, '|')) {
                    [$val, $opt_label] = explode('|', $line, 2);
                    $parsed_options[trim($val)] = trim($opt_label);
                } else {
                    $parsed_options[$line] = $line;
                }
            }
        }

        if ($is_edit) {
            // Update Existing Attached Field
            $found = false;
            foreach ($schema as &$f) {
                if ($f['name'] === $raw_name) {
                    $f['label']         = $label;
                    if ($raw_name !== 'body') {
                        $f['type']      = $type;
                    }
                    $f['label_display'] = $label_display;
                    $f['help_text']     = $help_text;
                    $f['required']      = $is_required;
                    $f['options']       = $parsed_options;
                    if ($type === 'file' || $type === 'image') {
                        $f['upload_dir'] = $upload_dir;
                    } else {
                        unset($f['upload_dir']);
                    }
                    $found = true;
                    break;
                }
            }
            unset($f);

            if ($found) {
                $upd = $db->prepare('UPDATE content_types SET fields_schema = ? WHERE type = ?');
                $upd->execute([json_encode($schema), $type_key]);
                set_flash('success', "Field '{$label}' updated successfully.");
                header('Location: ' . base_path('admin/content_type_fields.php?type=') . urlencode($type_key));
                exit;
            }
        } else {
            // Add New Field (or Attach Re-used Existing Field)
            if ($type === 'body') {
                $raw_name = 'body';
            } elseif ($raw_name === '') {
                $clean_label = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace([' ', '-'], '_', $label)));
                $raw_name = 'field_' . ($clean_label !== '' ? $clean_label : bin2hex(random_bytes(3)));
            } else {
                $raw_name = preg_replace('/[^a-z0-9_]/', '', strtolower($raw_name));
                // Only enforce prefix if not already present, not body, and not existing machine identifier
                if (!str_starts_with($raw_name, 'field_') && $raw_name !== 'body' && !isset($existing_fields_pool[$raw_name])) {
                    $raw_name = 'field_' . $raw_name;
                }
            }

            // Check if already on this content type
            $exists = false;
            foreach ($schema as $f) {
                if ($f['name'] === $raw_name) {
                    $exists = true;
                    break;
                }
            }

            if ($exists) {
                $error = "A field with the identifier '{$raw_name}' is already attached to this content type.";
            } else {
                $new_field = [
                    'name'          => $raw_name,
                    'label'         => $label,
                    'type'          => $type,
                    'label_display' => $label_display,
                    'weight'        => (count($schema) + 1) * 10,
                    'help_text'     => $help_text,
                    'required'      => $is_required,
                    'options'       => $parsed_options
                ];

                if ($type === 'file' || $type === 'image') {
                    $new_field['upload_dir'] = $upload_dir;
                }

                $schema[] = $new_field;
                usort($schema, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
                
                $has_body = ($type === 'body' || $content_type['has_body']) ? 1 : 0;
                $upd = $db->prepare('UPDATE content_types SET fields_schema = ?, has_body = ? WHERE type = ?');
                $upd->execute([json_encode($schema), $has_body, $type_key]);

                set_flash('success', "Field '{$label}' successfully attached to content type.");
                header('Location: ' . base_path('admin/content_type_fields.php?type=') . urlencode($type_key));
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Fields: <?= htmlspecialchars($content_type['name']) ?> - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>

        <main class="admin-main">
            <div style="font-size: 0.84rem; color: #64748b; margin-bottom: 8px;">
                <a href="<?= base_path('admin/content_types.php') ?>" style="color: #0b2545; text-decoration: none;">&laquo; Content Types</a> &raquo;
                <span><?= htmlspecialchars($content_type['name']) ?></span>
            </div>

            <div class="page-header-actions">
                <div>
                    <h1>Manage Fields: <?= htmlspecialchars($content_type['name']) ?> (<code><?= htmlspecialchars($content_type['type']) ?></code>)</h1>
                    <p style="color: #64748b; font-size: 0.88rem; margin-top: -12px;">
                        Configure form fields and adjust their display sequence using the arrow controls.
                    </p>
                </div>
                <div style="display:flex; gap:10px;">
                    <a href="<?= base_path('admin/pathauto.php') ?>" class="btn-secondary">URL Alias Patterns &raquo;</a>
                    <button type="button" class="btn-primary" onclick="openAddModal()">+ Add Field</button>
                </div>
            </div>

            <?php render_flash_messages(); ?>
            <?php if ($error): ?><div class="notice" style="background:#fee; border-color:#c00; color:#c00;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <!-- Field Structure Form -->
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="action" value="save_order">

                <div class="fields-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Field Information</th>
                                <th>Machine Identifier</th>
                                <th>Requirement</th>
                                <th>Label Style</th>
                                <th style="text-align: center; width: 90px;">Order</th>
                                <th style="text-align: right; width: 140px;">Actions</th>
                            </tr>
                        </thead>

                        <!-- Permanent Title Field -->
                        <tbody>
                            <tr style="background: #fafbfc; color: #475569;">
                                <td>
                                    <strong>Title</strong><br>
                                </td>
                                <td><code>title</code> • <span class="type-badge">Text (Single Line)</span></td>
                                <td><span class="badge-req">Required</span></td>
                                <td><code>hidden</code></td>
                                <td style="text-align: center; color: #94a3b8; font-size: 0.8rem;">Top (Fixed)</td>
                                <td style="text-align: right; color: #94a3b8; font-size: 0.82rem;">Core &bull; Locked</td>
                            </tr>
                        </tbody>

                        <!-- Configured Fields -->
                        <tbody id="sortable-fields">
                            <?php if (empty($schema)): ?>
                                <tr><td colspan="6" style="text-align:center; color:#777; padding:25px;">No additional fields configured. Click "+ Add Field" above.</td></tr>
                            <?php else: ?>
                                <?php foreach ($schema as $idx => $f): ?>
                                <?php 
                                    $f_weight = $f['weight'] ?? (($idx + 1) * 10);
                                    $is_body = ($f['name'] === 'body' || ($f['type'] ?? '') === 'body');
                                ?>
                                <tr class="field-row" style="<?= $is_body ? 'background:#fafcff;' : '' ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($f['label']) ?></strong>
                                        <?php if (!empty($f['help_text'])): ?>
                                            <div style="font-size: 0.78rem; color: #64748b;"><?= htmlspecialchars($f['help_text']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code><?= htmlspecialchars($f['name']) ?></code> &bull; 
                                        <span class="type-badge <?= $is_body ? 'type-badge-body' : '' ?>"><?= htmlspecialchars($f['type']) ?></span>
                                        <input type="hidden" name="weight[<?= htmlspecialchars($f['name']) ?>]" class="field-weight-input" value="<?= $f_weight ?>">
                                    </td>
                                    <td>
                                        <?= !empty($f['required']) ? '<span class="badge-req">Required</span>' : '<span class="badge-opt">Optional</span>' ?>
                                    </td>
                                    <td><code><?= htmlspecialchars($f['label_display'] ?? ($is_body ? 'hidden' : 'inline')) ?></code></td>
                                    <td style="text-align: center;">
                                        <div class="arrow-controls">
                                            <button type="button" class="btn-arrow btn-up" onclick="moveRow(this, -1)" title="Move Up">▲</button>
                                            <button type="button" class="btn-arrow btn-down" onclick="moveRow(this, 1)" title="Move Down">▼</button>
                                        </div>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <button type="button" class="btn-secondary" style="font-size:0.8rem; padding:4px 8px;" onclick='openEditModal(<?= json_encode($f) ?>)'>Edit</button>
                                        <a href="<?= base_path('admin/content_type_fields.php') ?>?type=<?= urlencode($type_key) ?>&action=delete&field=<?= urlencode($f['name']) ?>&csrf_token=<?= get_csrf_token() ?>" 
                                           data-confirm="Remove field &quot;<?= htmlspecialchars($f['label']) ?>&quot;?" 
                                           data-confirm-title="Remove Field"
                                           data-confirm-btn="Delete"
                                           style="color:#c00; font-size:0.8rem; margin-left:8px; text-decoration:none;">Delete</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($schema)): ?>
                    <div style="margin-top: 18px;">
                        <button type="submit" class="btn-primary">Save Field Order</button>
                    </div>
                <?php endif; ?>
            </form>
        </main>
    </div>

    <!-- Unified Modal Dialog (Add or Edit Field) -->
    <div id="fieldModalOverlay" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modalTitle">Field Configuration</h3>
                <button type="button" class="modal-close-btn" onclick="closeFieldModal()">&times;</button>
            </div>
            <form method="POST" id="fieldModalForm" onsubmit="prepareModalSubmit()">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="action" value="save_field_modal">
                <input type="hidden" name="modal_mode" id="modal_mode" value="add">

                <div class="modal-body">
                    <!-- Choice: New Field vs. Re-use Existing Field (Shown only in Add Mode) -->
                    <div id="field_source_toggle_box" class="source-pill-group">
                        <label style="font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px;">
                            <input type="radio" name="field_source" id="source_new" value="new" checked onchange="handleSourceChange('new')">
                            Create new field
                        </label>
                        <label style="font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px;">
                            <input type="radio" name="field_source" id="source_existing" value="existing" onchange="handleSourceChange('existing')"
                                   <?= empty($existing_fields_pool) ? 'disabled title="No unattached fields exist across other types"' : '' ?>>
                            Re-use existing field <?= empty($existing_fields_pool) ? '<span style="font-weight:normal; color:#94a3b8;">(none available)</span>' : '' ?>
                        </label>
                    </div>

                    <!-- Dropdown Picker for Existing Field (Shown when 'existing' is checked) -->
                    <div class="form-row" id="existing_picker_row" style="display: none; background: #eff6ff; border: 1px solid #bfdbfe; padding: 12px; border-radius: 6px; margin-bottom: 16px;">
                        <label style="color: #1e40af; font-weight: 700;">Select Existing Field by Machine Name *</label>
                        <select id="existing_field_select" onchange="onExistingFieldPicked(this.value)" style="width:100%; padding:8px;">
                            <option value="">-- Choose an existing field --</option>
                            <?php foreach ($existing_fields_pool as $ex): ?>
                                <option value="<?= htmlspecialchars($ex['name']) ?>">
                                    <?= htmlspecialchars($ex['label']) ?> (<code><?= htmlspecialchars($ex['name']) ?></code> &bull; <?= htmlspecialchars(strtoupper($ex['type'])) ?> from <?= htmlspecialchars($ex['source_type']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #1e40af; margin-top: 4px;">Selecting a field binds its shared machine identifier and data type.</small>
                    </div>

                    <div class="form-row">
                        <label>Field Title / Label *</label>
                        <input type="text" name="field_label" id="modal_field_label" required placeholder="e.g. Lead Photograph" onkeyup="autoMachineName(this.value)">
                    </div>

                    <div class="form-row" id="machine_name_row">
                        <label>Machine Identifier</label>
                        <input type="text" name="field_name" id="modal_field_name" placeholder="field_lead_photograph">
                        <small id="modal_name_help">System column identifier. Letters, numbers, and underscores only.</small>
                    </div>

                    <div class="form-row">
                        <label>Field Type *</label>
                        <select name="field_type" id="modal_field_type" onchange="handleTypeChange(this.value)">
                            <option value="image">Image Upload (Photo / Graphic)</option>
                            <option value="video">Video (YouTube URL Embed)</option>
                            <option value="body">Body Content (Rich Text WYSIWYG)</option>
                            <option value="file">File (PDF / Document Download)</option>
                            <option value="text">Text (Single Line)</option>
                            <option value="taxonomy_term">Taxonomy Terms (Vocabulary Reference)</option>
                            <option value="textarea">Long Text (Multi-Line)</option>
                            <option value="number">Number (Integer / Decimal)</option>
                            <option value="date">Date</option>
                            <option value="boolean">Boolean (Yes/No Checkbox)</option>
                            <option value="select">Dropdown Menu (Allowed List)</option>
                        </select>
                    </div>

                    <div class="form-row" id="modal_upload_dir_row" style="display: none;">
                        <label>Upload Directory (inside <code>uploads/</code>)</label>
                        <input type="text" name="upload_dir" id="modal_upload_dir" placeholder="e.g. attachments">
                        <small>Target folder inside <code>/uploads/</code>. Created automatically if it does not exist. Defaults to <code>files</code>.</small>
                    </div>

                    <div class="form-row" id="modal_options_row" style="display: none;">
                        <label>Allowed Values (One per line: key|Label)</label>
                        <textarea name="options" id="modal_options" rows="3" placeholder="alpha|Alpha Facility&#10;beta|Beta Facility"></textarea>
                    </div>

                    <div class="form-row">
                        <label>Label Display</label>
                        <select name="label_display" id="modal_label_display">
                            <option value="inline">Inline (Label: Value)</option>
                            <option value="above">Above (Label above value)</option>
                            <option value="hidden">&lt;Hidden&gt;</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label>Help Text</label>
                        <input type="text" name="help_text" id="modal_help_text" placeholder="Instructions displayed to editors in authoring form">
                    </div>

                    <div class="form-row">
                        <label style="font-weight: normal; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="is_required" id="modal_is_required" value="1">
                            <strong>Required Field</strong>
                        </label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeFieldModal()">Cancel</button>
                    <button type="submit" class="btn-primary" id="modalSubmitBtn">Add Field</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const existingFieldsPool = <?= json_encode($existing_fields_pool) ?>;

        function openAddModal() {
            document.getElementById('modalTitle').innerText = 'Add Field to Content Type';
            document.getElementById('modal_mode').value = 'add';
            document.getElementById('modalSubmitBtn').innerText = 'Add Field';

            // Show source toggle box
            document.getElementById('field_source_toggle_box').style.display = 'flex';
            document.getElementById('source_new').checked = true;
            document.getElementById('existing_picker_row').style.display = 'none';
            document.getElementById('existing_field_select').value = '';

            // Reset field inputs
            document.getElementById('modal_field_label').value = '';
            document.getElementById('modal_field_name').value = '';
            document.getElementById('modal_field_name').readOnly = false;
            document.getElementById('modal_field_name').style.background = '#ffffff';
            document.getElementById('modal_name_help').innerText = 'Auto-generated if left blank.';

            document.getElementById('modal_field_type').disabled = false;
            document.getElementById('modal_field_type').value = 'image';
            document.getElementById('modal_label_display').value = 'inline';
            document.getElementById('modal_help_text').value = '';
            document.getElementById('modal_options').value = '';
            document.getElementById('modal_upload_dir').value = '';
            document.getElementById('modal_is_required').checked = false;

            handleTypeChange('image');
            document.getElementById('fieldModalOverlay').classList.add('active');
            document.getElementById('modal_field_label').focus();
        }

        function openEditModal(field) {
            document.getElementById('modalTitle').innerText = 'Edit Field: ' + field.label;
            document.getElementById('modal_mode').value = 'edit';
            document.getElementById('modalSubmitBtn').innerText = 'Save Changes';

            // Hide source toggle when editing
            document.getElementById('field_source_toggle_box').style.display = 'none';
            document.getElementById('existing_picker_row').style.display = 'none';

            document.getElementById('modal_field_label').value = field.label;
            document.getElementById('modal_field_name').value = field.name;
            document.getElementById('modal_field_name').readOnly = true;
            document.getElementById('modal_field_name').style.background = '#f1f5f9';
            document.getElementById('modal_name_help').innerText = 'Machine identifier cannot be modified.';

            document.getElementById('modal_field_type').value = field.type;
            document.getElementById('modal_upload_dir').value = field.upload_dir || '';
            document.getElementById('modal_label_display').value = field.label_display || 'inline';
            document.getElementById('modal_help_text').value = field.help_text || '';
            document.getElementById('modal_is_required').checked = !!field.required;

            // Lock type change if it's the body field
            const isBody = (field.name === 'body');
            document.getElementById('modal_field_type').disabled = isBody;

            let optsStr = '';
            if (field.options && typeof field.options === 'object') {
                for (const [k, v] of Object.entries(field.options)) {
                    optsStr += `${k}|${v}\n`;
                }
            }
            document.getElementById('modal_options').value = optsStr.trim();

            handleTypeChange(field.type);
            document.getElementById('fieldModalOverlay').classList.add('active');
        }

        function closeFieldModal() {
            document.getElementById('fieldModalOverlay').classList.remove('active');
        }

        function handleSourceChange(source) {
            const isExisting = (source === 'existing');
            document.getElementById('existing_picker_row').style.display = isExisting ? 'block' : 'none';

            if (!isExisting) {
                // Restore new field default state
                document.getElementById('modal_field_name').readOnly = false;
                document.getElementById('modal_field_name').style.background = '#ffffff';
                document.getElementById('modal_name_help').innerText = 'Auto-generated if left blank.';
                document.getElementById('modal_field_type').disabled = false;
                document.getElementById('modal_field_label').value = '';
                document.getElementById('modal_field_name').value = '';
                document.getElementById('modal_field_type').value = 'image';
                document.getElementById('modal_upload_dir').value = '';
                document.getElementById('existing_field_select').value = '';
                handleTypeChange('image');
            } else {
                const curSel = document.getElementById('existing_field_select').value;
                if (curSel) {
                    onExistingFieldPicked(curSel);
                }
            }
        }

        function onExistingFieldPicked(name) {
            if (!name || !existingFieldsPool[name]) return;
            const f = existingFieldsPool[name];

            document.getElementById('modal_field_name').value = f.name;
            document.getElementById('modal_field_name').readOnly = true;
            document.getElementById('modal_field_name').style.background = '#f1f5f9';
            document.getElementById('modal_name_help').innerText = 'Re-using shared machine identifier.';

            document.getElementById('modal_field_label').value = f.label;
            document.getElementById('modal_field_type').value = f.type;
            document.getElementById('modal_field_type').disabled = true; // Bound to existing type
            document.getElementById('modal_upload_dir').value = f.upload_dir || '';
            document.getElementById('modal_label_display').value = f.label_display || 'inline';
            document.getElementById('modal_help_text').value = f.help_text || '';
            document.getElementById('modal_is_required').checked = !!f.required;

            let optsStr = '';
            if (f.options && typeof f.options === 'object') {
                for (const [k, v] of Object.entries(f.options)) {
                    optsStr += `${k}|${v}\n`;
                }
            }
            document.getElementById('modal_options').value = optsStr.trim();
            handleTypeChange(f.type);
        }

        function autoMachineName(val) {
            if (document.getElementById('modal_mode').value === 'edit') return;
            if (document.getElementById('source_existing').checked) return;

            const type = document.getElementById('modal_field_type').value;
            if (type === 'body') {
                document.getElementById('modal_field_name').value = 'body';
                return;
            }
            const clean = val.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
            document.getElementById('modal_field_name').value = clean ? 'field_' + clean : '';
        }

        function handleTypeChange(type) {
            document.getElementById('modal_options_row').style.display = (type === 'select') ? 'block' : 'none';
            document.getElementById('modal_upload_dir_row').style.display = (type === 'file' || type === 'image') ? 'block' : 'none';
            if (type === 'body') {
                if (document.getElementById('modal_mode').value === 'add') {
                    document.getElementById('modal_field_name').value = 'body';
                    document.getElementById('modal_field_name').readOnly = true;
                }
                document.getElementById('modal_label_display').value = 'hidden';
            } else if (document.getElementById('modal_mode').value === 'add' && !document.getElementById('source_existing').checked) {
                document.getElementById('modal_field_name').readOnly = false;
                autoMachineName(document.getElementById('modal_field_label').value);
            }
        }

        function prepareModalSubmit() {
            // Ensure disabled select inputs submit their values in POST
            document.getElementById('modal_field_type').disabled = false;
        }

        function moveRow(btn, direction) {
            const row = btn.closest('tr');
            const tbody = document.getElementById('sortable-fields');
            if (direction === -1) {
                const prev = row.previousElementSibling;
                if (prev) tbody.insertBefore(row, prev);
            } else if (direction === 1) {
                const next = row.nextElementSibling;
                if (next) tbody.insertBefore(next, row);
            }
            refreshWeights();
        }

        function refreshWeights() {
            const rows = document.querySelectorAll('#sortable-fields .field-row');
            rows.forEach((row, idx) => {
                const input = row.querySelector('.field-weight-input');
                if (input) input.value = (idx + 1) * 10;
                const up = row.querySelector('.btn-up');
                const down = row.querySelector('.btn-down');
                if (up) up.disabled = (idx === 0);
                if (down) down.disabled = (idx === rows.length - 1);
            });
        }

        document.addEventListener('DOMContentLoaded', refreshWeights);
    </script>
</body>
</html>