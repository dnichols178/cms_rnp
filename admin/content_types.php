<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';

require_permission('administer_content_types');

$db = get_db();
$message = '';
$error = '';

// 1. Action: Delete Content Type
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['type'])) {
    $token = $_GET['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Invalid or expired security token. Please refresh the page and try again.';
    } else {
        $del_type = trim($_GET['type']);
        $ct = get_content_type($del_type);

        if (!$ct) {
            $error = 'Content type not found.';
        } else {
            // Check if any existing nodes are assigned to this content type
            $check = $db->prepare('SELECT COUNT(*) FROM nodes WHERE type = :type');
            $check->execute(['type' => $del_type]);
            $node_count = (int)$check->fetchColumn();

            if ($node_count > 0) {
                $error = "Cannot delete '{$ct['name']}': {$node_count} content node(s) currently use this type. Please delete or reassign those nodes first.";
            } else {
                $stmt = $db->prepare('DELETE FROM content_types WHERE type = :type');
                $stmt->execute(['type' => $del_type]);
                watchdog('content_type', "Content type '{$ct['name']}' ({$del_type}) deleted.", 'notice');
                $message = "Content type '{$ct['name']}' deleted successfully.";
            }
        }
    }
}

// 2. Action: Create Content Type
// 2. Action: Create Content Type
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_type') {
    validate_csrf();

    $machine_name = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['type'] ?? '')));
    $label        = trim($_POST['name'] ?? '');
    $desc         = trim($_POST['description'] ?? '');
    $has_body     = isset($_POST['has_body']) ? 1 : 0;
    $url_pattern  = trim($_POST['url_pattern'] ?? '[node:title]');

    $field_modes  = $_POST['field_mode'] ?? []; // 'existing' or 'new'
    $existing_names = $_POST['existing_field_name'] ?? [];
    $field_names  = $_POST['field_name'] ?? [];
    $field_labels = $_POST['field_label'] ?? [];
    $field_types  = $_POST['field_type'] ?? [];
    $schema       = [];

    // Gather all existing global fields across content types to pull definitions from if re-used
    $all_global_fields = [];
    foreach (get_content_types() as $ct_item) {
        $ct_schema = $ct_item['fields_schema'] ?? [];
        if (is_string($ct_schema)) {
            $ct_schema = json_decode($ct_schema, true) ?? [];
        }
        foreach ($ct_schema as $f_def) {
            if (isset($f_def['name'])) {
                $all_global_fields[$f_def['name']] = $f_def;
            }
        }
    }

    if (is_array($field_modes)) {
        for ($i = 0; $i < count($field_modes); $i++) {
            $mode = $field_modes[$i] ?? 'new';

            if ($mode === 'existing') {
                $fname = trim($existing_names[$i] ?? '');
                if ($fname !== '' && isset($all_global_fields[$fname])) {
                    // Re-use existing field definition
                    $schema[] = $all_global_fields[$fname];
                }
            } else {
                $flabel = trim($field_labels[$i] ?? '');
                $fname  = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($field_names[$i] ?? '')));

                if ($fname === '' && $flabel !== '') {
                    $fname = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', $flabel)));
                }

                if ($fname !== '') {
                    if (!str_starts_with($fname, 'field_')) {
                        $fname = 'field_' . $fname;
                    }
                    $schema[] = [
                        'name'          => $fname,
                        'label'         => $flabel !== '' ? $flabel : $fname,
                        'type'          => trim($field_types[$i] ?? 'text'),
                        'help_text'     => '',
                        'required'      => 0,
                        'label_display' => 'above',
                        'weight'        => ($i + 1) * 10,
                        'options'       => []
                    ];
                }
            }
        }
    }

    if ($machine_name !== '' && $label !== '') {
        try {
            $stmt = $db->prepare('
                INSERT INTO content_types (type, name, description, has_body, url_pattern, fields_schema)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$machine_name, $label, $desc, $has_body, $url_pattern, json_encode($schema)]);
            watchdog('content_type', "New content type '{$label}' ({$machine_name}) created.", 'notice');
            $message = "Content type '{$label}' created successfully.";
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = "A content type with the machine name '{$machine_name}' already exists.";
            } else {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } else {
        $error = 'Both Human Name and Machine Name are required.';
    }
}
$types = get_content_types();

// Collect unique existing fields for re-use dropdown options
$unique_existing_fields = [];
foreach ($types as $t_item) {
    $t_schema = $t_item['fields_schema'] ?? [];
    if (is_string($t_schema)) {
        $t_schema = json_decode($t_schema, true) ?? [];
    }
    if (is_array($t_schema)) {
        foreach ($t_schema as $f_def) {
            if (!empty($f_def['name'])) {
                $unique_existing_fields[$f_def['name']] = $f_def['label'] . ' (' . $f_def['name'] . ')';
            }
        }
    }
}
ksort($unique_existing_fields);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Content Types - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <h1>Structure &raquo; Content Types</h1>

            <?php if ($message): ?>
                <div class="notice" style="background:#ecfdf5; border-left:4px solid #059669; color:#065f46; padding:12px 16px; margin-bottom:20px;">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="notice" style="background:#fef2f2; border-left:4px solid #dc2626; color:#991b1b; padding:12px 16px; margin-bottom:20px;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <section class="card">
                <div style="display: flex;justify-content: space-between;align-items: center;margin-bottom: 20px;">
                    <h3 style="margin: 0;">Existing Content Types</h3>
                    <button type="button" class="btn-primary" onclick="openCreateTypeModal()" style="white-space: nowrap;">+ Add New Content Type</button>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Machine Name</th>
                            <th>Fields</th>
                            <th>Body Field</th>
                            <th>Description</th>
                            <th>Operations</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($types as $t): ?>
                        <?php 
                            $raw_schema = $t['fields_schema'] ?? '[]';
                            $schema_arr = is_array($raw_schema) ? $raw_schema : json_decode((string)$raw_schema, true);
                            $f_count = is_array($schema_arr) ? count($schema_arr) : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
                            <td><code><?= htmlspecialchars($t['type']) ?></code></td>
                            <td><strong><?= $f_count ?></strong> custom field(s)</td>
                            <td><?= !empty($t['has_body']) ? 'Yes' : 'No' ?></td>
                            <td style="color:#555; font-size:0.85rem;"><?= htmlspecialchars($t['description'] ?? '') ?></td>
                            <td style="white-space: nowrap;">
                                <a href="<?= base_path('admin/content_type_fields.php') ?>?type=<?= urlencode($t['type']) ?>" class="btn-secondary" style="font-size:0.8rem; padding: 4px 10px;">
                                    + Fields
                                </a>
                                <a href="<?= base_path('admin/content_types.php') ?>?action=delete&type=<?= urlencode($t['type']) ?>&csrf_token=<?= get_csrf_token() ?>" 
                                   data-confirm="Permanently delete content type &quot;<?= htmlspecialchars($t['name']) ?>&quot;? This cannot be undone." 
                                   data-confirm-title="Delete Content Type"
                                   data-confirm-btn="Delete"
                                   onclick="return confirm('Permanently delete content type &quot;<?= htmlspecialchars(addslashes($t['name'])) ?>&quot;? This cannot be undone.');" 
                                   style="color:#c00; font-size:0.8rem; margin-left:8px; text-decoration:none;">
                                    Delete
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>

    <!-- Modal for Creating Content Type -->
    <div id="createTypeModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);">
        <div class="modal-content" style="background-color: #fefefe; margin: 3% auto; padding: 25px; border: 1px solid #888; width: 780px; max-width: 95vw; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-height: 90vh; overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 20px;">
                <h3 style="margin: 0; color: #0b2545;">Create New Content Type</h3>
                <button type="button" onclick="closeCreateTypeModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;">&times;</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="action" value="create_type">

                <div class="form-row">
                    <label>Human Name *</label>
                    <input type="text" name="name" id="ct_human_name" required placeholder="e.g. Safety Bulletin">
                </div>

                <div class="form-row">
                    <label>Machine Name (Type) *</label>
                    <input type="text" name="type" id="ct_machine_name" required placeholder="e.g. safety_bulletin" class="field-mono-input">
                    <small style="color: #666;">Unique system identifier. Letters, numbers, and underscores only.</small>
                </div>

                <div class="form-row">
                    <label>Description</label>
                    <textarea name="description" rows="2" placeholder="Describe the purpose of this content type."></textarea>
                </div>

                <div class="form-row">
                    <label>
                        <input type="checkbox" name="has_body" value="1" checked> 
                        Enable default WYSIWYG Body Field
                    </label>
                </div>

                <div class="form-row">
                    <label>Pathauto URL Pattern *</label>
                    <input type="text" name="url_pattern" value="[node:title]" required class="field-mono-input">
                    <small style="color: #64748b;">Pattern tokens: <code>[node:title]</code>, <code>[node:type]</code>, <code>[node:created:Y]</code>, <code>[node:created:m]</code>, <code>[term:slug]</code></small>
                </div>

                <!-- Styled Initial Fields Container -->
<!-- Styled Initial Fields Container -->
                <div class="initial-fields-box">
                    <div class="initial-fields-header">
                        <div>
                            <h4 style="margin:0; color:#0b2545;">Initial Fields (Optional)</h4>
                            <span style="font-size:0.82rem; color:#64748b;">Add new fields or re-use existing system fields.</span>
                        </div>
                        <button type="button" onclick="addFieldRow()" class="btn-add-field">+ Add Another Field</button>
                    </div>

                    <table class="initial-fields-table">
                        <thead>
                            <tr>
                                <th style="width: 20%;">Field Source</th>
                                <th style="width: 56%;">Field Configuration</th>
                                <th style="width: 20%;">Field Type</th>
                                <th style="width: 4%;"></th>
                            </tr>
                        </thead>
                        <tbody id="fields-container">
                            <tr class="field-item-row">
                                <td>
                                    <select name="field_mode[]" onchange="toggleFieldMode(this)" style="width:100%; box-sizing:border-box;">
                                        <option value="new">Create New</option>
                                        <option value="existing">Use Existing</option>
                                    </select>
                                </td>
                                <td class="field-inputs-cell">
                                    <!-- New Field Inputs -->
                                    <div class="new-field-group">
                                        <input type="text" name="field_label[]" placeholder="Field Label (e.g. Topic)" oninput="autoGenerateFieldName(this)" style="width:100%; box-sizing:border-box; margin-bottom:4px;">
                                        <input type="text" name="field_name[]" placeholder="field_machine_name" class="field-name-input field-mono-input" style="width:100%; box-sizing:border-box;">
                                    </div>
                                    <!-- Existing Field Dropdown -->
                                    <div class="existing-field-group" style="display: none;">
                                        <select name="existing_field_name[]" style="width:100%; box-sizing:border-box;">
                                            <option value="">-- Select Existing Field --</option>
                                            <?php foreach ($unique_existing_fields as $ef_name => $ef_label): ?>
                                                <option value="<?= htmlspecialchars($ef_name) ?>"><?= htmlspecialchars($ef_label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </td>
                                <td>
                                    <select name="field_type[]" class="field-type-select" style="width:100%; box-sizing:border-box;">
                                        <option value="text">Text (Single Line)</option>
                                        <option value="taxonomy_term">Taxonomy Terms</option>
                                        <option value="textarea">Long Text</option>
                                        <option value="number">Number</option>
                                        <option value="date">Date</option>
                                        <option value="file">File Attachment</option>
                                        <option value="image">Image Upload</option>
                                        <option value="video">Video (YouTube URL)</option>
                                        <option value="boolean">Boolean Checkbox</option>
                                    </select>
                                </td>
                                <td style="text-align:center;">
                                    <button type="button" class="btn-remove-row" onclick="removeFieldRow(this)" title="Remove field">&times;</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top: 25px; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeCreateTypeModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Save Content Type</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal Control Functions
        function openCreateTypeModal() {
            document.getElementById('createTypeModal').style.display = 'block';
        }

        function closeCreateTypeModal() {
            document.getElementById('createTypeModal').style.display = 'none';
        }

        // Close modal when clicking outside of modal-content
        window.onclick = function(event) {
            const modal = document.getElementById('createTypeModal');
            if (event.target === modal) {
                closeCreateTypeModal();
            }
        };

        // Automatically open modal if there was a form submission error
        <?php if ($error && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            openCreateTypeModal();
        });
        <?php endif; ?>

        // Auto-generate Content Type machine name from Human Name
        const ctHumanName = document.getElementById('ct_human_name');
        const ctMachineName = document.getElementById('ct_machine_name');

        if (ctHumanName && ctMachineName) {
            ctHumanName.addEventListener('input', function() {
                if (ctMachineName.dataset.touched === 'true') return;
                ctMachineName.value = this.value.toLowerCase().trim()
                    .replace(/[^a-z0-9_]/g, '_')
                    .replace(/_+/g, '_')
                    .replace(/^_+|_+$/g, '');
            });

            ctMachineName.addEventListener('input', function() {
                this.dataset.touched = (this.value.trim() !== '') ? 'true' : 'false';
            });
        }

        // Auto-generate Field machine name from Field Label
        function autoGenerateFieldName(labelInput) {
            const row = labelInput.closest('tr');
            if (!row) return;
            const nameInput = row.querySelector('.field-name-input');
            if (!nameInput || nameInput.dataset.touched === 'true') return;

            let slug = labelInput.value.toLowerCase().trim()
                .replace(/[^a-z0-9_]/g, '_')
                .replace(/_+/g, '_')
                .replace(/^_+|_+$/g, '');

            if (slug.length > 0) {
                if (!slug.startsWith('field_')) {
                    slug = 'field_' + slug;
                }
                nameInput.value = slug;
            } else {
                nameInput.value = '';
            }
        }

        // Watch for manual editing of field machine names
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('field-name-input')) {
                e.target.dataset.touched = (e.target.value.trim() !== '') ? 'true' : 'false';
            }
        });

        // Add new field row
// Toggle between Creating New Field vs Using Existing Field
        function toggleFieldMode(selectElem) {
            const row = selectElem.closest('tr');
            const newGroup = row.querySelector('.new-field-group');
            const existingGroup = row.querySelector('.existing-field-group');
            const typeSelect = row.querySelector('.field-type-select');

            if (selectElem.value === 'existing') {
                newGroup.style.display = 'none';
                existingGroup.style.display = 'block';
                if (typeSelect) typeSelect.disabled = true;
            } else {
                newGroup.style.display = 'block';
                existingGroup.style.display = 'none';
                if (typeSelect) typeSelect.disabled = false;
            }
        }

        // Updated Add Field Row supporting both modes
        function addFieldRow() {
            const container = document.getElementById('fields-container');
            const row = document.createElement('tr');
            row.className = 'field-item-row';
            row.innerHTML = `
                <td>
                    <select name="field_mode[]" onchange="toggleFieldMode(this)" style="width:100%; box-sizing:border-box;">
                        <option value="new">Create New</option>
                        <option value="existing">Use Existing</option>
                    </select>
                </td>
                <td class="field-inputs-cell">
                    <div class="new-field-group">
                        <input type="text" name="field_label[]" placeholder="Field Label" oninput="autoGenerateFieldName(this)" style="width:100%; box-sizing:border-box; margin-bottom:4px;">
                        <input type="text" name="field_name[]" placeholder="field_machine_name" class="field-name-input field-mono-input" style="width:100%; box-sizing:border-box;">
                    </div>
                    <div class="existing-field-group" style="display: none;">
                        <select name="existing_field_name[]" style="width:100%; box-sizing:border-box;">
                            <option value="">-- Select Existing Field --</option>
                            <?php foreach ($unique_existing_fields as $ef_name => $ef_label): ?>
                                <option value="<?= htmlspecialchars($ef_name) ?>"><?= htmlspecialchars($ef_label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </td>
                <td>
                    <select name="field_type[]" class="field-type-select" style="width:100%; box-sizing:border-box;">
                        <option value="text">Text (Single Line)</option>
                        <option value="taxonomy_term">Taxonomy Terms</option>
                        <option value="textarea">Long Text</option>
                        <option value="number">Number</option>
                        <option value="date">Date</option>
                        <option value="file">File Attachment</option>
                        <option value="image">Image Upload</option>
                        <option value="video">Video (YouTube URL)</option>
                        <option value="boolean">Boolean Checkbox</option>
                    </select>
                </td>
                <td style="text-align:center;">
                    <button type="button" class="btn-remove-row" onclick="removeFieldRow(this)" title="Remove field">&times;</button>
                </td>
            `;
            container.appendChild(row);
        }

        // Remove field row
        function removeFieldRow(btn) {
            const row = btn.closest('tr');
            const container = document.getElementById('fields-container');
            if (container.querySelectorAll('tr').length > 1) {
                row.remove();
            } else {
                // Clear row if it's the last one remaining
                row.querySelectorAll('input').forEach(input => {
                    input.value = '';
                    input.dataset.touched = 'false';
                });
                row.querySelector('select').selectedIndex = 0;
            }
        }
    </script>
</body>
</html>
