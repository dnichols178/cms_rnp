<?php declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/rules.php';

require_permission('administer_site');

$db = get_db();
$message = '';
$error = '';

// 1. Action: Delete Rule
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    validate_csrf();
    $stmt = $db->prepare('DELETE FROM custom_rules WHERE id = :id');
    $stmt->execute(['id' => (int)$_GET['id']]);
    watchdog('rules', "Deleted rule ID: " . (int)$_GET['id'], 'notice');
    header('Location: ' . base_path('admin/rules.php?msg=deleted'));
    exit;
}

// 2. Action: Toggle Active Status
if (isset($_GET['action']) && $_GET['action'] === 'toggle' && isset($_GET['id'])) {
    validate_csrf();
    $stmt = $db->prepare('UPDATE custom_rules SET status = IF(status = 1, 0, 1) WHERE id = :id');
    $stmt->execute(['id' => (int)$_GET['id']]);
    header('Location: ' . base_path('admin/rules.php'));
    exit;
}

// 3. Action: Add or Edit Rule Modal Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_rule') {
    validate_csrf();
    $is_edit      = ($_POST['modal_mode'] ?? '') === 'edit';
    $rule_id      = (int)($_POST['rule_id'] ?? 0);
    $label        = trim($_POST['label'] ?? '');
    $machine_name = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['machine_name'] ?? '')));
    $event        = trim($_POST['event'] ?? 'node_publish');

    // Conditions Payload
    $conditions = [
        'content_type' => trim($_POST['condition_type'] ?? 'all'),
        'node_status'  => trim($_POST['condition_status'] ?? 'all'),
    ];

    // Actions: HTML Email Action
    $recipient = trim($_POST['action_email_to'] ?? '');
    $subject   = trim($_POST['action_email_subject'] ?? '');
    $body_html = trim($_POST['action_email_body'] ?? '');
    $cta_label = trim($_POST['action_email_cta_label'] ?? '');
    $cta_url   = trim($_POST['action_email_cta_url'] ?? '');

    $actions = [];
    if ($recipient !== '' && $subject !== '') {
        $actions[] = [
            'type'      => 'send_email',
            'recipient' => $recipient,
            'subject'   => $subject,
            'body_html' => $body_html,
            'cta_label' => $cta_label,
            'cta_url'   => $cta_url
        ];
    }

    if ($label === '') {
        $error = 'Rule Label is required.';
    } elseif ($machine_name === '') {
        $error = 'Machine name is required.';
    } elseif (empty($actions)) {
        $error = 'Please configure at least one Action (e.g. Email Notification).';
    } else {
        if ($is_edit && $rule_id > 0) {
            $stmt = $db->prepare('
                UPDATE custom_rules 
                SET label = ?, event = ?, conditions = ?, actions = ? 
                WHERE id = ?
            ');
            $stmt->execute([$label, $event, json_encode($conditions), json_encode($actions), $rule_id]);
            $message = "Rule '{$label}' updated successfully.";
        } else {
            $stmt = $db->prepare('
                INSERT INTO custom_rules (label, machine_name, event, conditions, actions, status)
                VALUES (?, ?, ?, ?, ?, 1)
            ');
            try {
                $stmt->execute([$label, $machine_name, $event, json_encode($conditions), json_encode($actions)]);
                $message = "Rule '{$label}' created and active.";
            } catch (\PDOException $e) {
                $error = "A rule with the machine identifier '{$machine_name}' already exists.";
            }
        }
    }
}

$rules = $db->query('SELECT * FROM custom_rules ORDER BY id DESC')->fetchAll();
$event_dict = rules_get_events();
$types = get_content_types();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Rules & Workflow Automation - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    <style>
        .page-header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .event-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 700;
            background: #e0f2fe;
            color: #0369a1;
            text-transform: uppercase;
        }
        .rule-card-desc {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 4px;
        }
        .token-btn {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            padding: 2px 6px;
            font-family: var(--font-mono);
            font-size: 0.74rem;
            color: #0b2545;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .token-btn:hover {
            background: #0b2545;
            color: #fff;
            border-color: #0b2545;
        }
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11, 37, 69, 0.55);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            backdrop-filter: blur(2px);
        }
        .modal-overlay.active { display: flex; }
        .modal-card {
            background: #ffffff;
            width: 100%;
            max-width: 680px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-strong);
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.25);
            overflow: hidden;
            animation: modalFadeIn 0.2s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
        }
        .modal-body {
            padding: 20px;
            max-height: 75vh;
            overflow-y: auto;
        }
        .modal-footer {
            padding: 14px 20px;
            background: #f8fafc;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .fieldset-box {
            background: #fafbfc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 16px;
            margin-bottom: 16px;
        }
        .fieldset-box h4 {
            font-size: 0.88rem;
            color: #0b2545;
            margin-bottom: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <?php require __DIR__ . '/nav.php'; ?>
        <main class="admin-main">
            <div class="page-header-actions">
                <div>
                    <h1>Communications &raquo; Rules & Events Automation</h1>
                    <p style="color: #64748b; font-size: 0.88rem; margin-top: -12px;">
                        Configure event-driven actions, automated workflows, and tokenized email alerts.
                    </p>
                </div>
                <button type="button" class="btn-primary" onclick="openAddRuleModal()">+ Add Rule</button>
            </div>

            <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?><div class="notice">Rule removed.</div><?php endif; ?>
            <?php if ($error): ?><div class="notice" style="background:#fee; border-color:#c00; color:#c00;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <section class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Rule Name</th>
                            <th>Triggering Event</th>
                            <th>Condition Constraints</th>
                            <th>Actions Configured</th>
                            <th>Status</th>
                            <th style="text-align: right;">Operations</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rules)): ?>
                            <tr><td colspan="6" style="text-align:center; color:#64748b; padding:25px;">No automated rules defined. Click "+ Add Rule" above to create one.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rules as $r): ?>
                                <?php
                                    $cond = json_decode($r['conditions'] ?? '[]', true) ?: [];
                                    $acts = json_decode($r['actions'] ?? '[]', true) ?: [];
                                    $event_label = $event_dict[$r['event']] ?? $r['event'];
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($r['label']) ?></strong>
                                        <div class="rule-card-desc">Machine Name: <code><?= htmlspecialchars($r['machine_name']) ?></code></div>
                                    </td>
                                    <td>
                                        <span class="event-badge"><?= htmlspecialchars($r['event']) ?></span>
                                        <div style="font-size:0.75rem; color:#64748b; margin-top:2px;"><?= htmlspecialchars($event_label) ?></div>
                                    </td>
                                    <td style="font-size:0.84rem;">
                                        <?php if (!empty($cond['content_type']) && $cond['content_type'] !== 'all'): ?>
                                            <div>Type: <strong><?= htmlspecialchars($cond['content_type']) ?></strong></div>
                                        <?php endif; ?>
                                        <?php if (!empty($cond['node_status']) && $cond['node_status'] !== 'all'): ?>
                                            <div>Status: <strong><?= htmlspecialchars($cond['node_status']) ?></strong></div>
                                        <?php endif; ?>
                                        <?php if (empty($cond['content_type']) || $cond['content_type'] === 'all'): ?>
                                            <em>Always (Unrestricted)</em>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:0.84rem;">
                                        <?php foreach ($acts as $a): ?>
                                            <?php if ($a['type'] === 'send_email'): ?>
                                                <div>&#9993; Send HTML Email &rarr; <code><?= htmlspecialchars($a['recipient']) ?></code></div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </td>
                                    <td>
                                        <a href="<?= base_path('admin/rules.php?action=toggle&id=' . (int)$r['id'] . '&csrf_token=' . get_csrf_token()) ?>" style="text-decoration:none;">
                                            <span class="badge-status <?= $r['status'] ? 'status-published' : 'status-draft' ?>">
                                                <?= $r['status'] ? 'Enabled' : 'Disabled' ?>
                                            </span>
                                        </a>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <button type="button" class="btn-secondary" style="font-size:0.8rem; padding:4px 8px;" onclick='openEditRuleModal(<?= json_encode($r) ?>)'>Edit</button>
                                        <a href="<?= base_path('admin/rules.php?action=delete&id=' . (int)$r['id'] . '&csrf_token=' . get_csrf_token()) ?>" 
                                           onclick="return confirm('Permanently delete this automated rule?');" 
                                           style="color:#c00; font-size:0.8rem; margin-left:8px; text-decoration:none;">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>

    <!-- Add/Edit Rule Modal Dialog -->
    <div id="ruleModalOverlay" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modalRuleTitle">Configure Automation Rule</h3>
                <button type="button" class="modal-close-btn" onclick="closeRuleModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="modal_mode" id="rule_modal_mode" value="add">
                <input type="hidden" name="rule_id" id="rule_id" value="0">

                <div class="modal-body">
                    <!-- 1. Metadata -->
                    <div style="display:grid; grid-template-columns: 1.2fr 1fr; gap:14px;">
                        <div class="form-row">
                            <label>Rule Label *</label>
                            <input type="text" name="label" id="rule_label" required placeholder="e.g. Notify Site Manager on New Press Release" onkeyup="autoRuleMachineName(this.value)">
                        </div>
                        <div class="form-row">
                            <label>Machine Name *</label>
                            <input type="text" name="machine_name" id="rule_machine_name" required placeholder="notify_site_mgr_press_release">
                        </div>
                    </div>

                    <!-- 2. Event Selection -->
                    <div class="form-row">
                        <label>Triggering Event *</label>
                        <select name="event" id="rule_event" onchange="handleEventChange(this.value)">
                            <?php foreach ($event_dict as $ev_key => $ev_title): ?>
                                <option value="<?= htmlspecialchars($ev_key) ?>"><?= htmlspecialchars($ev_title) ?> (<code><?= htmlspecialchars($ev_key) ?></code>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 3. Condition Filters -->
                    <div class="fieldset-box" id="node_conditions_box">
                        <h4>Condition Criteria</h4>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
                            <div class="form-row">
                                <label>Restrict to Content Type</label>
                                <select name="condition_type" id="rule_condition_type">
                                    <option value="all">Any Content Type</option>
                                    <?php foreach ($types as $t): ?>
                                        <option value="<?= htmlspecialchars($t['type']) ?>"><?= htmlspecialchars($t['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-row">
                                <label>Restrict to Status</label>
                                <select name="condition_status" id="rule_condition_status">
                                    <option value="all">Any Status</option>
                                    <option value="published">Published</option>
                                    <option value="draft">Draft</option>
                                    <option value="in_review">In Review</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Action: HTML Email -->
                    <div class="fieldset-box">
                        <h4>Action: Send HTML Email</h4>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
                            <div class="form-row">
                                <label>Recipient Email Address *</label>
                                <input type="text" name="action_email_to" id="rule_action_email_to" required placeholder="manager@domain.com or [node:author:email]">
                            </div>
                            <div class="form-row">
                                <label>Email Subject *</label>
                                <input type="text" name="action_email_subject" id="rule_action_email_subject" required placeholder="Alert: [node:title] published">
                            </div>
                        </div>

                        <div class="form-row">
                            <label>HTML Message Body *</label>
                            <textarea name="action_email_body" id="rule_action_email_body" rows="4" required placeholder="<p>Hello,</p><p>A new item <strong>[node:title]</strong> has been published by [node:author].</p>"></textarea>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
                            <div class="form-row">
                                <label>Call to Action Label (Optional)</label>
                                <input type="text" name="action_email_cta_label" id="rule_action_email_cta_label" placeholder="View Live Article">
                            </div>
                            <div class="form-row">
                                <label>Call to Action URL (Optional)</label>
                                <input type="text" name="action_email_cta_url" id="rule_action_email_cta_url" placeholder="[node:url]">
                            </div>
                        </div>

                        <!-- Clickable Token Insert Helpers -->
                        <div style="margin-top: 10px; padding: 10px 12px; background: #ffffff; border: 1px dashed var(--border-strong); border-radius: var(--radius-sm);">
                            <div style="font-size: 0.72rem; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 6px;">
                                Insert Context Tokens:
                            </div>
                            <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                <button type="button" class="token-btn" onclick="insertTokenToEmail('[node:title]')">[node:title]</button>
                                <button type="button" class="token-btn" onclick="insertTokenToEmail('[node:url]')">[node:url]</button>
                                <button type="button" class="token-btn" onclick="insertTokenToEmail('[node:author]')">[node:author]</button>
                                <button type="button" class="token-btn" onclick="insertTokenToEmail('[node:author:email]')">[node:author:email]</button>
                                <button type="button" class="token-btn" onclick="insertTokenToEmail('[contact:name]')">[contact:name]</button>
                                <button type="button" class="token-btn" onclick="insertTokenToEmail('[contact:email]')">[contact:email]</button>
                                <button type="button" class="token-btn" onclick="insertTokenToEmail('[contact:subject]')">[contact:subject]</button>
                                <button type="button" class="token-btn" onclick="insertTokenToEmail('[contact:message]')">[contact:message]</button>
                                <button type="button" class="token-btn" onclick="insertTokenToEmail('[site:name]')">[site:name]</button>
                                <button type="button" class="token-btn" onclick="insertTokenToEmail('[site:url]')">[site:url]</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeRuleModal()">Cancel</button>
                    <button type="submit" class="btn-primary" id="ruleSubmitBtn">Save Automation Rule</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddRuleModal() {
            document.getElementById('modalRuleTitle').innerText = 'Create Automation Rule';
            document.getElementById('rule_modal_mode').value = 'add';
            document.getElementById('rule_id').value = '0';
            document.getElementById('ruleSubmitBtn').innerText = 'Create Rule';

            document.getElementById('rule_label').value = '';
            document.getElementById('rule_machine_name').value = '';
            document.getElementById('rule_machine_name').readOnly = false;
            document.getElementById('rule_event').value = 'node_publish';

            document.getElementById('rule_condition_type').value = 'all';
            document.getElementById('rule_condition_status').value = 'all';

            document.getElementById('rule_action_email_to').value = '';
            document.getElementById('rule_action_email_subject').value = '';
            document.getElementById('rule_action_email_body').value = '';
            document.getElementById('rule_action_email_cta_label').value = '';
            document.getElementById('rule_action_email_cta_url').value = '';

            handleEventChange('node_publish');
            document.getElementById('ruleModalOverlay').classList.add('active');
            document.getElementById('rule_label').focus();
        }

        function openEditRuleModal(rule) {
            document.getElementById('modalRuleTitle').innerText = 'Edit Rule: ' + rule.label;
            document.getElementById('rule_modal_mode').value = 'edit';
            document.getElementById('rule_id').value = rule.id;
            document.getElementById('ruleSubmitBtn').innerText = 'Update Rule';

            document.getElementById('rule_label').value = rule.label;
            document.getElementById('rule_machine_name').value = rule.machine_name;
            document.getElementById('rule_machine_name').readOnly = true;
            document.getElementById('rule_event').value = rule.event;

            const cond = JSON.parse(rule.conditions || '{}');
            document.getElementById('rule_condition_type').value = cond.content_type || 'all';
            document.getElementById('rule_condition_status').value = cond.node_status || 'all';

            const acts = JSON.parse(rule.actions || '[]');
            const emailAct = acts.find(a => a.type === 'send_email') || {};
            document.getElementById('rule_action_email_to').value = emailAct.recipient || '';
            document.getElementById('rule_action_email_subject').value = emailAct.subject || '';
            document.getElementById('rule_action_email_body').value = emailAct.body_html || '';
            document.getElementById('rule_action_email_cta_label').value = emailAct.cta_label || '';
            document.getElementById('rule_action_email_cta_url').value = emailAct.cta_url || '';

            handleEventChange(rule.event);
            document.getElementById('ruleModalOverlay').classList.add('active');
        }

        function closeRuleModal() {
            document.getElementById('ruleModalOverlay').classList.remove('active');
        }

        function autoRuleMachineName(val) {
            if (document.getElementById('rule_modal_mode').value === 'edit') return;
            const clean = val.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
            document.getElementById('rule_machine_name').value = clean;
        }

        function handleEventChange(ev) {
            const isNode = ev.startsWith('node_');
            document.getElementById('node_conditions_box').style.display = isNode ? 'block' : 'none';
        }

        function insertTokenToEmail(token) {
            const textarea = document.getElementById('rule_action_email_body');
            const start = textarea.selectionStart || textarea.value.length;
            const end = textarea.selectionEnd || textarea.value.length;
            const val = textarea.value;
            textarea.value = val.substring(0, start) + token + val.substring(end);
            textarea.focus();
            textarea.selectionStart = textarea.selectionEnd = start + token.length;
        }
    </script>
</body>
</html>