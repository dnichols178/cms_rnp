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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_type') {
    validate_csrf();

    $machine_name = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['type'] ?? '')));
    $label        = trim($_POST['name'] ?? '');
    $desc         = trim($_POST['description'] ?? '');
    $has_body     = isset($_POST['has_body']) ? 1 : 0;
    $url_pattern  = trim($_POST['url_pattern'] ?? '[node:title]');

    $field_names  = $_POST['field_name'] ?? [];
    $field_labels = $_POST['field_label'] ?? [];
    $field_types  = $_POST['field_type'] ?? [];
    $schema       = [];

    if (is_array($field_names)) {
        for ($i = 0; $i < count($field_names); $i++) {
            $flabel = trim($field_labels[$i] ?? '');
            $fname  = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($field_names[$i] ?? '')));

            // Fallback generation if label was entered but machine name was left blank
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Content Types - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    <style>
        .initial-fields-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 18px 20px;
            margin: 20px 0;
        }
        .initial-fields-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        .initial-fields-table {
            width: 100%;
            border-collapse: collapse;
        }
        .initial-fields-table th {
            text-align: left;
            padding: 6px 8px;
            font-size: 0.78rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .initial-fields-table td {
            padding: 6px 8px;
            vertical-align: middle;
        }
        .field-mono-input {
            font-family: monospace;
            background: #ffffff;
        }
        .btn-remove-row {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #dc2626;
            border-radius: 4px;
            padding: 6px 10px;
            cursor: pointer;
            font-size: 0.85rem;
            line-height: 1;
            font-weight: bold;
            transition: all 0.15s ease;
        }
        .btn-remove-row:hover {
            background: #dc2626;
            color: #ffffff;
        }
        .btn-add-field {
            background: #ffffff;
            border: 1px dashed #0b2545;
            color: #0b2545;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.82rem;
            transition: all 0.15s ease;
        }
        .btn-add-field:hover {
            background: #0b2545;
            color: #ffffff;
        }
    </style>
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
                <h3>Existing Content Types</h3>
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

            <section class="card">
                <h3>Create New Content Type</h3>
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
                    <div class="initial-fields-box">
                        <div class="initial-fields-header">
                            <div>
                                <h4 style="margin:0; color:#0b2545;">Initial Fields (Optional)</h4>
                                <span style="font-size:0.82rem; color:#64748b;">Add initial fields now, or configure advanced options later in "Manage Fields".</span>
                            </div>
                            <button type="button" onclick="addFieldRow()" class="btn-add-field">+ Add Another Field</button>
                        </div>

                        <table class="initial-fields-table">
                            <thead>
                                <tr>
                                    <th style="width: 38%;">Field Label</th>
                                    <th style="width: 38%;">Machine Name</th>
                                    <th style="width: 20%;">Field Type</th>
                                    <th style="width: 4%;"></th>
                                </tr>
                            </thead>
                            <tbody id="fields-container">
                                <tr class="field-item-row">
                                    <td>
                                        <input type="text" name="field_label[]" placeholder="e.g. Project Topic" oninput="autoGenerateFieldName(this)" style="width:100%; box-sizing:border-box;">
                                    </td>
                                    <td>
                                        <input type="text" name="field_name[]" placeholder="field_project_topic" class="field-name-input field-mono-input" style="width:100%; box-sizing:border-box;">
                                    </td>
                                    <td>
                                        <select name="field_type[]" style="width:100%; box-sizing:border-box;">
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

                    <div style="margin-top: 25px;">
                        <button type="submit" class="btn-primary">Save Content Type</button>
                    </div>
                </form>
            </section>
        </main>
    </div>

    <script>
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
        function addFieldRow() {
            const container = document.getElementById('fields-container');
            const row = document.createElement('tr');
            row.className = 'field-item-row';
            row.innerHTML = `
                <td>
                    <input type="text" name="field_label[]" placeholder="Field Label" oninput="autoGenerateFieldName(this)" style="width:100%; box-sizing:border-box;">
                </td>
                <td>
                    <input type="text" name="field_name[]" placeholder="field_machine_name" class="field-name-input field-mono-input" style="width:100%; box-sizing:border-box;">
                </td>
                <td>
                    <select name="field_type[]" style="width:100%; box-sizing:border-box;">
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