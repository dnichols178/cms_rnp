<?php declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rules.php';

// Auto-heal schema if columns are missing from earlier migrations
try {
    $db_init = get_db();
    $has_form_id = $db_init->query("SHOW COLUMNS FROM registrations LIKE 'form_id'")->fetch();
    if (!$has_form_id) {
        $db_init->exec("ALTER TABLE registrations ADD COLUMN form_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER id, ADD INDEX (form_id)");
    }
    $has_sub_data = $db_init->query("SHOW COLUMNS FROM registrations LIKE 'submission_data'")->fetch();
    if (!$has_sub_data) {
        $db_init->exec("ALTER TABLE registrations ADD COLUMN submission_data JSON NULL AFTER state");
    }
} catch (\Throwable $e) {}

/**
 * Fetch a single registration form by ID.
 */
function get_registration_form_by_id(int $id): ?array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM registration_forms WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($form) {
        $form['fields_schema'] = json_decode($form['fields_schema'] ?? '[]', true) ?: [];
    }
    return $form ?: null;
}

/**
 * Fetch a registration form by its page URL slug.
 */
function get_registration_form_by_slug(string $slug): ?array {
    $db = get_db();
    $clean_slug = trim($slug, '/');
    $stmt = $db->prepare("SELECT * FROM registration_forms WHERE host_type = 'page' AND slug = :slug LIMIT 1");
    $stmt->execute(['slug' => $clean_slug]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($form) {
        $form['fields_schema'] = json_decode($form['fields_schema'] ?? '[]', true) ?: [];
    }
    return $form ?: null;
}

/**
 * Calculate confirmed, waitlisted, and total attendees for a form.
 */
function get_form_attendee_counts(int $form_id): array {
    $db = get_db();
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN state = 'complete' THEN slots ELSE 0 END), 0) AS confirmed_slots,
            COALESCE(SUM(CASE WHEN state = 'waitlist' THEN slots ELSE 0 END), 0) AS waitlist_slots,
            COUNT(*) AS total_submissions
        FROM registrations
        WHERE form_id = :fid
    ");
    $stmt->execute(['fid' => $form_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'confirmed' => (int)($row['confirmed_slots'] ?? 0),
        'waitlist'  => (int)($row['waitlist_slots'] ?? 0),
        'total'     => (int)($row['total_submissions'] ?? 0)
    ];
}

/**
 * Processes attendee submission, validates custom inputs & checkboxes, and triggers Rules.
 */
function process_form_submission(int $form_id, array $post_data): array {
    $form = get_registration_form_by_id($form_id);
    if (!$form) {
        return ['success' => false, 'error' => 'Registration form not found.'];
    }

    if (empty($form['status'])) {
        return ['success' => false, 'error' => 'This registration form is currently closed or inactive.'];
    }

    $now = time();
    if (!empty($form['open_date']) && $now < strtotime($form['open_date'])) {
        return ['success' => false, 'error' => 'Registration has not opened yet.'];
    }
    if (!empty($form['close_date']) && $now > strtotime($form['close_date'])) {
        return ['success' => false, 'error' => 'Registration for this event has closed.'];
    }

    $email = trim($post_data['reg_email'] ?? '');
    $slots = 1; // Slots is always 1 and not exposed to users
    $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please provide a valid email address.'];
    }

    // Process custom fields from fields_schema
    $custom_values = [];
    $name = '';
    $phone = '';
    $org = '';
    
    $schema = $form['fields_schema'] ?? [];
    foreach ($schema as $f) {
        $fname = $f['name'];
        $ftype = $f['type'] ?? 'text';
        $val = $post_data['custom_' . $fname] ?? null;

        if ($ftype === 'checkbox') {
            $is_checked = !empty($val);
            if (!empty($f['required']) && !$is_checked) {
                return ['success' => false, 'error' => "You must check '{$f['label']}' to proceed."];
            }
            $custom_values[$fname] = $is_checked ? 'Yes' : 'No';
        } elseif ($ftype === 'checkbox_group') {
            $checked_vals = is_array($val) ? array_map('trim', $val) : [];
            if (!empty($f['required']) && empty($checked_vals)) {
                return ['success' => false, 'error' => "Please select at least one option for '{$f['label']}'."];
            }
            $custom_values[$fname] = $checked_vals;
        } else {
            $clean_val = is_array($val) ? $val : trim((string)$val);
            if (!empty($f['required']) && ($val === null || (is_string($val) && $clean_val === ''))) {
                return ['success' => false, 'error' => "Field '{$f['label']}' is required."];
            }
            $custom_values[$fname] = $clean_val;

            // Map standard legacy identifiers if present in custom schema
            if (stripos($f['label'], 'name') !== false && empty($name)) {
                $name = (string)$clean_val;
            }
            if ($ftype === 'phone' || stripos($f['label'], 'phone') !== false) {
                $phone = (string)$clean_val;
            }
            if (stripos($f['label'], 'organization') !== false || stripos($f['label'], 'department') !== false) {
                $org = (string)$clean_val;
            }
        }
    }

    $counts = get_form_attendee_counts($form_id);
    $capacity = (int)$form['capacity'];
    $state = 'complete';

    if ($capacity > 0 && ($counts['confirmed'] + $slots) > $capacity) {
        if (!empty($form['allow_waitlist'])) {
            $state = 'waitlist';
        } else {
            return ['success' => false, 'error' => 'Registration is full. No additional slots are available.'];
        }
    }

    $uid = (function_exists('current_user') && current_user()) ? (int)current_user()['id'] : null;

    $db = get_db();
    $stmt = $db->prepare('
        INSERT INTO registrations (form_id, user_id, name, email, phone, organization, slots, state, submission_data, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $form_id,
        $uid,
        $name,
        $email,
        $phone,
        $org,
        $slots,
        $state,
        json_encode($custom_values),
        $ip
    ]);
    $reg_id = (int)$db->lastInsertId();

    // Trigger Rules Event Automation
    if (file_exists(__DIR__ . '/rules.php')) {
        $context = [
            'registration' => [
                'id'           => $reg_id,
                'form_id'      => $form_id,
                'form_title'   => $form['title'],
                'form_url'     => ($form['host_type'] === 'page') ? base_path($form['slug']) : '',
                'name'         => $name,
                'email'        => $email,
                'phone'        => $phone,
                'organization' => $org,
                'slots'        => $slots,
                'state'        => $state,
                'data'         => $custom_values
            ]
        ];
        rules_invoke_event('registration_create', $context);
        if ($state === 'waitlist') {
            rules_invoke_event('registration_waitlist', $context);
        }
    }

    watchdog('registration', "Registration #{$reg_id} for '{$form['title']}' recorded [State: {$state}]", 'notice');

    return [
        'success' => true,
        'state'   => $state,
        'reg_id'  => $reg_id
    ];
}

/**
 * Render production registration form with FRNP typography and styling.
 */
function render_registration_form_view(array $form, bool $is_standalone_page = false): void {
    $form_id = (int)$form['id'];
    $counts = get_form_attendee_counts($form_id);
    $capacity = (int)$form['capacity'];
    $is_full = ($capacity > 0 && $counts['confirmed'] >= $capacity);
    $waitlist_allowed = !empty($form['allow_waitlist']);
    $schema = $form['fields_schema'] ?? [];

    $status_msg = '';
    $status_type = 'success';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_reg_' . $form_id) {
        validate_csrf();
        $res = process_form_submission($form_id, $_POST);
        if ($res['success']) {
            $is_wait = ($res['state'] === 'waitlist');
            $status_type = $is_wait ? 'waitlist' : 'success';
            $default_conf = $is_wait
                ? "You have been placed on the <strong>Waitlist</strong>. We will contact you if a slot opens."
                : "Your registration has been confirmed. Thank you for signing up.";
            $status_msg = !empty($form['confirmation_message']) ? nl2br(htmlspecialchars($form['confirmation_message'])) : $default_conf;

            $counts = get_form_attendee_counts($form_id);
            $is_full = ($capacity > 0 && $counts['confirmed'] >= $capacity);
        } else {
            $status_type = 'error';
            $status_msg = htmlspecialchars($res['error']);
        }
    }

    $pct_filled = ($capacity > 0) ? min(100, round(($counts['confirmed'] / $capacity) * 100)) : 0;
    ?>
    <style>
        .frnp-reg-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-top: 4px solid #0b2545;
            border-radius: 8px;
            padding: 28px 32px;
            margin: 24px 0 35px 0;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            color: #1e293b;
        }
        .frnp-reg-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f1f5f9;
            gap: 15px;
        }
        .frnp-reg-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #0b2545;
            margin: 0;
            line-height: 1.25;
        }
        .frnp-reg-desc {
            color: #475569;
            font-size: 0.95rem;
            line-height: 1.55;
            margin: 6px 0 0 0;
        }
        .frnp-reg-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .frnp-reg-badge.badge-open { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .frnp-reg-badge.badge-waitlist { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .frnp-reg-badge.badge-closed { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        .frnp-cap-bar-wrapper {
            margin: 14px 0 22px 0;
            padding: 10px 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.85rem;
            color: #475569;
        }
        .frnp-cap-meter {
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            width: 140px;
            margin-left: 12px;
        }
        .frnp-cap-fill {
            height: 100%;
            background: #0b2545;
            transition: width 0.3s ease;
        }

        .frnp-reg-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px 20px;
        }
        .frnp-grid-full {
            grid-column: 1 / -1;
        }
        @media (max-width: 680px) {
            .frnp-reg-grid { grid-template-columns: 1fr; }
            .frnp-reg-card { padding: 20px; }
        }

        .frnp-field-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .frnp-field-label {
            font-size: 0.88rem;
            font-weight: 700;
            color: #0b2545;
        }
        .frnp-req-star {
            color: #dc2626;
            margin-left: 2px;
        }
        .frnp-input, .frnp-select, .frnp-textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 14px;
            font-size: 0.92rem;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            background: #ffffff;
            color: #1e293b;
            font-family: inherit;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .frnp-input:focus, .frnp-select:focus, .frnp-textarea:focus {
            border-color: #0b2545;
            outline: none;
            box-shadow: 0 0 0 3px rgba(11, 37, 69, 0.12);
        }
        .frnp-field-help {
            font-size: 0.78rem;
            color: #64748b;
            line-height: 1.35;
            margin-top: 2px;
        }

        .frnp-option-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 8px 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            font-size: 0.88rem;
            color: #334155;
            cursor: pointer;
            user-select: none;
            transition: background 0.15s ease;
        }
        .frnp-option-item:hover {
            background: #f1f5f9;
        }
        .frnp-option-item input[type="checkbox"],
        .frnp-option-item input[type="radio"] {
            margin-top: 3px;
            width: 16px;
            height: 16px;
            accent-color: #0b2545;
            cursor: pointer;
        }

        .frnp-reg-submit {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            justify-content: flex-start;
        }
        .frnp-btn-submit {
            background: #0b2545;
            color: #ffffff;
            font-size: 0.95rem;
            font-weight: 700;
            padding: 12px 28px;
            border: 2px solid #0b2545;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.15s ease;
            box-shadow: 0 2px 4px rgba(11, 37, 69, 0.15);
        }
        .frnp-btn-submit:hover {
            background: #c59b27;
            border-color: #c59b27;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .frnp-reg-alert {
            padding: 14px 18px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.92rem;
            line-height: 1.5;
        }
        .frnp-reg-alert.alert-success { background: #ecfdf5; border-left: 4px solid #059669; color: #065f46; }
        .frnp-reg-alert.alert-waitlist { background: #fffbeb; border-left: 4px solid #d97706; color: #92400e; }
        .frnp-reg-alert.alert-error { background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; }
    </style>

    <div class="frnp-reg-card" id="registration-form-<?= $form_id ?>">
        <div class="frnp-reg-header">
            <div>
                <h3 class="frnp-reg-title"><?= htmlspecialchars($form['title']) ?></h3>
                <?php if (!empty($form['description'])): ?>
                    <p class="frnp-reg-desc"><?= nl2br(htmlspecialchars($form['description'])) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <?php if (!$form['status']): ?>
                    <span class="frnp-reg-badge badge-closed">Closed</span>
                <?php elseif ($is_full): ?>
                    <span class="frnp-reg-badge <?= $waitlist_allowed ? 'badge-waitlist' : 'badge-closed' ?>">
                        <?= $waitlist_allowed ? 'Waitlist Open' : 'Full' ?>
                    </span>
                <?php else: ?>
                    <span class="frnp-reg-badge badge-open">Registration Open</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($capacity > 0): ?>
            <div class="frnp-cap-bar-wrapper">
                <div>
                    Capacity: <strong><?= $counts['confirmed'] ?></strong> of <?= $capacity ?> slots filled
                    <?php if ($counts['waitlist'] > 0): ?>
                        &bull; <span style="color:#d97706;">(<?= $counts['waitlist'] ?> Waitlist)</span>
                    <?php endif; ?>
                </div>
                <div style="display:flex; align-items:center;">
                    <span style="font-weight:700;"><?= $pct_filled ?>%</span>
                    <div class="frnp-cap-meter">
                        <div class="frnp-cap-fill" style="width: <?= $pct_filled ?>%;"></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($status_msg): ?>
            <div class="frnp-reg-alert alert-<?= $status_type ?>">
                <?= $status_msg ?>
            </div>
        <?php endif; ?>

        <?php if (!$form['status']): ?>
            <p style="color:#64748b; font-style:italic; margin: 15px 0 0 0;">Registrations for this form are currently inactive.</p>
        <?php elseif ($is_full && !$waitlist_allowed): ?>
            <p style="color:#64748b; font-style:italic; margin: 15px 0 0 0;">This event is fully booked and waitlists are not enabled.</p>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="action" value="submit_reg_<?= $form_id ?>">

                <div class="frnp-reg-grid">
                    <!-- Core Email Field -->
                    <div class="frnp-field-group frnp-grid-full">
                        <label class="frnp-field-label">Email Address<span class="frnp-req-star">*</span></label>
                        <input type="email" name="reg_email" required placeholder="name@domain.com" class="frnp-input" value="<?= htmlspecialchars($_POST['reg_email'] ?? '') ?>">
                    </div>

                    <!-- Custom Fields Configured in Step 2 -->
                    <?php if (!empty($schema)): ?>
                        <?php foreach ($schema as $f): ?>
                            <?php
                                $fname = $f['name'];
                                $ftype = $f['type'] ?? 'text';
                                $is_req = !empty($f['required']);
                                $req_attr = $is_req ? 'required' : '';
                                $is_full_width = in_array($ftype, ['textarea', 'checkbox', 'checkbox_group', 'radio', 'phone'], true);
                            ?>
                            <div class="frnp-field-group <?= $is_full_width ? 'frnp-grid-full' : '' ?>">
                                <?php if ($ftype === 'checkbox'): ?>
                                    <label class="frnp-option-item" style="margin-top:6px;">
                                        <input type="checkbox" name="custom_<?= htmlspecialchars($fname) ?>" value="1" <?= $req_attr ?>>
                                        <div>
                                            <span style="font-weight:700; color:#0b2545;"><?= htmlspecialchars($f['label']) ?></span>
                                            <?php if ($is_req): ?><span class="frnp-req-star">*</span><?php endif; ?>
                                            <?php if (!empty($f['help_text'])): ?>
                                                <div class="frnp-field-help"><?= htmlspecialchars($f['help_text']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </label>
                                <?php elseif ($ftype === 'phone'): ?>
                                    <label class="frnp-field-label">
                                        <?= htmlspecialchars($f['label']) ?>
                                        <?php if ($is_req): ?><span class="frnp-req-star">*</span><?php endif; ?>
                                    </label>
                                    <input type="tel" name="custom_<?= htmlspecialchars($fname) ?>" placeholder="(270) 555-0100" class="frnp-input" maxlength="14" oninput="formatPhoneInput(this)" <?= $req_attr ?>>
                                    <?php if (!empty($f['help_text'])): ?>
                                        <span class="frnp-field-help"><?= htmlspecialchars($f['help_text']) ?></span>
                                    <?php endif; ?>
                                <?php elseif ($ftype === 'checkbox_group'): ?>
                                    <label class="frnp-field-label">
                                        <?= htmlspecialchars($f['label']) ?>
                                        <?php if ($is_req): ?><span class="frnp-req-star">*</span><?php endif; ?>
                                    </label>
                                    <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:4px;">
                                        <?php foreach (($f['options'] ?? []) as $opt_k => $opt_v): ?>
                                            <label class="frnp-option-item">
                                                <input type="checkbox" name="custom_<?= htmlspecialchars($fname) ?>[]" value="<?= htmlspecialchars((string)$opt_k) ?>">
                                                <span><?= htmlspecialchars((string)$opt_v) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (!empty($f['help_text'])): ?>
                                        <span class="frnp-field-help"><?= htmlspecialchars($f['help_text']) ?></span>
                                    <?php endif; ?>
                                <?php elseif ($ftype === 'radio'): ?>
                                    <label class="frnp-field-label">
                                        <?= htmlspecialchars($f['label']) ?>
                                        <?php if ($is_req): ?><span class="frnp-req-star">*</span><?php endif; ?>
                                    </label>
                                    <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:4px;">
                                        <?php foreach (($f['options'] ?? []) as $opt_k => $opt_v): ?>
                                            <label class="frnp-option-item">
                                                <input type="radio" name="custom_<?= htmlspecialchars($fname) ?>" value="<?= htmlspecialchars((string)$opt_k) ?>" <?= $req_attr ?>>
                                                <span><?= htmlspecialchars((string)$opt_v) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (!empty($f['help_text'])): ?>
                                        <span class="frnp-field-help"><?= htmlspecialchars($f['help_text']) ?></span>
                                    <?php endif; ?>
                                <?php elseif ($ftype === 'textarea'): ?>
                                    <label class="frnp-field-label">
                                        <?= htmlspecialchars($f['label']) ?>
                                        <?php if ($is_req): ?><span class="frnp-req-star">*</span><?php endif; ?>
                                    </label>
                                    <textarea name="custom_<?= htmlspecialchars($fname) ?>" rows="3" class="frnp-textarea" <?= $req_attr ?>></textarea>
                                    <?php if (!empty($f['help_text'])): ?>
                                        <span class="frnp-field-help"><?= htmlspecialchars($f['help_text']) ?></span>
                                    <?php endif; ?>
                                <?php elseif ($ftype === 'select'): ?>
                                    <label class="frnp-field-label">
                                        <?= htmlspecialchars($f['label']) ?>
                                        <?php if ($is_req): ?><span class="frnp-req-star">*</span><?php endif; ?>
                                    </label>
                                    <select name="custom_<?= htmlspecialchars($fname) ?>" class="frnp-select" <?= $req_attr ?>>
                                        <option value="">-- Please Select --</option>
                                        <?php foreach (($f['options'] ?? []) as $opt_k => $opt_v): ?>
                                            <option value="<?= htmlspecialchars((string)$opt_k) ?>"><?= htmlspecialchars((string)$opt_v) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!empty($f['help_text'])): ?>
                                        <span class="frnp-field-help"><?= htmlspecialchars($f['help_text']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <label class="frnp-field-label">
                                        <?= htmlspecialchars($f['label']) ?>
                                        <?php if ($is_req): ?><span class="frnp-req-star">*</span><?php endif; ?>
                                    </label>
                                    <input type="<?= $ftype === 'number' ? 'number' : ($ftype === 'date' ? 'date' : 'text') ?>" 
                                           name="custom_<?= htmlspecialchars($fname) ?>" class="frnp-input" <?= $req_attr ?>>
                                    <?php if (!empty($f['help_text'])): ?>
                                        <span class="frnp-field-help"><?= htmlspecialchars($f['help_text']) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="frnp-reg-submit">
                    <button type="submit" class="frnp-btn-submit">
                        <?= $is_full ? 'Join Event Waitlist' : 'Submit Registration' ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <script>
    function formatPhoneInput(input) {
        let numbers = input.value.replace(/\D/g, '').substring(0, 10);
        let result = '';
        if (numbers.length > 0) {
            result = '(' + numbers.substring(0, 3);
        }
        if (numbers.length >= 4) {
            result += ') ' + numbers.substring(3, 6);
        }
        if (numbers.length >= 7) {
            result += '-' + numbers.substring(6, 10);
        }
        input.value = result;
    }
    </script>
    <?php
}

/**
 * Filter block markup: Replaces [registration_form:ID] tags with the interactive form.
 */
function replace_registration_form_tags(string $text): string {
    return preg_replace_callback('/\[registration_form:(\d+)\]/', function($matches) {
        $fid = (int)$matches[1];
        $form = get_registration_form_by_id($fid);
        if (!$form) return '';
        ob_start();
        render_registration_form_view($form, false);
        return ob_get_clean();
    }, $text);
}

/**
 * Compatibility alias for legacy template calls.
 */
function render_registration_form(array $node): void {
    if (empty($node['id'])) {
        return;
    }
    $form = get_registration_form_by_slug($node['slug'] ?? '');
    if ($form) {
        render_registration_form_view($form, false);
    }
}