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
    // Ensure unique_email column exists on registration_forms
    $has_unique_email = $db_init->query("SHOW COLUMNS FROM registration_forms LIKE 'unique_email'")->fetch();
    if (!$has_unique_email) {
        $db_init->exec("ALTER TABLE registration_forms ADD COLUMN unique_email TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_waitlist");
    }
    $has_slot_id = $db_init->query("SHOW COLUMNS FROM registrations LIKE 'slot_id'")->fetch();
    if (!$has_slot_id) {
        $db_init->exec("ALTER TABLE registrations ADD COLUMN slot_id INT UNSIGNED NULL DEFAULT NULL AFTER form_id, ADD INDEX idx_reg_slot (slot_id)");
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
 * Fetch all active time slots with live confirmed and waitlist counts for a form.
 */
function get_registration_time_slots(int $form_id): array {
    $db = get_db();
    $stmt = $db->prepare("
        SELECT ts.*,
               COALESCE(SUM(CASE WHEN r.state = 'complete' THEN r.slots ELSE 0 END), 0) AS confirmed_slots,
               COALESCE(SUM(CASE WHEN r.state = 'waitlist' THEN r.slots ELSE 0 END), 0) AS waitlist_slots
        FROM registration_time_slots ts
        LEFT JOIN registrations r ON r.slot_id = ts.id
        WHERE ts.form_id = :fid AND ts.status = 1
        GROUP BY ts.id
        ORDER BY ts.weight ASC, ts.id ASC
    ");
    $stmt->execute(['fid' => $form_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    // Check unique email restriction if configured for this form
    if (!empty($form['unique_email'])) {
        $db = get_db();
        $dup_stmt = $db->prepare("
            SELECT COUNT(*) FROM registrations 
            WHERE form_id = :fid AND LOWER(email) = LOWER(:email) AND state != 'canceled'
        ");
        $dup_stmt->execute([
            'fid'   => $form_id,
            'email' => $email
        ]);
        if ((int)$dup_stmt->fetchColumn() > 0) {
            return [
                'success' => false, 
                'error'   => 'A registration has already been submitted for this event using the email address ' . htmlspecialchars($email) . '.'
            ];
        }
    }

    // Time Slot Validation (For Tour Registrations)
    $is_tour = (($form['form_type'] ?? '') === 'tour');
    $selected_slot_id = null;
    $selected_slot_time = '';
    $time_slots = get_registration_time_slots($form_id);

    if ($is_tour && !empty($time_slots)) {
        $raw_slot_id = isset($post_data['slot_id']) ? (int)$post_data['slot_id'] : 0;
        if ($raw_slot_id <= 0) {
            return ['success' => false, 'error' => 'Please select a tour time slot.'];
        }

        $matched_slot = null;
        foreach ($time_slots as $ts) {
            if ((int)$ts['id'] === $raw_slot_id) {
                $matched_slot = $ts;
                break;
            }
        }

        if (!$matched_slot) {
            return ['success' => false, 'error' => 'The selected tour time slot is invalid.'];
        }

        $selected_slot_id = (int)$matched_slot['id'];
        $selected_slot_time = (string)$matched_slot['slot_time'];
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

        if ($ftype === 'attestation') {
            $is_checked = !empty($val);
            if (!empty($f['required']) && !$is_checked) {
                $err_label = !empty($f['label']) ? $f['label'] : 'Attestation';
                return ['success' => false, 'error' => "You must confirm '{$err_label}' to proceed."];
            }
            $custom_values[$fname] = $is_checked ? 'Confirmed' : 'Not Confirmed';
        } elseif ($ftype === 'checkbox') {
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

    $state = 'complete';

    // Capacity verification: check slot-specific capacity if tour with slots, otherwise form capacity
    if ($is_tour && $selected_slot_id !== null && isset($matched_slot)) {
        $slot_cap = (int)$matched_slot['capacity'];
        $slot_confirmed = (int)$matched_slot['confirmed_slots'];
        if ($slot_cap > 0 && ($slot_confirmed + $slots) > $slot_cap) {
            if (!empty($matched_slot['allow_waitlist']) || !empty($form['allow_waitlist'])) {
                $state = 'waitlist';
            } else {
                return ['success' => false, 'error' => "The {$matched_slot['slot_time']} tour time is currently full and no waitlist is available."];
            }
        }
    } else {
        $counts = get_form_attendee_counts($form_id);
        $capacity = (int)$form['capacity'];
        if ($capacity > 0 && ($counts['confirmed'] + $slots) > $capacity) {
            if (!empty($form['allow_waitlist'])) {
                $state = 'waitlist';
            } else {
                return ['success' => false, 'error' => 'Registration is full. No additional slots are available.'];
            }
        }
    }

    $uid = (function_exists('current_user') && current_user()) ? (int)current_user()['id'] : null;

    $db = get_db();
    $stmt = $db->prepare('
        INSERT INTO registrations (form_id, slot_id, user_id, name, email, phone, organization, slots, state, submission_data, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $form_id,
        $selected_slot_id,
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

    // Trigger Rules Engine automation
    if (file_exists(__DIR__ . '/rules.php')) {
        require_once __DIR__ . '/rules.php';
        rules_invoke_event('registration_submit', [
            'registration' => [
                'id'              => $reg_id,
                'form_id'         => $form['id'],
                'slot_id'         => $selected_slot_id,
                'slot_time'       => $selected_slot_time,
                'tour_date'       => $form['tour_date'] ?? null,
                'name'            => $name,
                'email'           => $email,
                'phone'           => $phone,
                'organization'    => $org,
                'slots'           => $slots,
                'state'           => $state,
                'submission_data' => json_encode($custom_values),
                'created_at'      => date('Y-m-d H:i:s'),
            ],
            'form' => $form,
            'node' => !empty($form['node_id']) ? get_node_by_id((int)$form['node_id']) : null,
            'user' => function_exists('current_user') ? current_user() : null,
        ]);
    }

    $log_time_info = $selected_slot_time ? " [Time: {$selected_slot_time}]" : '';
    watchdog('registration', "Registration #{$reg_id} for '{$form['title']}' recorded [State: {$state}]{$log_time_info}", 'notice');

    return [
        'success'   => true,
        'state'     => $state,
        'reg_id'    => $reg_id,
        'slot_time' => $selected_slot_time
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

    // Format dynamic title for standalone tour registration pages
    $is_tour = (($form['form_type'] ?? '') === 'tour' || !empty($form['tour_date']));
    $tour_title = null;
    $time_slots = $is_tour ? get_registration_time_slots($form_id) : [];

    // Resolve preselected slot from query string (e.g. ?slot=8:00+AM or ?slot_id=2)
    $preselected_slot = trim((string)($_GET['slot'] ?? $_GET['slot_id'] ?? ''));

    if ($is_tour && $is_standalone_page) {
        $tour_month = '';
        $tour_day = '';

        if (!empty($form['tour_date'])) {
            $ts = strtotime($form['tour_date']);
            $tour_month = date('F', $ts);
            $tour_day   = date('j', $ts);
            $tour_year  = date('Y', $ts);
        }

        // Fallbacks from form title if tour_date was not explicitly set
        $title_raw = (string)($form['title'] ?? '');
        if (empty($tour_month) && preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\b/i', $title_raw, $m)) {
            $tour_month = ucfirst(strtolower($m[1]));
        }
        if (empty($tour_day) && preg_match('/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})\b/i', $title_raw, $m)) {
            $tour_day = $m[1];
        }

        $tour_title = "Paducah Site Tour · {$tour_month} {$tour_year}";

        global $node, $page_title;
        if (isset($node) && is_array($node)) {
            $node['title'] = $tour_title;
        }
        $GLOBALS['node']['title'] = $tour_title;
        $site_name = function_exists('get_site_setting') ? get_site_setting('site_name', 'Four Rivers Nuclear Partnership') : 'Four Rivers Nuclear Partnership';
        $page_title = htmlspecialchars($tour_title) . ' | ' . htmlspecialchars($site_name);
    }

    $status_msg = '';
    $status_type = 'success';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_reg_' . $form_id) {
        validate_csrf();
        $res = process_form_submission($form_id, $_POST);
        if ($res['success']) {
            $is_wait = ($res['state'] === 'waitlist');
            $status_type = $is_wait ? 'waitlist' : 'success';
            $slot_text = !empty($res['slot_time']) ? " for the <strong>{$res['slot_time']}</strong> tour session" : '';
            $default_conf = $is_wait
                ? "You have been placed on the <strong>Waitlist</strong>{$slot_text}. We will contact you if a slot opens."
                : "Your registration{$slot_text} has been confirmed. Thank you for signing up.";
            $status_msg = !empty($form['confirmation_message']) ? nl2br(htmlspecialchars($form['confirmation_message'])) : $default_conf;
            $counts = get_form_attendee_counts($form_id);
            $is_full = ($capacity > 0 && $counts['confirmed'] >= $capacity);
            if ($is_tour) {
                $time_slots = get_registration_time_slots($form_id);
            }
        } else {
            $status_type = 'error';
            $status_msg = htmlspecialchars($res['error']);
        }
    }

    $pct_filled = ($capacity > 0) ? min(100, round(($counts['confirmed'] / $capacity) * 100)) : 0;
    ?>
    <style>
        .frnp-reg-card {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            color: #1e293b;
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
            margin: 10px 0 0 0;
        }
        .frnp-reg-desc p {
            margin-bottom: 10px;
        }
        .frnp-reg-desc p:last-child {
            margin-bottom: 0;
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

        /* Tour Time Slots Card Grid */
        .frnp-slots-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 6px;
        }
        .frnp-slot-card {
            border: 2px solid #cbd5e1;
            padding: 14px;
            border-radius: 6px;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            gap: 4px;
            cursor: pointer;
            transition: all 0.15s ease;
            position: relative;
        }
        .frnp-slot-card:hover {
            border-color: #0b2545;
            background: #f8fafc;
        }
        .frnp-slot-card.is-selected {
            border-color: #0b2545;
            background: #f0f7ff;
            box-shadow: 0 0 0 1px #0b2545;
        }
        .frnp-slot-card.is-full {
            background: #f8fafc;
            border-color: #e2e8f0;
            cursor: pointer;
        }
        .frnp-slot-card input[type="radio"] {
            margin-right: 6px;
            accent-color: #0b2545;
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

        /* Attestation Single Checkbox Layout */
        .frnp-attestation-group {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #0b2545;
            border-radius: 4px;
            padding: 14px 18px;
            margin-top: 4px;
        }
        .frnp-attestation-line {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            margin-top: 6px;
            user-select: none;
        }
        .frnp-attestation-line input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #0b2545;
            cursor: pointer;
            margin: 0;
            flex-shrink: 0;
        }
        .frnp-attestation-text {
            font-size: 0.92rem;
            font-weight: 600;
            color: #1e293b;
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
            <h3 class="frnp-reg-title"><?= htmlspecialchars($tour_title ?? $form['title']) ?></h3>
            <div>
                <?php if (!$form['status']): ?>
                    <span class="frnp-reg-badge badge-closed">Closed</span>
                <?php elseif ($is_full): ?>
                    <span class="frnp-reg-badge <?= $waitlist_allowed ? 'badge-waitlist' : 'badge-closed' ?>">
                        <?= $waitlist_allowed ? 'Waitlist Open' : 'Full' ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($form['description'])): ?>
            <div class="frnp-reg-desc"><?= check_markup($form['description'], 'filtered_html') ?></div>
        <?php endif; ?>

        <?php if ($capacity > 0 && !$is_tour): ?>
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
        <?php elseif ($is_full && !$waitlist_allowed && empty($time_slots)): ?>
            <p style="color:#64748b; font-style:italic; margin: 15px 0 0 0;">This event is fully booked and waitlists are not enabled.</p>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="action" value="submit_reg_<?= $form_id ?>">

                <div class="frnp-reg-grid">
                    <!-- Tour Time Slot Selection (When multiple slots exist) -->
                    <?php if ($is_tour && !empty($time_slots)): ?>
                        <div class="frnp-field-group frnp-grid-full" style="margin-bottom: 8px;">
                            <label class="frnp-field-label">
                                Select Tour Time<span class="frnp-req-star">*</span>
                            </label>
                            <div class="frnp-slots-grid">
                                <?php foreach ($time_slots as $idx => $ts): 
                                    $scap = (int)$ts['capacity'];
                                    $sconf = (int)$ts['confirmed_slots'];
                                    $srem = max(0, $scap - $sconf);
                                    $s_is_full = ($scap > 0 && $sconf >= $scap);
                                    $s_allow_wait = !empty($ts['allow_waitlist']) || $waitlist_allowed;

                                    // Match selection from POST or query param (?slot=8:00+AM or ?slot_id=X)
                                    $is_checked = false;
                                    if (isset($_POST['slot_id'])) {
                                        $is_checked = ((int)$_POST['slot_id'] === (int)$ts['id']);
                                    } elseif ($preselected_slot !== '') {
                                        $is_checked = ($preselected_slot == $ts['id'] || strcasecmp($preselected_slot, $ts['slot_time']) === 0 || str_starts_with(strtolower($ts['slot_time']), strtolower($preselected_slot)));
                                    } elseif ($idx === 0 && !$s_is_full) {
                                        $is_checked = true;
                                    }
                                ?>
                                    <label class="frnp-slot-card <?= $is_checked ? 'is-selected' : '' ?> <?= $s_is_full ? 'is-full' : '' ?>">
                                        <div style="display:flex; justify-content:space-between; align-items:center;">
                                            <span style="font-weight:700; color:#0b2545; font-size:1rem; display:flex; align-items:center;">
                                                <input type="radio" name="slot_id" value="<?= (int)$ts['id'] ?>" <?= $is_checked ? 'checked' : '' ?> required onchange="updateSelectedSlotCard(this)">
                                                <?= htmlspecialchars($ts['slot_time']) ?>
                                            </span>
                                            <?php if ($s_is_full): ?>
                                                <span class="badge" style="background:#fee2e2; color:#991b1b; font-size:0.72rem; padding:2px 6px; font-weight:700; border-radius:4px;">
                                                    <?= $s_allow_wait ? 'WAITLIST' : 'FULL' ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge" style="background:#ecfdf5; color:#065f46; font-size:0.72rem; padding:2px 6px; font-weight:700; border-radius:4px;">
                                                    <?= $srem ?> seats left
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <span style="font-size:0.78rem; color:#64748b; margin-top:2px; margin-left: 22px;">
    <?php if ($s_is_full && $s_allow_wait): ?>
        <strong style="color:#d97706;">Waitlist Available</strong>
    <?php else: ?>
        Capacity: <?= $scap ?> seats
        <?php if ($s_is_full): ?>
            &bull; Fully Booked
        <?php endif; ?>
    <?php endif; ?>
</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Core Email Field -->
                    <div class="frnp-field-group frnp-grid-full">
                        <label class="frnp-field-label">Email Address<span class="frnp-req-star">*</span></label>
                        <input type="email" name="reg_email" required placeholder="name@domain.com" class="frnp-input" value="<?= htmlspecialchars($_POST['reg_email'] ?? '') ?>">
                    </div>

                    <!-- Custom Fields Configured in Form Schema -->
                    <?php if (!empty($schema)): ?>
                        <?php foreach ($schema as $f): ?>
                            <?php
                                $fname = $f['name'];
                                $ftype = $f['type'] ?? 'text';
                                $is_req = !empty($f['required']);
                                $req_attr = $is_req ? 'required' : '';
                                $is_full_width = in_array($ftype, ['textarea', 'checkbox', 'checkbox_group', 'radio', 'phone', 'attestation'], true);
                            ?>

                            <div class="frnp-field-group <?= $is_full_width ? 'frnp-grid-full' : '' ?>">
                                <?php if ($fname === 'dl_state' || $ftype === 'state'): ?>
                                    <?php
                                        $us_states = [
                                            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
                                            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
                                            'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
                                            'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
                                            'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
                                            'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
                                            'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
                                            'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
                                            'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
                                            'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
                                            'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
                                            'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
                                            'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming'
                                        ];
                                        $selected_state = $_POST['custom_' . $fname] ?? '';
                                    ?>
                                    <label class="frnp-field-label">
                                        <?= htmlspecialchars($f['label']) ?>
                                        <?php if ($is_req): ?><span class="frnp-req-star">*</span><?php endif; ?>
                                    </label>
                                    <select name="custom_<?= htmlspecialchars($fname) ?>" class="frnp-select" <?= $req_attr ?>>
                                        <option value="">-- Select State --</option>
                                        <?php foreach ($us_states as $abbr => $sname): ?>
                                            <option value="<?= $abbr ?>" <?= ($selected_state === $abbr) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($sname) ?> (<?= htmlspecialchars($abbr) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!empty($f['help_text'])): ?>
                                        <span class="frnp-field-help"><?= htmlspecialchars($f['help_text']) ?></span>
                                    <?php endif; ?>

                                <?php elseif ($ftype === 'attestation'): ?>
                                    <div class="frnp-attestation-group">
                                        <div class="frnp-field-label">
                                            <?= htmlspecialchars($f['label']) ?>
                                            <?php if ($is_req): ?><span class="frnp-req-star">*</span><?php endif; ?>
                                        </div>
                                        <?php if (!empty($f['help_text'])): ?>
                                            <div class="frnp-field-help" style="margin-bottom: 8px;"><?= htmlspecialchars($f['help_text']) ?></div>
                                        <?php endif; ?>
                                        <label class="frnp-attestation-line">
                                            <input type="checkbox" name="custom_<?= htmlspecialchars($fname) ?>" value="1" <?= $req_attr ?>>
                                            <span class="frnp-attestation-text"><?= htmlspecialchars(!empty($f['checkbox_label']) ? $f['checkbox_label'] : 'I agree') ?></span>
                                        </label>
                                    </div>
                                <?php elseif ($ftype === 'checkbox'): ?>
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
    function updateSelectedSlotCard(radio) {
        document.querySelectorAll('.frnp-slot-card').forEach(card => card.classList.remove('is-selected'));
        if (radio && radio.closest('.frnp-slot-card')) {
            radio.closest('.frnp-slot-card').classList.add('is-selected');
        }
    }

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

    document.addEventListener('DOMContentLoaded', function () {
    const phoneInput = document.querySelector('input[name="custom_phone"]');

    if (!phoneInput) return;

    function formatPhoneNumber(val) {
        // Strip everything except numeric digits
        const digits = val.replace(/\D/g, '').substring(0, 10);
        const len = digits.length;

        if (len === 0) {
            return '';
        } else if (len < 4) {
            return `(${digits}`;
        } else if (len < 7) {
            return `(${digits.substring(0, 3)}) ${digits.substring(3)}`;
        } else {
            return `(${digits.substring(0, 3)}) ${digits.substring(3, 6)}-${digits.substring(6, 10)}`;
        }
    }

    phoneInput.addEventListener('input', function (e) {
        // Format live as digits are typed or pasted
        this.value = formatPhoneNumber(this.value);
    });

    phoneInput.addEventListener('keydown', function (e) {
        // Handle Backspace when directly adjacent to formatting characters
        if (e.key === 'Backspace') {
            const cursor = this.selectionStart;
            const val = this.value;

            // If the character directly behind the cursor is a formatting char, drop the digit before it
            if (cursor && [' ', ')', '-', '('].includes(val[cursor - 1])) {
                e.preventDefault();
                const digits = val.replace(/\D/g, '');
                // Calculate position relative to raw digits and strip one
                const digitsBeforeCursor = val.substring(0, cursor).replace(/\D/g, '');
                const targetIdx = digitsBeforeCursor.length - 1;

                if (targetIdx >= 0) {
                    const newDigits = digits.slice(0, targetIdx) + digits.slice(targetIdx + 1);
                    this.value = formatPhoneNumber(newDigits);
                }
            }
        }
    });

    phoneInput.addEventListener('paste', function (e) {
        e.preventDefault();
        const pasteData = (e.clipboardData || window.clipboardData).getData('text');
        this.value = formatPhoneNumber(pasteData);
    });
});
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

