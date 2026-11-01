<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/rules.php';
require_once __DIR__ . '/../includes/cache.php';

require_permission('administer_site');

$db = get_db();
$action = $_GET['action'] ?? 'list';
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 1. Status Toggle Action
if ($action === 'toggle' && $edit_id > 0) {
    validate_csrf();
    $stmt = $db->prepare('UPDATE custom_rules SET status = IF(status = 1, 0, 1) WHERE id = :id');
    $stmt->execute(['id' => $edit_id]);
    set_flash('success', 'Rule status updated.');
    header('Location: ' . base_path('admin/rules.php'));
    exit;
}

// 2. Delete Action
if ($action === 'delete' && $edit_id > 0) {
    validate_csrf();
    $stmt = $db->prepare('DELETE FROM custom_rules WHERE id = :id');
    $stmt->execute(['id' => $edit_id]);
    watchdog('rules', "Deleted rule ID #{$edit_id}.", 'notice');
    set_flash('success', 'Rule removed successfully.');
    header('Location: ' . base_path('admin/rules.php'));
    exit;
}

// 3. Save / Update Rule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rule'])) {
    validate_csrf();

    $id              = (int)($_POST['rule_id'] ?? 0);
    $label           = trim($_POST['label'] ?? '');
    $machine_name    = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['machine_name'] ?? '')));
    $event           = trim($_POST['event'] ?? 'registration_submit');
    $reg_type_filter = trim($_POST['registration_type_filter'] ?? 'all');
    $status          = isset($_POST['status']) ? 1 : 0;

    // Build conditions structure
    $conditions = [];
    if (str_starts_with($event, 'registration_') && in_array($reg_type_filter, ['tour', 'general'], true)) {
        $conditions[] = [
            'field'    => 'form_type',
            'operator' => 'equals',
            'value'    => $reg_type_filter
        ];
    }

    // Actions Payload
    $action_type = $_POST['action_type'] ?? 'send_email';
    $actions = [];

    if ($action_type === 'send_email') {
        $actions = [
            [
                'type'           => 'send_email',
                'to'             => trim($_POST['email_to'] ?? ''),
                'recipient_name' => trim($_POST['email_to_name'] ?? ''),
                'subject'        => trim($_POST['email_subject'] ?? ''),
                'email_template' => trim($_POST['email_template'] ?? 'frnp'),
                'cta_label'      => trim($_POST['email_cta_label'] ?? ''),
                'cta_url'        => trim($_POST['email_cta_url'] ?? ''),
                'message'        => trim($_POST['email_body'] ?? ''),
            ]
        ];
    } elseif ($action_type === 'watchdog') {
        $actions[] = [
            'type'     => 'watchdog',
            'severity' => $_POST['log_severity'] ?? 'notice',
            'message'  => trim($_POST['log_message'] ?? ''),
        ];
    }

    if ($label === '' || $machine_name === '') {
        set_flash('error', 'Rule Label and Machine Name are required.');
    } else {
        if ($id > 0) {
            $stmt = $db->prepare('
                UPDATE custom_rules 
                SET label = :label, event = :event, conditions = :conditions, actions = :actions, status = :status
                WHERE id = :id
            ');
            $stmt->execute([
                'label'      => $label,
                'event'      => $event,
                'conditions' => json_encode($conditions),
                'actions'    => json_encode($actions),
                'status'     => $status,
                'id'         => $id,
            ]);
            set_flash('success', "Rule '{$label}' updated successfully.");
        } else {
            $stmt = $db->prepare('
                INSERT INTO custom_rules (label, machine_name, event, conditions, actions, status)
                VALUES (:label, :machine_name, :event, :conditions, :actions, :status)
            ');
            $stmt->execute([
                'label'        => $label,
                'machine_name' => $machine_name,
                'event'        => $event,
                'conditions'   => json_encode($conditions),
                'actions'      => json_encode($actions),
                'status'       => $status,
            ]);
            set_flash('success', "Rule '{$label}' created successfully.");
        }

        header('Location: ' . base_path('admin/rules.php'));
        exit;
    }
}

$all_rules = rules_get_all_rules();
$available_events = rules_get_events();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Rules & Automations (ECA) - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    <style>
        .token-group-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 12px 14px;
            margin-bottom: 15px;
        }
        .token-group-title {
            font-size: 0.76rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .token-pill {
            display: inline-block;
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 2px 7px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.76rem;
            cursor: pointer;
            margin: 2px 2px;
            user-select: all;
            transition: all 0.12s ease;
        }
        .token-pill:hover { background: #e2e8f0; color: #0b2545; }
        .token-pill-reg { background: #eff6ff; color: #0369a1; border-color: #bfdbfe; }
        .token-pill-reg:hover { background: #dbeafe; }
        .token-pill-tour { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .token-pill-tour:hover { background: #fde68a; }
        .token-pill-node { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
        .token-pill-node:hover { background: #dcfce7; }
        .token-pill-user { background: #faf5ff; color: #6b21a8; border-color: #e9d5ff; }
        .token-pill-user:hover { background: #f3e8ff; }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(2px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .modal-dialog {
            background: #ffffff;
            width: 820px;
            max-width: 95vw;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 8px;
            padding: 24px 28px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        .type-badge {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 2px 6px;
            border-radius: 3px;
            letter-spacing: 0.3px;
        }
        .type-badge-tour { background: #0b2545; color: #ffffff; }
        .type-badge-general { background: #e2e8f0; color: #475569; }
        .type-badge-all { background: #e0f2fe; color: #0369a1; }
        .type-badge-na { color: #94a3b8; font-style: italic; }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>

        <main class="admin-main" style="max-width: 1300px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h1 style="margin: 0 0 4px 0;">Structure &raquo; Custom Rules & Automations</h1>
                    <p style="color: #64748b; font-size: 0.88rem; margin: 0;">
                        Configure event-driven automations across Content Nodes, User Accounts, and Registrations.
                    </p>
                </div>
                <div>
                    <button type="button" class="btn-primary" onclick="openAddRuleModal()">+ Add New Rule</button>
                </div>
            </div>

            <?php render_flash_messages(); ?>

            <section class="card">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th style="width: 26%;">Rule Label</th>
                            <th style="width: 22%;">Trigger Event</th>
                            <th style="width: 16%;">Scope / Filter</th>
                            <th style="width: 20%;">Action Target</th>
                            <th style="width: 8%; text-align: center;">Status</th>
                            <th style="width: 8%; text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_rules)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: #94a3b8; padding: 35px; font-style: italic;">
                                    No automation rules defined yet. Click "+ Add New Rule" to create one.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_rules as $r): ?>
                                <?php 
                                    $ev = $available_events[$r['event']] ?? ['label' => $r['event'], 'group' => 'General'];
                                    $actions = json_decode($r['actions'] ?? '[]', true) ?: [];
                                    $first_act = $actions[0] ?? ['type' => 'None'];
                                    $conditions = json_decode($r['conditions'] ?? '[]', true) ?: [];

                                    // Extract registration filter if present
                                    $reg_filter = 'all';
                                    $has_reg_filter = false;
                                    foreach ($conditions as $c) {
                                        if (($c['field'] ?? '') === 'form_type') {
                                            $reg_filter = $c['value'] ?? 'all';
                                            $has_reg_filter = true;
                                            break;
                                        }
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <strong style="color: #0b2545; font-size: 0.95rem;"><?= htmlspecialchars($r['label']) ?></strong>
                                        <div style="font-family: monospace; font-size: 0.78rem; color: #64748b;"><?= htmlspecialchars($r['machine_name']) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 600;">
                                            <?= htmlspecialchars($ev['label']) ?>
                                        </span>
                                        <div style="font-size: 0.76rem; color: #64748b; margin-top: 2px;">
                                            <?= htmlspecialchars($ev['group']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (str_starts_with($r['event'], 'registration_')): ?>
                                            <?php if ($reg_filter === 'tour'): ?>
                                                <span class="type-badge type-badge-tour">Tours Only</span>
                                            <?php elseif ($reg_filter === 'general'): ?>
                                                <span class="type-badge type-badge-general">General Only</span>
                                            <?php else: ?>
                                                <span class="type-badge type-badge-all">All Registrations</span>
                                            <?php endif; ?>
                                        <?php elseif (str_starts_with($r['event'], 'node_')): ?>
                                            <span class="type-badge" style="background: #ecfdf5; color: #166534;">All Nodes</span>
                                        <?php elseif (str_starts_with($r['event'], 'user_')): ?>
                                            <span class="type-badge" style="background: #faf5ff; color: #6b21a8;">All Users</span>
                                        <?php else: ?>
                                            <span class="type-badge-na">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($first_act['type'] === 'send_email'): ?>
                                            <div style="font-size: 0.84rem; color: #334155;">
                                                &#9993; Email: <code><?= htmlspecialchars(mb_strimwidth($first_act['to'] ?? '', 0, 28, '...')) ?></code>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size: 0.84rem; color: #64748b; text-transform: capitalize;"><?= htmlspecialchars($first_act['type']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <a href="<?= base_path('admin/rules.php?action=toggle&id=' . (int)$r['id'] . '&csrf_token=' . get_csrf_token()) ?>" style="text-decoration: none;">
                                            <span class="badge-status <?= $r['status'] ? 'status-published' : 'status-archived' ?>">
                                                <?= $r['status'] ? 'Active' : 'Disabled' ?>
                                            </span>
                                        </a>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <button type="button" class="btn-secondary" style="font-size: 0.8rem; padding: 4px 8px;" onclick='openAddRuleModal(<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                            Edit
                                        </button>
                                        <a href="<?= base_path('admin/rules.php?action=delete&id=' . (int)$r['id'] . '&csrf_token=' . get_csrf_token()) ?>" onclick="return confirm('Permanently delete this rule?');" style="color: #dc2626; font-size: 0.8rem; margin-left: 8px; text-decoration: none;">
                                            Delete
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>

    <!-- MODAL: ADD / EDIT RULE -->
    <div id="ruleModal" class="modal-overlay">
        <div class="modal-dialog">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 16px;">
                <h3 id="modalRuleTitle" style="margin: 0; color: #0b2545;">Configure Rule</h3>
                <button type="button" onclick="closeRuleModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;">&times;</button>
            </div>

            <form method="POST" action="<?= base_path('admin/rules.php') ?>">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="save_rule" value="1">
                <input type="hidden" name="rule_id" id="modal_rule_id" value="0">

                <div style="display: grid; grid-template-columns: 1.5fr 1.5fr; gap: 15px; margin-bottom: 15px;">
                    <div class="form-row">
                        <label>Rule Label *</label>
                        <input type="text" name="label" id="modal_label" required placeholder="e.g. Tour Registration Confirmation Email" onkeyup="autoMachineName(this.value)">
                    </div>
                    <div class="form-row">
                        <label>Machine Name *</label>
                        <input type="text" name="machine_name" id="modal_machine_name" required placeholder="tour_registration_confirmation">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div class="form-row">
                        <label>Event Trigger *</label>
                        <select name="event" id="modal_event" onchange="onEventTriggerChange(this.value)">
                            <?php 
                                $curr_group = '';
                                foreach ($available_events as $ek => $ed): 
                                    if ($curr_group !== ($ed['group'] ?? '')):
                                        if ($curr_group !== '') echo '</optgroup>';
                                        $curr_group = $ed['group'] ?? 'General';
                                        echo '<optgroup label="' . htmlspecialchars($curr_group) . '">';
                                    endif;
                            ?>
                                <option value="<?= htmlspecialchars($ek) ?>">
                                    <?= htmlspecialchars($ed['label']) ?>
                                </option>
                            <?php endforeach; echo '</optgroup>'; ?>
                        </select>
                    </div>

                    <!-- REGISTRATION TYPE FILTER: Only displayed when event is a registration trigger -->
                    <div class="form-row" id="row_reg_type_filter">
                        <label>Registration Type Target</label>
                        <select name="registration_type_filter" id="modal_reg_type_filter" onchange="onRegTypeFilterChange(this.value)">
                            <option value="all">All Registrations (General & Tours)</option>
                            <option value="tour">Site Tours Only (form_type = tour)</option>
                            <option value="general">General Events Only (form_type = general)</option>
                        </select>
                    </div>
                </div>

                <!-- Action Block -->
                <h4 style="color: #0b2545; margin: 15px 0 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">Action Configuration</h4>
                
                <div class="form-row" style="margin-bottom: 12px;">
                    <label>Action to Execute</label>
                    <select name="action_type" id="modal_action_type" onchange="onActionTypeChange(this.value)">
                        <option value="send_email">Send Email Notification</option>
                        <option value="watchdog">Log Entry to Watchdog</option>
                    </select>
                </div>

                <!-- Email Fields Container -->
                <div id="email_action_fields">
                    <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 12px; margin-bottom: 12px;">
                        <div class="form-row">
                            <label>Recipient Email Address *</label>
                            <input type="text" name="email_to" id="modal_email_to" value="[registration:email]" placeholder="e.g. [registration:email] or [user:email]">
                        </div>
                        <div class="form-row">
                            <label>Recipient Name</label>
                            <input type="text" name="email_to_name" id="modal_email_to_name" value="[registration:name]" placeholder="e.g. [registration:name] or [user:name]">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1.6fr 1fr; gap: 12px; margin-bottom: 12px;">
                        <div class="form-row">
                            <label>Email Subject *</label>
                            <input type="text" name="email_subject" id="modal_email_subject" value="Registration Confirmation">
                        </div>
                        <div class="form-row">
                            <label>Email Layout Template</label>
                            <select name="email_template" id="modal_email_template">
                                <option value="frnp" selected>FRNP Branded Template (Navy Header / Footer)</option>
                                <option value="generic">Generic Template (Neutral Card Wrapper)</option>
                                <option value="none">No Template (Raw HTML Body Only)</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                        <div class="form-row">
                            <label>Call-to-Action Button Label (Optional)</label>
                            <input type="text" name="email_cta_label" id="modal_email_cta_label" placeholder="e.g. View Event Details">
                        </div>
                        <div class="form-row">
                            <label>Call-to-Action Button URL (Optional)</label>
                            <input type="text" name="email_cta_url" id="modal_email_cta_url" placeholder="e.g. [form:url] or https://...">
                        </div>
                    </div>

                    <div class="form-row" style="margin-bottom: 8px;">
                        <label>Message Content (HTML Allowed)</label>
                        <textarea name="email_body" id="modal_email_body" rows="7" placeholder="Email message content..."></textarea>
                    </div>

                    <!-- Available Replacement Tokens Palette -->
                    <div class="token-group-box">
                        <div style="font-size: 0.82rem; font-weight: 700; color: #0b2545; margin-bottom: 8px;">Available Replacement Tokens (Click to insert):</div>
                        
                        <!-- Registration Tokens -->
                        <div id="token_sec_registration" style="margin-bottom: 8px;">
                            <div class="token-group-title" style="color: #0369a1;">Registration Tokens:</div>
                            <span class="token-pill token-pill-reg" onclick="insertToken('[registration:name]')">[registration:name]</span>
                            <span class="token-pill token-pill-reg" onclick="insertToken('[registration:email]')">[registration:email]</span>
                            <span class="token-pill token-pill-reg" onclick="insertToken('[registration:phone]')">[registration:phone]</span>
                            <span class="token-pill token-pill-reg" onclick="insertToken('[registration:confirmation_number]')">[registration:confirmation_number]</span>
                            <span class="token-pill token-pill-reg" onclick="insertToken('[registration:summary]')">[registration:summary]</span>
                            <span class="token-pill token-pill-reg" onclick="insertToken('[form:title]')">[form:title]</span>
                            <span class="token-pill token-pill-reg" onclick="insertToken('[form:url]')">[form:url]</span>
                        </div>

                        <!-- Tour Specific Tokens -->
                        <div id="token_sec_tour" style="margin-bottom: 8px;">
                            <div class="token-group-title" style="color: #92400e;">Tour Specific Tokens:</div>
                            <span class="token-pill token-pill-tour" onclick="insertToken('[tour:date]')">[tour:date]</span>
                            <span class="token-pill token-pill-tour" onclick="insertToken('[tour:date_formatted]')">[tour:date_formatted]</span>
                            <span class="token-pill token-pill-tour" onclick="insertToken('[tour:time]')">[tour:time]</span>
                            <span class="token-pill token-pill-tour" onclick="insertToken('[registration:dl_state]')">[registration:dl_state]</span>
                        </div>

                        <!-- Node Tokens -->
                        <div id="token_sec_node" style="margin-bottom: 8px;">
                            <div class="token-group-title" style="color: #166534;">Content (Node) Tokens:</div>
                            <span class="token-pill token-pill-node" onclick="insertToken('[node:id]')">[node:id]</span>
                            <span class="token-pill token-pill-node" onclick="insertToken('[node:title]')">[node:title]</span>
                            <span class="token-pill token-pill-node" onclick="insertToken('[node:type]')">[node:type]</span>
                            <span class="token-pill token-pill-node" onclick="insertToken('[node:url]')">[node:url]</span>
                            <span class="token-pill token-pill-node" onclick="insertToken('[node:author]')">[node:author]</span>
                            <span class="token-pill token-pill-node" onclick="insertToken('[node:created]')">[node:created]</span>
                            <span class="token-pill token-pill-node" onclick="insertToken('[node:summary]')">[node:summary]</span>
                        </div>

                        <!-- User Tokens -->
                        <div id="token_sec_user" style="margin-bottom: 8px;">
                            <div class="token-group-title" style="color: #6b21a8;">User Account Tokens:</div>
                            <span class="token-pill token-pill-user" onclick="insertToken('[user:id]')">[user:id]</span>
                            <span class="token-pill token-pill-user" onclick="insertToken('[user:name]')">[user:name]</span>
                            <span class="token-pill token-pill-user" onclick="insertToken('[user:email]')">[user:email]</span>
                            <span class="token-pill token-pill-user" onclick="insertToken('[user:roles]')">[user:roles]</span>
                        </div>

                        <!-- Site Tokens -->
                        <div id="token_sec_site">
                            <div class="token-group-title" style="color: #475569;">Global Tokens:</div>
                            <span class="token-pill" onclick="insertToken('[site:name]')">[site:name]</span>
                            <span class="token-pill" onclick="insertToken('[site:url]')">[site:url]</span>
                        </div>
                    </div>
                </div>

                <!-- Watchdog Log Fields Container -->
                <div id="watchdog_action_fields" style="display: none;">
                    <div class="form-row" style="margin-bottom: 12px;">
                        <label>Log Message *</label>
                        <input type="text" name="log_message" id="modal_log_message" placeholder="Automated event executed for [user:name]">
                    </div>
                    <div class="form-row" style="margin-bottom: 15px;">
                        <label>Severity</label>
                        <select name="log_severity" id="modal_log_severity">
                            <option value="notice">Notice</option>
                            <option value="info">Info</option>
                            <option value="warning">Warning</option>
                        </select>
                    </div>
                </div>

                <div class="form-row" style="margin-top: 15px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; color: #0b2545;">
                        <input type="checkbox" name="status" id="modal_status" value="1" checked>
                        <span>Active & Enabled</span>
                    </label>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px; border-top: 1px solid #e2e8f0; padding-top: 14px;">
                    <button type="button" class="btn-secondary" onclick="closeRuleModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Save Rule</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddRuleModal(data = null) {
            const isEdit = !!(data && data.id);
            document.getElementById('modalRuleTitle').innerText = isEdit ? 'Edit Rule: ' + (data.label || '') : 'Create New Rule';
            document.getElementById('modal_rule_id').value = isEdit ? data.id : 0;
            document.getElementById('modal_label').value = isEdit ? (data.label || '') : '';
            
            const machineInput = document.getElementById('modal_machine_name');
            machineInput.value = isEdit ? (data.machine_name || '') : '';
            machineInput.readOnly = isEdit;

            const eventSelect = document.getElementById('modal_event');
            eventSelect.value = isEdit ? (data.event || 'registration_submit') : 'registration_submit';

            // Resolve registration type condition if applicable
            let regType = 'all';
            if (isEdit && data.conditions) {
                const conds = typeof data.conditions === 'string' ? JSON.parse(data.conditions) : data.conditions;
                if (Array.isArray(conds)) {
                    for (let c of conds) {
                        if (c.field === 'form_type') {
                            regType = c.value || 'all';
                            break;
                        }
                    }
                }
            }
            document.getElementById('modal_reg_type_filter').value = regType;

            document.getElementById('modal_status').checked = !isEdit || parseInt(data.status, 10) === 1;

            // Load Actions
            if (isEdit && data.actions) {
                const actions = typeof data.actions === 'string' ? JSON.parse(data.actions) : data.actions;
                const act = actions[0] || {};
                const actType = act.type || 'send_email';
                document.getElementById('modal_action_type').value = actType;
                onActionTypeChange(actType);

                if (actType === 'send_email') {
                    document.getElementById('modal_email_to').value = act.to || '';
                    document.getElementById('modal_email_to_name').value = act.recipient_name || '';
                    document.getElementById('modal_email_subject').value = act.subject || '';
                    document.getElementById('modal_email_template').value = act.email_template || 'frnp';
                    document.getElementById('modal_email_cta_label').value = act.cta_label || '';
                    document.getElementById('modal_email_cta_url').value = act.cta_url || '';
                    document.getElementById('modal_email_body').value = act.message || '';
                } else if (actType === 'watchdog') {
                    document.getElementById('modal_log_message').value = act.message || '';
                    document.getElementById('modal_log_severity').value = act.severity || 'notice';
                    document.getElementById('modal_email_template').value = 'frnp';
                    document.getElementById('modal_email_cta_label').value = '';
                    document.getElementById('modal_email_cta_url').value = '';
                }
            } else {
                document.getElementById('modal_action_type').value = 'send_email';
                onActionTypeChange('send_email');
                document.getElementById('modal_email_to').value = '[registration:email]';
                document.getElementById('modal_email_to_name').value = '[registration:name]';
                document.getElementById('modal_email_subject').value = 'Registration Confirmation';
                document.getElementById('modal_email_template').value = 'frnp';
                document.getElementById('modal_email_cta_label').value = '';
                document.getElementById('modal_email_cta_url').value = '';
                document.getElementById('modal_email_body').value = '<p>Dear [registration:name],</p>\n<p>Thank you for submitting your registration. Here are your details:</p>\n[registration:summary]';
            }

            onEventTriggerChange(eventSelect.value);
            document.getElementById('ruleModal').style.display = 'flex';
        }

        const openRuleModal = openAddRuleModal;

        function closeRuleModal() {
            document.getElementById('ruleModal').style.display = 'none';
        }

        function autoMachineName(val) {
            const input = document.getElementById('modal_machine_name');
            if (input.readOnly) return;
            input.value = val.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
        }

        function onActionTypeChange(type) {
            document.getElementById('email_action_fields').style.display = (type === 'send_email') ? 'block' : 'none';
            document.getElementById('watchdog_action_fields').style.display = (type === 'watchdog') ? 'block' : 'none';
        }

        function onEventTriggerChange(eventVal) {
            const isReg = eventVal.startsWith('registration_');
            const isNode = eventVal.startsWith('node_');
            const isUser = eventVal.startsWith('user_');

            // Show Registration Type filter only for registrations
            document.getElementById('row_reg_type_filter').style.display = isReg ? 'block' : 'none';

            // Contextual token groups
            document.getElementById('token_sec_registration').style.display = isReg ? 'block' : 'none';
            document.getElementById('token_sec_tour').style.display = isReg ? 'block' : 'none';
            document.getElementById('token_sec_node').style.display = (isNode || isReg) ? 'block' : 'none';
            document.getElementById('token_sec_user').style.display = (isUser || isNode || isReg) ? 'block' : 'none';

            if (isReg) {
                onRegTypeFilterChange(document.getElementById('modal_reg_type_filter').value);
            }
        }

        function onRegTypeFilterChange(type) {
            const tourTokens = document.getElementById('token_sec_tour');
            if (tourTokens) {
                tourTokens.style.display = (type === 'general') ? 'none' : 'block';
            }
        }

        function insertToken(token) {
            const textarea = document.getElementById('modal_email_body');
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const text = textarea.value;
            textarea.value = text.substring(0, start) + token + text.substring(end);
            textarea.focus();
            textarea.selectionStart = textarea.selectionEnd = start + token.length;
        }

        window.onclick = function(e) {
            const modal = document.getElementById('ruleModal');
            if (e.target === modal) {
                closeRuleModal();
            }
        };
    </script>
</body>
</html>
