<?php declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/registrations.php';

require_permission('view_registrations');

$db = get_db();

$active_form_id = isset($_GET['form_id']) && $_GET['form_id'] !== '' ? (int)$_GET['form_id'] : null;
$active_form = $active_form_id ? get_registration_form_by_id($active_form_id) : null;
$action = $_GET['action'] ?? 'list';

// Load All Forms
$forms_list = $db->query('SELECT * FROM registration_forms ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);

// 1. Build Dictionary of Existing Fields (from all other registration forms & content types)
$existing_fields_pool = [];
$current_form_fields = $active_form ? array_column($active_form['fields_schema'] ?? [], 'name') : [];

foreach ($forms_list as $f_item) {
    if ($active_form && (int)$f_item['id'] === (int)$active_form['id']) continue;
    $f_schema = json_decode($f_item['fields_schema'] ?? '[]', true) ?: [];
    foreach ($f_schema as $f) {
        $fname = $f['name'] ?? '';
        if ($fname === '' || in_array($fname, $current_form_fields, true)) continue;
        if (!isset($existing_fields_pool[$fname])) {
            $existing_fields_pool[$fname] = [
                'name'      => $fname,
                'label'     => $f['label'] ?? $fname,
                'type'      => $f['type'] ?? 'text',
                'help_text' => $f['help_text'] ?? '',
                'required'  => !empty($f['required']) ? 1 : 0,
                'options'   => $f['options'] ?? [],
                'source'    => 'Form: ' . $f_item['title']
            ];
        }
    }
}

$all_content_types = get_content_types();
foreach ($all_content_types as $ct) {
    $ct_schema = json_decode($ct['fields_schema'] ?? '[]', true) ?: [];
    foreach ($ct_schema as $f) {
        $fname = $f['name'] ?? '';
        if ($fname === '' || $fname === 'body' || in_array($fname, $current_form_fields, true)) continue;
        if (!isset($existing_fields_pool[$fname])) {
            $existing_fields_pool[$fname] = [
                'name'      => $fname,
                'label'     => $f['label'] ?? $fname,
                'type'      => $f['type'] ?? 'text',
                'help_text' => $f['help_text'] ?? '',
                'required'  => !empty($f['required']) ? 1 : 0,
                'options'   => $f['options'] ?? [],
                'source'    => 'Content Type: ' . $ct['name']
            ];
        }
    }
}
ksort($existing_fields_pool);

// 2. Export Action (CSV / JSON)
if ($action === 'export' && $active_form) {
    require_permission('delete_registrations');
    $format = $_GET['format'] ?? 'csv';

    $stmt = $db->prepare('
        SELECT r.id, r.name, r.email, r.phone, r.organization, r.slots, r.state, 
               r.submission_data, r.ip_address, r.created_at
        FROM registrations r
        WHERE r.form_id = :fid
        ORDER BY r.created_at DESC
    ');
    $stmt->execute(['fid' => $active_form['id']]);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $timestamp = date('Ymd_His');
    $slug_name = $active_form['slug'] ?: 'form_' . $active_form['id'];

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$slug_name}_export_{$timestamp}.json\"");
        echo json_encode($submissions, JSON_PRETTY_PRINT);
        exit;
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$slug_name}_export_{$timestamp}.csv\"");
        $out = fopen('php://output', 'w');

        $schema = $active_form['fields_schema'] ?? [];
        $header = ['Submission ID', 'Attendee Name', 'Email', 'Phone', 'Organization', 'Slots', 'State', 'IP Address', 'Submitted At'];
        foreach ($schema as $f) {
            $header[] = $f['label'] . ' (' . $f['name'] . ')';
        }
        fputcsv($out, $header);

        foreach ($submissions as $sub) {
            $custom = json_decode($sub['submission_data'] ?? '{}', true) ?: [];
            $row = [
                $sub['id'],
                $sub['name'],
                $sub['email'],
                $sub['phone'],
                $sub['organization'],
                $sub['slots'],
                $sub['state'],
                $sub['ip_address'],
                $sub['created_at']
            ];
            foreach ($schema as $f) {
                $fname = $f['name'];
                $val = $custom[$fname] ?? '';
                $row[] = is_array($val) ? implode(', ', $val) : $val;
            }
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}

// 3. Action: Toggle Form Status (Enable / Disable)
if ($action === 'toggle_form' && isset($_GET['id'])) {
    require_permission('edit_registrations');
    validate_csrf();
    $toggle_id = (int)$_GET['id'];
    $stmt = $db->prepare('UPDATE registration_forms SET status = IF(status = 1, 0, 1) WHERE id = :id');
    $stmt->execute(['id' => $toggle_id]);
    
    $form_record = get_registration_form_by_id($toggle_id);
    if (!empty($form_record['block_id'])) {
        $db->prepare('UPDATE blocks SET status = ? WHERE id = ?')->execute([$form_record['status'], $form_record['block_id']]);
    }
    
    watchdog('registration', "Toggled status for registration form #{$toggle_id}", 'notice');
    set_flash('success', 'Form status updated.');
    $redir = $active_form_id ? base_path("admin/registrations.php?form_id={$active_form_id}") : base_path('admin/registrations.php');
    header('Location: ' . base_path($redir));
    exit;
}

// 4. Action: Delete Entire Registration Form
if ($action === 'delete_form' && isset($_GET['id'])) {
    require_permission('delete_registrations');
    validate_csrf();
    $del_id = (int)$_GET['id'];
    $form_record = get_registration_form_by_id($del_id);
    if ($form_record && !empty($form_record['block_id'])) {
        $db->prepare('DELETE FROM blocks WHERE id = ?')->execute([$form_record['block_id']]);
    }
    $db->prepare('DELETE FROM registration_forms WHERE id = ?')->execute([$del_id]);
    watchdog('registration', "Deleted registration form #{$del_id}", 'notice');
    set_flash('success', 'Registration form deleted.');
    header('Location: ' . base_path('admin/registrations.php'));
    exit;
}

// 5. Action: Delete Individual Attendee Submission
if ($action === 'delete_sub' && isset($_GET['id'])) {
    require_permission('delete_registrations');
    validate_csrf();
    $sub_id = (int)$_GET['id'];
    $db->prepare('DELETE FROM registrations WHERE id = ?')->execute([$sub_id]);
    set_flash('success', 'Attendee submission removed.');
    $redir = $active_form_id ? base_path("admin/registrations.php?form_id={$active_form_id}") : base_path('admin/registrations.php');
    header('Location: ' . base_path($redir));
    exit;
}

// 6. Action: Update Submission State
if ($action === 'set_state' && isset($_GET['id']) && isset($_GET['state'])) {
    require_permission('edit_registrations');
    validate_csrf();
    $new_state = $_GET['state'];
    if (in_array($new_state, ['complete', 'waitlist', 'pending', 'canceled'], true)) {
        $db->prepare('UPDATE registrations SET state = ? WHERE id = ?')->execute([$new_state, (int)$_GET['id']]);
        set_flash('success', "Registration state updated to {$new_state}.");
    }
    $redir = $active_form_id ? base_path("admin/registrations.php?form_id={$active_form_id}") : base_path('admin/registrations.php');
    header('Location: ' . base_path($redir));
    exit;
}

// 7. Action: Save Step 1 (Form Creation & Base Settings)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_form_step1') {
    require_permission('edit_registrations');
    validate_csrf();
    $form_id        = (int)($_POST['form_id'] ?? 0);
    $title          = trim($_POST['title'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $host_type      = in_array($_POST['host_type'] ?? '', ['page', 'block'], true) ? $_POST['host_type'] : 'page';
    $slug           = strtolower(trim(preg_replace('/[^a-z0-9_\-\/]/i', '', str_replace(' ', '-', $_POST['slug'] ?? ''))));
    $status         = isset($_POST['status']) ? 1 : 0;
    $capacity       = max(0, (int)($_POST['capacity'] ?? 0));
    $allow_waitlist = isset($_POST['allow_waitlist']) ? 1 : 0;
    $open_date      = !empty($_POST['open_date']) ? str_replace('T', ' ', $_POST['open_date']) : null;
    $close_date     = !empty($_POST['close_date']) ? str_replace('T', ' ', $_POST['close_date']) : null;
    $conf_msg       = trim($_POST['confirmation_message'] ?? '');
    $advance_fields = isset($_POST['advance_to_fields']);

    if ($title === '') {
        set_flash('error', 'Form Title is required.');
        $redir = $form_id > 0 ? base_path("admin/registrations.php?form_id={$form_id}&action=settings") : base_path('admin/registrations.php?action=create');
        header('Location: ' . base_path($redir));
        exit;
    } elseif ($host_type === 'page' && $slug === '') {
        set_flash('error', 'URL Alias Slug is required for page-hosted forms.');
        $redir = $form_id > 0 ? base_path("admin/registrations.php?form_id={$form_id}&action=settings") : base_path('admin/registrations.php?action=create');
        header('Location: ' . base_path($redir));
        exit;
    } else {
        if ($form_id > 0) {
            $stmt = $db->prepare('
                UPDATE registration_forms 
                SET title = ?, description = ?, host_type = ?, slug = ?, status = ?, capacity = ?, 
                    allow_waitlist = ?, open_date = ?, close_date = ?, confirmation_message = ?
                WHERE id = ?
            ');
            $stmt->execute([$title, $description, $host_type, $slug, $status, $capacity, $allow_waitlist, $open_date, $close_date, $conf_msg, $form_id]);
            $saved_fid = $form_id;
        } else {
            $stmt = $db->prepare('
                INSERT INTO registration_forms (title, description, host_type, slug, status, capacity, allow_waitlist, open_date, close_date, confirmation_message, fields_schema)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "[]")
            ');
            $stmt->execute([$title, $description, $host_type, $slug, $status, $capacity, $allow_waitlist, $open_date, $close_date, $conf_msg]);
            $saved_fid = (int)$db->lastInsertId();
        }

        if ($host_type === 'block') {
            $existing_block_id = $db->query("SELECT block_id FROM registration_forms WHERE id = {$saved_fid}")->fetchColumn();
            $block_body = "[registration_form:{$saved_fid}]";
            if ($existing_block_id) {
                $b_stmt = $db->prepare('UPDATE blocks SET title = ?, body = ?, status = ? WHERE id = ?');
                $b_stmt->execute([$title, $block_body, $status, $existing_block_id]);
            } else {
                $b_stmt = $db->prepare('INSERT INTO blocks (title, region, body, weight, status, visibility_pages, visibility_mode) VALUES (?, "sidebar", ?, 0, ?, "", "show_only")');
                $b_stmt->execute([$title, $block_body, $status]);
                $new_bid = (int)$db->lastInsertId();
                $db->prepare('UPDATE registration_forms SET block_id = ? WHERE id = ?')->execute([$new_bid, $saved_fid]);
            }
        }

        if ($advance_fields) {
            set_flash('success', 'Form settings saved! Now configure custom fields.');
            header('Location: ' . base_path("admin/registrations.php?form_id={$saved_fid}&action=fields"));
            exit;
        } else {
            set_flash('success', 'Registration settings saved.');
            header('Location: ' . base_path("admin/registrations.php?form_id={$saved_fid}"));
            exit;
        }
    }
}

// 8. Action: Save Custom Field (Add or Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_field_item' && $active_form) {
    require_permission('edit_registrations');
    validate_csrf();
    $field_label   = trim($_POST['field_label'] ?? '');
    $field_name    = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['field_name'] ?? '')));
    $field_type    = trim($_POST['field_type'] ?? 'text');
    $is_required   = isset($_POST['is_required']) ? 1 : 0;
    $help_text     = trim($_POST['help_text'] ?? '');
    $raw_options   = trim($_POST['options'] ?? '');

    if ($field_label === '') {
        set_flash('error', 'Field label is required.');
    } else {
        if ($field_name === '') {
            $field_name = 'field_' . preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', $field_label)));
        } elseif (!str_starts_with($field_name, 'field_')) {
            $field_name = 'field_' . $field_name;
        }

        $options = [];
        if (in_array($field_type, ['select', 'radio', 'checkbox_group'], true) && $raw_options !== '') {
            foreach (explode("\n", str_replace("\r", "", $raw_options)) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (str_contains($line, '|')) {
                    [$k, $v] = explode('|', $line, 2);
                    $options[trim($k)] = trim($v);
                } else {
                    $options[$line] = $line;
                }
            }
        }

        $schema = $active_form['fields_schema'] ?? [];
        $found = false;
        foreach ($schema as &$existing_f) {
            if ($existing_f['name'] === $field_name) {
                $existing_f['label']     = $field_label;
                $existing_f['type']      = $field_type;
                $existing_f['required']  = $is_required;
                $existing_f['help_text'] = $help_text;
                $existing_f['options']   = $options;
                $found = true;
                break;
            }
        }
        unset($existing_f);

        if (!$found) {
            $schema[] = [
                'name'      => $field_name,
                'label'     => $field_label,
                'type'      => $field_type,
                'required'  => $is_required,
                'help_text' => $help_text,
                'options'   => $options
            ];
        }

        $db->prepare('UPDATE registration_forms SET fields_schema = ? WHERE id = ?')->execute([json_encode($schema), $active_form['id']]);
        set_flash('success', "Field '{$field_label}' saved successfully.");
    }

    header('Location: ' . base_path("admin/registrations.php?form_id={$active_form['id']}&action=fields"));
    exit;
}

// Action: Delete Field from Registration Form
if ($action === 'delete_field' && isset($_GET['field']) && $active_form) {
    require_permission('edit_registrations');
    validate_csrf();
    $to_del = trim($_GET['field']);
    $schema = array_values(array_filter($active_form['fields_schema'] ?? [], fn($f) => $f['name'] !== $to_del));
    $db->prepare('UPDATE registration_forms SET fields_schema = ? WHERE id = ?')->execute([json_encode($schema), $active_form['id']]);
    set_flash('success', 'Field removed from registration schema.');
    header('Location: ' . base_path("admin/registrations.php?form_id={$active_form['id']}&action=fields"));
    exit;
}

$active_submissions = [];
if ($active_form && $action === 'list') {
    $sub_stmt = $db->prepare('SELECT * FROM registrations WHERE form_id = :fid ORDER BY created_at DESC');
    $sub_stmt->execute(['fid' => $active_form['id']]);
    $active_submissions = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Registration Forms & Rosters - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            
            <?php if ($action === 'create' || ($action === 'settings' && $active_form)): ?>
                <!-- Step 1: Form Settings Page -->
                <?php $is_edit = ($action === 'settings' && $active_form); ?>
                <div style="font-size: 0.84rem; color: #64748b; margin-bottom: 8px;">
                    <a href="<?= base_path('admin/registrations.php') ?>" style="color: #0b2545; text-decoration: none;">&laquo; All Registration Forms</a>
                    <?php if ($is_edit): ?>
                         &raquo; <a href="<?= base_path('admin/registrations.php?form_id=' . $active_form['id']) ?>" style="color: #0b2545; text-decoration: none;"><?= htmlspecialchars($active_form['title']) ?></a>
                    <?php endif; ?>
                </div>

                <div class="page-header-actions">
                    <div>
                        <h1><?= $is_edit ? 'Edit Settings: ' . htmlspecialchars($active_form['title']) : 'Create Registration Form' ?></h1>
                        <p style="color: #64748b; font-size: 0.88rem; margin-top: -12px;">
                            Step 1: Configure form host targets, capacity quotas, and availability windows.
                        </p>
                    </div>
                </div>

                <?php render_flash_messages(); ?>

                <section class="card" style="max-width: 800px;">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="save_form_step1">
                        <input type="hidden" name="form_id" value="<?= $is_edit ? $active_form['id'] : 0 ?>">

                        <div class="form-row">
                            <label>Form Title *</label>
                            <input type="text" name="title" required placeholder="e.g. Annual Technical Tour" value="<?= htmlspecialchars($is_edit ? $active_form['title'] : ($_POST['title'] ?? '')) ?>">
                        </div>

                        <div class="form-row">
                            <label>Description / Event Instructions</label>
                            <textarea name="description" rows="3" placeholder="Brief event summary shown above the registration form..."><?= htmlspecialchars($is_edit ? ($active_form['description'] ?? '') : ($_POST['description'] ?? '')) ?></textarea>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1.5fr; gap:16px;">
                            <div class="form-row">
                                <label>Host Type *</label>
                                <select name="host_type" id="host_type_select" onchange="toggleHostType(this.value)">
                                    <option value="page" <?= ($is_edit && $active_form['host_type'] === 'page') ? 'selected' : '' ?>>Page (Direct URL)</option>
                                    <option value="block" <?= ($is_edit && $active_form['host_type'] === 'block') ? 'selected' : '' ?>>Block (Region Widget)</option>
                                </select>
                            </div>
                            <div class="form-row" id="slug_input_row" style="<?= ($is_edit && $active_form['host_type'] === 'block') ? 'display:none;' : '' ?>">
                                <label>URL Alias Slug *</label>
                                <input type="text" name="slug" placeholder="e.g. register/annual-tour" value="<?= htmlspecialchars($is_edit ? ($active_form['slug'] ?? '') : ($_POST['slug'] ?? '')) ?>">
                                <small>Accessible at website public URL path</small>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                            <div class="form-row">
                                <label>Capacity Quota (0 = Unlimited)</label>
                                <input type="number" name="capacity" min="0" value="<?= htmlspecialchars((string)($is_edit ? $active_form['capacity'] : 0)) ?>">
                            </div>
                            <div class="form-row">
                                <label>Form Status</label>
                                <label style="font-weight:normal; display:flex; align-items:center; gap:8px; margin-top:8px; cursor:pointer;">
                                    <input type="checkbox" name="status" value="1" <?= (!$is_edit || !empty($active_form['status'])) ? 'checked' : '' ?>>
                                    <strong>Active / Open</strong>
                                </label>
                            </div>
                        </div>

                        <div class="form-row">
                            <label style="font-weight:normal; display:flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" name="allow_waitlist" value="1" <?= (!$is_edit || !empty($active_form['allow_waitlist'])) ? 'checked' : '' ?>>
                                <strong>Allow Waitlist</strong> when capacity is reached
                            </label>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                            <div class="form-row">
                                <label>Open Date (Optional)</label>
                                <input type="datetime-local" name="open_date" value="<?= htmlspecialchars($is_edit && !empty($active_form['open_date']) ? str_replace(' ', 'T', substr($active_form['open_date'], 0, 16)) : '') ?>">
                            </div>
                            <div class="form-row">
                                <label>Close Date (Optional)</label>
                                <input type="datetime-local" name="close_date" value="<?= htmlspecialchars($is_edit && !empty($active_form['close_date']) ? str_replace(' ', 'T', substr($active_form['close_date'], 0, 16)) : '') ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <label>Custom Confirmation Message</label>
                            <textarea name="confirmation_message" rows="2" placeholder="Custom success message shown upon submission..."><?= htmlspecialchars($is_edit ? ($active_form['confirmation_message'] ?? '') : '') ?></textarea>
                        </div>

                        <div style="display:flex; gap:10px; margin-top:20px;">
                            <button type="submit" class="btn-primary">Save Settings</button>
                            <?php if (!$is_edit): ?>
                                <button type="submit" name="advance_to_fields" value="1" class="btn-secondary">Save & Configure Fields &raquo;</button>
                            <?php else: ?>
                                <a href="<?= base_path("admin/registrations.php?form_id={$active_form['id']}&action=fields") ?>" class="btn-secondary">Manage Custom Fields &raquo;</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </section>
                <script>
                function toggleHostType(val) {
                    document.getElementById('slug_input_row').style.display = (val === 'page') ? 'block' : 'none';
                }
                </script>

            <?php elseif ($action === 'fields' && $active_form): ?>
                <!-- Step 2: Custom Fields Management Page -->
                <div style="font-size: 0.84rem; color: #64748b; margin-bottom: 8px;">
                    <a href="<?= base_path('admin/registrations.php') ?>" style="color: #0b2545; text-decoration: none;">&laquo; All Registration Forms</a> &raquo;
                    <a href="<?= base_path('admin/registrations.php?form_id=' . $active_form['id']) ?>" style="color: #0b2545; text-decoration: none;"><?= htmlspecialchars($active_form['title']) ?></a>
                </div>

                <div class="page-header-actions">
                    <div>
                        <h1>Manage Fields: <?= htmlspecialchars($active_form['title']) ?></h1>
                        <p style="color: #64748b; font-size: 0.88rem; margin-top: -12px;">
                            Step 2: Add, edit, or remove custom attendee questions, agreements, and specialized data inputs.
                        </p>
                    </div>
                    <div>
                        <a href="<?= base_path('admin/registrations.php?form_id=' . $active_form['id']) ?>" class="btn-primary">&laquo; Back to Attendee Roster</a>
                    </div>
                </div>

                <?php render_flash_messages(); ?>

                <section class="card" style="margin-bottom:25px;">
                    <h3 style="color:var(--frnp-navy); margin-bottom:12px;">Current Form Fields</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Field Label</th>
                                <th>Identifier</th>
                                <th>Type</th>
                                <th>Required</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="background:#f8fafc; color:#64748b; font-size:0.84rem;">
                                <td>Email Address</td><td><code>reg_email</code></td><td>EMAIL</td><td>Yes</td><td style="text-align:right;"><em>Core</em></td>
                            </tr>
                            <?php if (empty($active_form['fields_schema'])): ?>
                                <tr><td colspan="5" style="text-align:center; color:#777; padding:15px;">No custom fields added yet. Add a new or existing field below.</td></tr>
                            <?php else: ?>
                                <?php foreach ($active_form['fields_schema'] as $fld): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($fld['label']) ?></strong></td>
                                        <td><code><?= htmlspecialchars($fld['name']) ?></code></td>
                                        <td><?= strtoupper(htmlspecialchars(str_replace('_', ' ', $fld['type']))) ?></td>
                                        <td><?= !empty($fld['required']) ? '<span style="color:#c00; font-weight:700;">Yes</span>' : 'No' ?></td>
                                        <td style="text-align:right;">
                                            <button type="button" class="btn-secondary" style="font-size:0.75rem; padding:3px 8px; margin-right:4px;" onclick='editFieldItem(<?= json_encode($fld, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>Edit</button>
                                            <a href="<?= base_path('admin/registrations.php?form_id=' . (int)$active_form['id'] . '&action=delete_field&field=' . urlencode($fld['name']) . '&csrf_token=' . get_csrf_token()) ?>" 
                                               data-confirm="Remove this field from form?"
                                               data-confirm-title="Remove Custom Field"
                                               data-confirm-btn="Delete"
                                               style="color:#c00; font-size:0.8rem; text-decoration:none;">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <section class="card" style="max-width: 800px;">
                    <form method="POST" onsubmit="prepareFieldSubmit()">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="save_field_item">
                        <h3 id="form_box_title" style="color:var(--frnp-navy); margin-bottom:16px;">+ Add Field to Registration Form</h3>

                        <div id="source_mode_group" style="display:flex; gap:20px; margin-bottom:16px;">
                            <label style="font-weight:600; font-size:0.88rem; cursor:pointer; display:flex; align-items:center; gap:6px;">
                                <input type="radio" name="field_source_mode" id="reg_source_new" value="new" checked onchange="toggleFieldSource('new')">
                                Create new field
                            </label>
                            <label style="font-weight:600; font-size:0.88rem; cursor:pointer; display:flex; align-items:center; gap:6px;">
                                <input type="radio" name="field_source_mode" id="reg_source_existing" value="existing" onchange="toggleFieldSource('existing')"
                                       <?= empty($existing_fields_pool) ? 'disabled title="No unattached fields found in other forms"' : '' ?>>
                                Re-use existing field <?= empty($existing_fields_pool) ? '<span style="font-weight:normal; color:#94a3b8;">(none available)</span>' : '' ?>
                            </label>
                        </div>

                        <div class="form-row" id="existing_picker_row" style="display:none; background:#eff6ff; border:1px solid #bfdbfe; padding:14px; border-radius:6px; margin-bottom:16px;">
                            <label style="color:#1e40af; font-weight:700;">Select Existing Field by Machine Name *</label>
                            <select id="existing_field_select" onchange="onExistingFieldSelected(this.value)" style="width:100%; padding:8px;">
                                <option value="">-- Choose an existing field --</option>
                                <?php foreach ($existing_fields_pool as $ex_name => $ex_data): ?>
                                    <option value="<?= htmlspecialchars($ex_name) ?>">
                                        <?= htmlspecialchars($ex_data['label']) ?> (<code><?= htmlspecialchars($ex_name) ?></code> &bull; <?= strtoupper(htmlspecialchars($ex_data['type'])) ?> from <?= htmlspecialchars($ex_data['source']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                            <div class="form-row">
                                <label>Field Label *</label>
                                <input type="text" name="field_label" id="field_label_input" required placeholder="e.g. Phone Number or Dietary Needs" onkeyup="autoIdentifier(this.value)">
                            </div>
                            <div class="form-row">
                                <label>Machine Identifier</label>
                                <input type="text" name="field_name" id="field_name_input" placeholder="field_phone_number">
                                <small id="field_name_help">Auto-generated if creating new field.</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <label>Field Type</label>
                            <select name="field_type" id="field_type_select" onchange="toggleOptionsBox(this.value)">
                                <option value="text">Text (Single Line)</option>
                                <option value="phone">Phone Number ((000) 000-0000)</option>
                                <option value="checkbox">Single Checkbox (Agreement / Yes-No)</option>
                                <option value="textarea">Textarea (Multi-Line)</option>
                                <option value="select">Dropdown Menu (Single Choice)</option>
                                <option value="radio">Radio Buttons (Single Choice)</option>
                                <option value="checkbox_group">Checkbox Group (Multiple Choice)</option>
                                <option value="number">Number</option>
                                <option value="date">Date</option>
                            </select>
                        </div>

                        <div class="form-row" id="field_opts_box" style="display:none;">
                            <label>Options (One per line: key|Label)</label>
                            <textarea name="options" id="field_options_textarea" rows="3" placeholder="opt1|Option One&#10;opt2|Option Two"></textarea>
                        </div>

                        <div class="form-row">
                            <label>Help Text</label>
                            <input type="text" name="help_text" id="field_help_input" placeholder="Instructions shown under field">
                        </div>

                        <div class="form-row">
                            <label style="font-weight:normal; display:flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" name="is_required" id="field_required_check" value="1"> <strong>Required Field</strong>
                            </label>
                        </div>

                        <div style="display:flex; gap:10px; margin-top:10px;">
                            <button type="submit" id="field_submit_btn" class="btn-primary">Add Field</button>
                            <button type="button" id="field_cancel_btn" class="btn-secondary" style="display:none;" onclick="resetFieldForm()">Cancel Edit</button>
                        </div>
                    </form>
                </section>
                <script>
                const existingPool = <?= json_encode($existing_fields_pool, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
                function toggleOptionsBox(type) {
                    const needsOpts = ['select', 'radio', 'checkbox_group'].includes(type);
                    document.getElementById('field_opts_box').style.display = needsOpts ? 'block' : 'none';
                }
                function autoIdentifier(val) {
                    if (document.getElementById('reg_source_existing') && document.getElementById('reg_source_existing').checked) return;
                    if (document.getElementById('field_name_input').readOnly) return;
                    const clean = val.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
                    document.getElementById('field_name_input').value = clean ? 'field_' + clean : '';
                }
                function toggleFieldSource(mode) {
                    const isExisting = (mode === 'existing');
                    document.getElementById('existing_picker_row').style.display = isExisting ? 'block' : 'none';
                    const nameInput = document.getElementById('field_name_input');
                    const typeSelect = document.getElementById('field_type_select');
                    if (!isExisting) {
                        nameInput.readOnly = false;
                        nameInput.style.background = '#ffffff';
                        typeSelect.disabled = false;
                    }
                }
                function onExistingFieldSelected(name) {
                    if (!name || !existingPool[name]) return;
                    const f = existingPool[name];
                    document.getElementById('field_label_input').value = f.label;
                    const nameInput = document.getElementById('field_name_input');
                    nameInput.value = f.name;
                    nameInput.readOnly = true;
                    nameInput.style.background = '#f1f5f9';
                    const typeSelect = document.getElementById('field_type_select');
                    typeSelect.value = f.type;
                    typeSelect.disabled = true;
                    document.getElementById('field_help_input').value = f.help_text || '';
                    document.getElementById('field_required_check').checked = !!f.required;
                    toggleOptionsBox(f.type);
                }
                function editFieldItem(f) {
                    document.getElementById('form_box_title').innerText = 'Edit Field: ' + f.label;
                    document.getElementById('source_mode_group').style.display = 'none';
                    document.getElementById('existing_picker_row').style.display = 'none';
                    
                    document.getElementById('field_label_input').value = f.label;
                    const nameInput = document.getElementById('field_name_input');
                    nameInput.value = f.name;
                    nameInput.readOnly = true;
                    nameInput.style.background = '#f1f5f9';
                    document.getElementById('field_name_help').innerText = 'Machine identifier cannot be modified during edit.';

                    const typeSelect = document.getElementById('field_type_select');
                    typeSelect.value = f.type;
                    document.getElementById('field_help_input').value = f.help_text || '';
                    document.getElementById('field_required_check').checked = !!f.required;

                    let optsStr = '';
                    if (f.options && typeof f.options === 'object') {
                        if (Array.isArray(f.options)) {
                            optsStr = f.options.join('\n');
                        } else {
                            for (const [k, v] of Object.entries(f.options)) {
                                optsStr += `${k}|${v}\n`;
                            }
                        }
                    }
                    document.getElementById('field_options_textarea').value = optsStr.trim();
                    toggleOptionsBox(f.type);

                    document.getElementById('field_submit_btn').innerText = 'Save Field Changes';
                    document.getElementById('field_cancel_btn').style.display = 'inline-block';
                    window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
                }
                function resetFieldForm() {
                    document.getElementById('form_box_title').innerText = '+ Add Field to Registration Form';
                    document.getElementById('source_mode_group').style.display = 'flex';
                    document.getElementById('field_label_input').value = '';
                    const nameInput = document.getElementById('field_name_input');
                    nameInput.value = '';
                    nameInput.readOnly = false;
                    nameInput.style.background = '#ffffff';
                    document.getElementById('field_name_help').innerText = 'Auto-generated if creating new field.';
                    const typeSelect = document.getElementById('field_type_select');
                    typeSelect.value = 'text';
                    typeSelect.disabled = false;
                    document.getElementById('field_options_textarea').value = '';
                    document.getElementById('field_help_input').value = '';
                    document.getElementById('field_required_check').checked = false;
                    toggleOptionsBox('text');
                    document.getElementById('field_submit_btn').innerText = 'Add Field';
                    document.getElementById('field_cancel_btn').style.display = 'none';
                }
                function prepareFieldSubmit() {
                    document.getElementById('field_type_select').disabled = false;
                }
                </script>

            <?php elseif ($active_form && $action === 'list'): ?>
                <!-- Active Form Attendee Submissions Roster View -->
                <div style="font-size: 0.84rem; color: #64748b; margin-bottom: 8px;">
                    <a href="<?= base_path('admin/registrations.php') ?>" style="color: #0b2545; text-decoration: none;">&laquo; All Registration Forms</a> &raquo;
                    <span><?= htmlspecialchars($active_form['title']) ?></span>
                </div>

                <div class="page-header-actions">
                    <div>
                        <h1><?= htmlspecialchars($active_form['title']) ?> &raquo; Attendee Roster</h1>
                        <p style="color: #64748b; font-size: 0.88rem; margin-top: -12px;">
                            Host Target: <strong><?= strtoupper($active_form['host_type']) ?></strong> 
                            <?= $active_form['host_type'] === 'page' ? '(<a href="' . base_path($active_form['slug']) . '" target="_blank">/' . htmlspecialchars($active_form['slug']) . '</a>)' : '(<a href="' . base_path('admin/blocks.php') . '">Configured in Blocks</a>)' ?>
                        </p>
                    </div>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <?php if (user_has_permission('delete_registrations')): ?>
                            <div class="export-group">
                                <span style="font-size:0.75rem; padding: 7px 10px; background:#f8fafc; color:#64748b; font-weight:700;">EXPORT:</span>
                                <a href="<?= base_path('admin/registrations.php?action=export&format=csv&form_id=' . (int)$active_form['id']) ?>" class="export-btn">CSV</a>
                                <a href="<?= base_path('admin/registrations.php?action=export&format=json&form_id=' . (int)$active_form['id']) ?>" class="export-btn">JSON</a>
                            </div>
                        <?php endif; ?>

                        <?php if (user_has_permission('edit_registrations')): ?>
                            <a href="<?= base_path('admin/registrations.php?action=toggle_form&id=' . (int)$active_form['id'] . '&csrf_token=' . get_csrf_token()) ?>" class="btn-secondary" style="font-size:0.84rem;">
                                <?= $active_form['status'] ? 'Pause / Close Form' : 'Activate Form' ?>
                            </a>
                            <a href="<?= base_path('admin/registrations.php?form_id=' . (int)$active_form['id'] . '&action=fields') ?>" class="btn-primary" style="font-size:0.84rem;">Manage Fields</a>
                            <a href="<?= base_path('admin/registrations.php?form_id=' . (int)$active_form['id'] . '&action=settings') ?>" class="btn-secondary" style="font-size:0.84rem;">Settings</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php render_flash_messages(); ?>

                <?php 
                    $stat = get_form_attendee_counts((int)$active_form['id']);
                    $cap  = (int)$active_form['capacity'];
                ?>
                <div class="stat-banner">
                    <div class="stat-card">
                        <div class="stat-val"><?= $stat['confirmed'] ?> <?= $cap > 0 ? '/ ' . $cap : '' ?></div>
                        <div class="stat-lbl">Confirmed Slots</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-val"><?= $stat['waitlist'] ?></div>
                        <div class="stat-lbl">Waitlist Count</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-val"><?= $stat['total'] ?></div>
                        <div class="stat-lbl">Total Submissions</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-val">
                            <span class="badge-status <?= $active_form['status'] ? 'status-published' : 'status-archived' ?>" style="font-size:0.85rem;">
                                <?= $active_form['status'] ? 'OPEN' : 'CLOSED' ?>
                            </span>
                        </div>
                        <div class="stat-lbl">Form State</div>
                    </div>
                </div>

                <section class="card">
                    <table>
                        <thead>
                            <tr>
                                <th>Attendee Email & Details</th>
                                <th>Slots</th>
                                <th>Custom Fields Data</th>
                                <th>State</th>
                                <th>Submitted At</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($active_submissions)): ?>
                                <tr><td colspan="6" style="text-align:center; color:#64748b; padding:25px;">No attendee submissions recorded yet for this form.</td></tr>
                            <?php else: ?>
                                <?php foreach ($active_submissions as $s): ?>
                                    <?php $c_data = json_decode($s['submission_data'] ?? '{}', true) ?: []; ?>
                                    <tr>
                                        <td>
                                            <strong><a href="mailto:<?= htmlspecialchars($s['email']) ?>"><?= htmlspecialchars($s['email']) ?></a></strong>
                                            <?php if (!empty($s['name'])): ?>
                                                <div style="font-size:0.78rem; color:#64748b;">Name: <?= htmlspecialchars($s['name']) ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($s['phone'])): ?>
                                                <div style="font-size:0.78rem; color:#64748b;">Phone: <?= htmlspecialchars($s['phone']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?= (int)$s['slots'] ?></strong></td>
                                        <td style="font-size:0.8rem; color:#475569;">
                                            <?php if (empty($c_data)): ?>
                                                <em>None</em>
                                            <?php else: ?>
                                                <?php foreach ($c_data as $k => $v): ?>
                                                    <div><strong><?= htmlspecialchars($k) ?>:</strong> <?= htmlspecialchars(is_array($v) ? implode(', ', $v) : (string)$v) ?></div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge-status <?= $s['state'] === 'complete' ? 'status-published' : ($s['state'] === 'waitlist' ? 'status-draft' : 'status-archived') ?>">
                                                <?= strtoupper(htmlspecialchars($s['state'])) ?>
                                            </span>
                                        </td>
                                        <td style="font-size:0.82rem; color:#64748b;"><?= date('M j, Y H:i', strtotime($s['created_at'])) ?></td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <?php if (user_has_permission('edit_registrations') && $s['state'] === 'waitlist'): ?>
                                                <a href="<?= base_path('admin/registrations.php?action=set_state&id=' . (int)$s['id'] . '&state=complete&form_id=' . (int)$active_form['id'] . '&csrf_token=' . get_csrf_token()) ?>" 
                                                   class="btn-secondary" style="font-size:0.75rem; padding:3px 6px; color:#065f46;">Confirm</a>
                                            <?php endif; ?>
                                            <?php if (user_has_permission('delete_registrations')): ?>
                                                <a href="<?= base_path('admin/registrations.php?action=delete_sub&id=' . (int)$s['id'] . '&form_id=' . (int)$active_form['id'] . '&csrf_token=' . get_csrf_token()) ?>" 
                                                   data-confirm="Permanently remove this attendee registration?"
                                                   data-confirm-title="Remove Registration"
                                                   data-confirm-btn="Delete"
                                                   style="color:#c00; font-size:0.8rem; margin-left:6px; text-decoration:none;">Delete</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>

            <?php else: ?>
                <!-- All Registration Forms Overview List -->
                <div class="page-header-actions">
                    <div>
                        <h1>Structure &raquo; Registration Forms</h1>
                        <p style="color: #64748b; font-size: 0.88rem; margin-top: -12px;">
                            Manage event registration forms, configure hosts, and view attendee rosters.
                        </p>
                    </div>
                    <?php if (user_has_permission('create_registrations')): ?>
                        <a href="<?= base_path('admin/registrations.php?action=create') ?>" class="btn-primary">+ Create Registration Form</a>
                    <?php endif; ?>
                </div>

                <?php render_flash_messages(); ?>

                <section class="card">
                    <table>
                        <thead>
                            <tr>
                                <th>Form Title</th>
                                <th>Host Target</th>
                                <th>Capacity & Roster</th>
                                <th>Registration Status</th>
                                <th>Date Window</th>
                                <th style="text-align: right;">Operations</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($forms_list)): ?>
                                <tr><td colspan="6" style="text-align:center; color:#64748b; padding:25px;">No registration forms created yet. Click "+ Create Registration Form" above.</td></tr>
                            <?php else: ?>
                                <?php foreach ($forms_list as $f): ?>
                                    <?php 
                                        $cnt = get_form_attendee_counts((int)$f['id']);
                                        $cap = (int)$f['capacity'];
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><a href="<?= base_path('admin/registrations.php?form_id=' . (int)$f['id']) ?>" style="font-size:1.02rem;"><?= htmlspecialchars($f['title']) ?></a></strong>
                                            <?php if (!empty($f['description'])): ?>
                                                <div style="font-size:0.8rem; color:#64748b; margin-top:2px; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                    <?= htmlspecialchars($f['description']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($f['host_type'] === 'page'): ?>
                                                <span class="host-badge-page">Page</span>
                                                <div style="margin-top:3px;"><a href="<?= base_path($f['slug']) ?>" target="_blank" style="font-size:0.82rem; font-family:monospace;">/<?= htmlspecialchars($f['slug']) ?></a></div>
                                            <?php else: ?>
                                                <span class="host-badge-block">Block</span>
                                                <div style="margin-top:3px;"><a href="<?= base_path('admin/blocks.php') ?>" style="font-size:0.82rem;">Configure in Blocks &raquo;</a></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= $cnt['confirmed'] ?></strong> <?= $cap > 0 ? '/ ' . $cap : 'slots' ?>
                                            <?php if ($cnt['waitlist'] > 0): ?>
                                                <span style="color:#d97706; font-size:0.8rem;">(<?= $cnt['waitlist'] ?> Waitlist)</span>
                                            <?php endif; ?>
                                            <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">
                                                <a href="<?= base_path('admin/registrations.php?form_id=' . (int)$f['id']) ?>"><?= $cnt['total'] ?> submission(s)</a>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (user_has_permission('edit_registrations')): ?>
                                                <a href="<?= base_path('admin/registrations.php?action=toggle_form&id=' . (int)$f['id'] . '&csrf_token=' . get_csrf_token()) ?>" style="text-decoration:none;">
                                                    <span class="badge-status <?= $f['status'] ? 'status-published' : 'status-archived' ?>">
                                                        <?= $f['status'] ? 'Active / Open' : 'Disabled / Closed' ?>
                                                    </span>
                                                </a>
                                            <?php else: ?>
                                                <span class="badge-status <?= $f['status'] ? 'status-published' : 'status-archived' ?>">
                                                    <?= $f['status'] ? 'Active / Open' : 'Disabled / Closed' ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:0.82rem; color:#64748b;">
                                            <?php if (!empty($f['open_date']) || !empty($f['close_date'])): ?>
                                                <?= !empty($f['open_date']) ? date('M j, Y', strtotime($f['open_date'])) : 'Any' ?> &rarr; 
                                                <?= !empty($f['close_date']) ? date('M j, Y', strtotime($f['close_date'])) : 'Indefinite' ?>
                                            <?php else: ?>
                                                <em>Always Open</em>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <a href="<?= base_path('admin/registrations.php?form_id=' . (int)$f['id']) ?>" class="btn-primary" style="font-size:0.78rem; padding:4px 8px;">View Roster</a>
                                            <?php if (user_has_permission('edit_registrations')): ?>
                                                <a href="<?= base_path('admin/registrations.php?form_id=' . (int)$f['id'] . '&action=fields') ?>" class="btn-secondary" style="font-size:0.78rem; padding:4px 8px; margin-left:4px;">Fields</a>
                                                <a href="<?= base_path('admin/registrations.php?form_id=' . (int)$f['id'] . '&action=settings') ?>" class="btn-secondary" style="font-size:0.78rem; padding:4px 8px; margin-left:4px;">Settings</a>
                                            <?php endif; ?>
                                            <?php if (user_has_permission('delete_registrations')): ?>
                                                <a href="<?= base_path('admin/registrations.php?action=delete_form&id=' . (int)$f['id'] . '&csrf_token=' . get_csrf_token()) ?>" 
                                                   data-confirm="Permanently delete this entire registration form and all attendee data?"
                                                   data-confirm-title="Delete Registration Form"
                                                   data-confirm-btn="Delete"
                                                   style="color:#c00; font-size:0.8rem; margin-left:6px; text-decoration:none;">Delete</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>

        </main>
    </div>
</body>
</html>