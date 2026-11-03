<?php
declare(strict_types=1);

/**
 * Node Template: Tour Information & 3-Column Schedule Layout
 * Used for the main /tour or /tours listing page.
 */

$db = get_db();

// 1. Auto-repair existing waitlist form records so they are never treated as tours
try {
    $db->exec("
        UPDATE registration_forms 
        SET form_type = 'general', tour_date = NULL 
        WHERE slug = 'tour-availability-waitlist' 
           OR title LIKE '%Tour%Waitlist%' 
           OR title LIKE '%Tour Availability%'
    ");
} catch (Exception $e) {
    // Continue silently if table is unchanged
}

// 2. Query strictly active tours (form_type = 'tour') with valid tour dates
$tour_stmt = $db->prepare("
    SELECT f.*, 
           COALESCE(SUM(CASE WHEN r.state = 'complete' THEN r.slots ELSE 0 END), 0) AS confirmed_slots,
           COALESCE(SUM(CASE WHEN r.state = 'waitlist' THEN r.slots ELSE 0 END), 0) AS waitlist_slots
    FROM registration_forms f
    LEFT JOIN registrations r ON r.form_id = f.id
    WHERE f.status = 1 
      AND LOWER(f.form_type) IN ('tour', 'tours')
      AND f.slug != 'tour-availability-waitlist'
      AND f.title NOT LIKE '%Waitlist%'
      AND f.title NOT LIKE '%Tour Availability%'
      AND f.tour_date IS NOT NULL 
      AND f.tour_date != '' 
      AND f.tour_date != '0000-00-00'
    GROUP BY f.id
    ORDER BY f.tour_date ASC, f.tour_time ASC
");
$tour_stmt->execute();
$all_tours = $tour_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Group valid tours by Date
$tours_by_date = [];
foreach ($all_tours as $t) {
    if (empty($t['tour_date'])) {
        continue;
    }
    $ts = strtotime((string)$t['tour_date']);
    if (!$ts) {
        continue;
    }
    $date_key = date('Y-m-d', $ts);

    if (!isset($tours_by_date[$date_key])) {
        $tours_by_date[$date_key] = [
            'month_label' => date('F', $ts),
            'day_num'     => date('j', $ts),
            'weekday'     => date('l', $ts),
            'full_date'   => date('F j, Y', $ts),
            'sessions'    => []
        ];
    }
    $tours_by_date[$date_key]['sessions'][] = $t;
}
?>

<style>
    .tours-content-wrapper {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        color: #1e293b;
    }
    .tours-body-region {
        font-size: 1.05rem;
        line-height: 1.7;
        color: #334155;
        margin-bottom: 30px;
    }
    .tours-body-region p {
        margin-bottom: 16px;
    }

    /* 3-Column Side-by-Side Tour Grid */
    .tour-schedule-grid-3col {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        align-items: stretch;
        margin-top: 20px;
    }
    @media (max-width: 960px) {
        .tour-schedule-grid-3col {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    @media (max-width: 640px) {
        .tour-schedule-grid-3col {
            grid-template-columns: 1fr;
        }
    }

    .tour-date-card {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        display: flex;
        flex-direction: column;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .tour-date-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }
    .tour-date-header {
        background: var(--frnp-navy, #0b2545);
        color: #ffffff;
        padding: 14px 18px;
        margin: 0;
        text-align: center;
    }
    .tour-date-day {
        font-size: 1.25rem;
        font-weight: 800;
        letter-spacing: 0.3px;
    }

    .tour-card-body {
        padding: 18px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        flex: 1;
        background: #ffffff;
    }
    .tour-section-label {
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 4px;
    }

    /* Interactive Click-to-Register Time Slot Buttons */
    .btn-tour-slot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--frnp-navy, #0b2545);
        color: #ffffff !important;
        padding: 10px 14px;
        border-radius: 4px;
        text-decoration: none;
        font-size: 0.9rem;
        font-weight: 700;
        transition: all 0.15s ease;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
    }
    .btn-tour-slot:hover {
        background: lightblue;
        color: #0b2545 !important;
        transform: translateY(-1px);
        box-shadow: 0 3px 6px rgba(0, 0, 0, 0.12);
    }
    .slot-capacity-pill {
        font-size: 0.72rem;
        background: rgba(255, 255, 255, 0.2);
        padding: 2px 6px;
        border-radius: 3px;
        font-weight: 600;
        white-space: nowrap;
    }

    /* Full State */
    .btn-tour-slot-full {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #f1f5f9;
        color: #64748b !important;
        border: 1px solid #cbd5e1;
        padding: 10px 14px;
        border-radius: 4px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: not-allowed;
        user-select: none;
    }
    .full-tag {
        background: #fee2e2;
        color: #991b1b;
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        padding: 2px 6px;
        border-radius: 3px;
        border: 1px solid #fecaca;
    }

    /* Waitlist State */
    .btn-tour-slot-waitlist {
        background: #ffffff;
        color: #0b2545 !important;
        border: 1px solid #cbd5e1;
    }
    .btn-tour-slot-waitlist:hover {
        background: #f1f5f9;
        color: #0b2545 !important;
    }
    .waitlist-tag {
        background: #fef3c7;
        color: #92400e;
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        padding: 2px 6px;
        border-radius: 3px;
        border: 1px solid #fde68a;
    }

    .no-tours-alert {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 6px;
        padding: 28px 20px;
        text-align: center;
        color: #64748b;
        font-size: 0.95rem;
    }
</style>

<article class="node-page tours-content-wrapper">
    <!-- 1. CMS Node Body Content -->
    <?php if (!empty($node['body'])): ?>
        <div class="node-body tours-body-region">
            <?= check_markup($node['body'], $node['format'] ?? 'filtered_html', ['node' => $node]) ?>
        </div>
    <?php endif; ?>

    <!-- 2. Tour Schedule Section -->
    <section class="tour-schedule-section">
        <h2 style="color: var(--frnp-navy, #0b2545); font-size: 1.45rem; font-weight: 800; margin-bottom: 6px;">
            Public Tour Schedule
        </h2>
        <p style="color: #64748b; font-size: 0.92rem; margin: 0 0 16px 0;">
            Select an available tour session below to complete your registration and security badging request.
        </p>

        <?php if (empty($tours_by_date)): ?>
            <?php
            // Process notification interest signup
            $interest_success = false;
            $interest_error = '';

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'tour_interest_signup') {
                $name      = trim($_POST['full_name'] ?? '');
                $email     = trim($_POST['email'] ?? '');
                $client_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

                if (empty($name)) {
                    $interest_error = 'Please provide your full name.';
                } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $interest_error = 'Please provide a valid email address.';
                } else {
                    try {
                        // Ensure parent form exists in registration_forms with form_type = 'general'
                        $form_slug = 'tour-availability-waitlist';
                        $form_stmt = $db->prepare("SELECT id FROM registration_forms WHERE slug = ? OR title = 'Tour Availability Notifications' LIMIT 1");
                        $form_stmt->execute([$form_slug]);
                        $form_id = $form_stmt->fetchColumn();

                        if (!$form_id) {
                            $rf_cols = $db->query("SHOW COLUMNS FROM registration_forms")->fetchAll(PDO::FETCH_COLUMN);

                            $form_data = [
                                'title'       => 'Tour Availability Notifications',
                                'slug'        => $form_slug,
                                'description' => 'Advance interest list for future tour dates.',
                            ];

                            if (in_array('status', $rf_cols)) $form_data['status'] = 1;
                            if (in_array('capacity', $rf_cols)) $form_data['capacity'] = 0;
                            if (in_array('allow_waitlist', $rf_cols)) $form_data['allow_waitlist'] = 0;
                            if (in_array('form_type', $rf_cols)) $form_data['form_type'] = 'general';
                            if (in_array('host_type', $rf_cols)) $form_data['host_type'] = 'Page';
                            if (in_array('tour_date', $rf_cols)) $form_data['tour_date'] = null;
                            if (in_array('fields_schema', $rf_cols)) $form_data['fields_schema'] = json_encode([]);
                            if (in_array('created_at', $rf_cols)) $form_data['created_at'] = date('Y-m-d H:i:s');
                            if (in_array('created', $rf_cols)) $form_data['created'] = time();

                            $f_keys = array_keys($form_data);
                            $f_fields = implode(', ', $f_keys);
                            $f_placeholders = implode(', ', array_fill(0, count($f_keys), '?'));

                            $create_form = $db->prepare("INSERT INTO registration_forms ($f_fields) VALUES ($f_placeholders)");
                            $create_form->execute(array_values($form_data));
                            $form_id = $db->lastInsertId();
                        }

                        // Prevent duplicate signups
                        $dup_check = $db->prepare("SELECT id FROM registrations WHERE form_id = ? AND email = ? LIMIT 1");
                        $dup_check->execute([$form_id, $email]);

                        if ($dup_check->fetch()) {
                            $interest_success = true;
                            $interest_msg = "You are already registered for notifications. We will email you as soon as new tour dates open!";
                        } else {
                            $reg_cols = $db->query("SHOW COLUMNS FROM registrations")->fetchAll(PDO::FETCH_COLUMN);

                            $reg_data = [
                                'form_id' => (int)$form_id,
                                'email'   => $email,
                            ];

                            if (in_array('name', $reg_cols)) {
                                $reg_data['name'] = $name;
                            } elseif (in_array('full_name', $reg_cols)) {
                                $reg_data['full_name'] = $name;
                            }

                            if (in_array('node_id', $reg_cols)) {
                                $reg_data['node_id'] = !empty($node['id']) ? (int)$node['id'] : 0;
                            }

                            if (in_array('state', $reg_cols)) $reg_data['state'] = 'complete';
                            if (in_array('status', $reg_cols)) $reg_data['status'] = 1;
                            if (in_array('ip_address', $reg_cols)) $reg_data['ip_address'] = $client_ip;
                            if (in_array('slots', $reg_cols)) $reg_data['slots'] = 1;

                            if (in_array('submission_data', $reg_cols)) {
                                $reg_data['submission_data'] = json_encode(['name' => $name, 'email' => $email]);
                            }

                            if (in_array('created_at', $reg_cols)) {
                                $reg_data['created_at'] = date('Y-m-d H:i:s');
                            } elseif (in_array('created', $reg_cols)) {
                                $reg_data['created'] = time();
                            }

                            $r_keys = array_keys($reg_data);
                            $r_fields = implode(', ', $r_keys);
                            $r_placeholders = implode(', ', array_fill(0, count($r_keys), '?'));

                            $reg_stmt = $db->prepare("INSERT INTO registrations ($r_fields) VALUES ($r_placeholders)");
                            $reg_stmt->execute(array_values($reg_data));

                            $interest_success = true;
                            $interest_msg = "Thank you, " . htmlspecialchars($name) . "! You've been added to our list. We will notify you by email as soon as new tour sessions are scheduled.";
                        }
                    } catch (Exception $e) {
                        $interest_error = 'Unable to save request: ' . $e->getMessage();
                    }
                }
            }
            ?>

            <div class="no-tours-wrapper" style="margin: 20px 0 30px;">
                <div class="no-tours-alert" style="background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid var(--frnp-navy, #0b2545); padding: 18px 22px; border-radius: 4px; margin-bottom: 24px; text-align: left;">
                    <strong style="color: var(--frnp-navy, #0b2545); display: block; font-size: 1.15rem; margin-bottom: 6px;">
                        No upcoming tours currently scheduled
                    </strong>
                    <span style="color: #4a5568; line-height: 1.5; display: block;">
                        There are currently no active tour sessions open for online registration. Please join our advance notification list below to receive an email alert the moment new dates and time slots become available.
                    </span>
                </div>

                <?php if ($interest_success): ?>
                    <div class="alert alert-success" style="background: #edf7ed; color: #1e4620; border: 1px solid #c8e6c9; padding: 16px 20px; border-radius: 6px;">
                        <strong style="display: block; font-size: 1rem; margin-bottom: 4px;">✓ Notification Request Confirmed</strong>
                        <p style="margin: 0; line-height: 1.5;"><?= $interest_msg ?></p>
                    </div>
                <?php else: ?>
                    <div class="tour-interest-card" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); max-width: 600px;">
                        <h3 style="margin-top: 0; margin-bottom: 6px; color: var(--frnp-navy, #0b2545); font-size: 1.2rem;">
                            Get Notified When Tours Open
                        </h3>
                        <p style="color: #718096; font-size: 0.9rem; margin-top: 0; margin-bottom: 18px;">
                            Sign up to receive an automated notification as soon as the next tour schedule is released.
                        </p>

                        <?php if (!empty($interest_error)): ?>
                            <div class="alert alert-danger" style="background: #fde8e8; color: #9b1c1c; border: 1px solid #f8b4b4; padding: 10px 14px; border-radius: 4px; margin-bottom: 16px; font-size: 0.9rem;">
                                <?= htmlspecialchars($interest_error) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" style="margin: 0;">
                            <input type="hidden" name="action" value="tour_interest_signup">

                            <div class="form-group" style="margin-bottom: 16px;">
                                <label for="tour_full_name" style="display: block; font-weight: 600; color: #2d3748; margin-bottom: 6px; font-size: 0.9rem;">
                                    Full Name <span style="color: #e53e3e;">*</span>
                                </label>
                                <input type="text" 
                                       name="full_name" 
                                       id="tour_full_name" 
                                       class="form-control" 
                                       required 
                                       placeholder="Jane Doe" 
                                       value="<?= isset($_POST['full_name']) && !$interest_success ? htmlspecialchars($_POST['full_name']) : '' ?>"
                                       style="width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 0.95rem;">
                            </div>

                            <div class="form-group" style="margin-bottom: 20px;">
                                <label for="tour_email" style="display: block; font-weight: 600; color: #2d3748; margin-bottom: 6px; font-size: 0.9rem;">
                                    Email Address <span style="color: #e53e3e;">*</span>
                                </label>
                                <input type="email" 
                                       name="email" 
                                       id="tour_email" 
                                       class="form-control" 
                                       required 
                                       placeholder="jane.doe@example.com" 
                                       value="<?= isset($_POST['email']) && !$interest_success ? htmlspecialchars($_POST['email']) : '' ?>"
                                       style="width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 0.95rem;">
                            </div>

                            <button type="submit" 
                                    class="btn btn-primary" 
                                    style="background: var(--frnp-navy, #0b2545); color: #ffffff; border: none; padding: 10px 22px; border-radius: 4px; font-weight: 600; font-size: 0.95rem; cursor: pointer;">
                                Notify Me When Dates Open
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- 3-Column Schedule Grid for real tour dates -->
            <div class="tour-schedule-grid-3col">
                <?php foreach ($tours_by_date as $date_info): ?>
                    <div class="tour-date-card">
                        <div class="tour-date-header">
                            <div class="tour-date-day"><?= htmlspecialchars($date_info['full_date']) ?></div>
                        </div>

                        <div class="tour-card-body">
                            <div class="tour-section-label">Tour Availability</div>

                            <?php foreach ($date_info['sessions'] as $session): ?>
                                <?php
                                    $cap = (int)$session['capacity'];
                                    $confirmed = (int)$session['confirmed_slots'];
                                    $is_full = ($cap > 0 && $confirmed >= $cap);
                                    $seats_left = $cap > 0 ? max(0, $cap - $confirmed) : null;
                                    $has_waitlist = !empty($session['allow_waitlist']);
                                    $time_label = !empty($session['tour_time']) ? $session['tour_time'] : 'Tour Session';
                                    $session_url = base_path($session['slug']);

                                    // Check if this tour form has multiple time slots configured in registration_time_slots
                                    $time_slots = [];
                                    try {
                                        $slots_stmt = $db->prepare("
                                            SELECT ts.*,
                                                   COALESCE(SUM(CASE WHEN r.state = 'complete' THEN r.slots ELSE 0 END), 0) AS confirmed_slots,
                                                   COALESCE(SUM(CASE WHEN r.state = 'waitlist' THEN r.slots ELSE 0 END), 0) AS waitlist_slots
                                            FROM registration_time_slots ts
                                            LEFT JOIN registrations r ON r.slot_id = ts.id
                                            WHERE ts.form_id = ? AND ts.status = 1
                                            GROUP BY ts.id
                                            ORDER BY ts.weight ASC, ts.id ASC
                                        ");
                                        $slots_stmt->execute([$session['id']]);
                                        $time_slots = $slots_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    } catch (Exception $e) {
                                        $time_slots = [];
                                    }
                                ?>

                                <?php if (!empty($time_slots)): ?>
                                    <div class="tour-slots-container" style="display: flex; flex-direction: column; gap: 10px;">
                                        <?php foreach ($time_slots as $slot): 
                                            $slot_cap = (int)$slot['capacity'];
                                            $slot_confirmed = (int)$slot['confirmed_slots'];
                                            $slot_remaining = max(0, $slot_cap - $slot_confirmed);
                                            $slot_is_full = ($slot_cap > 0 && $slot_confirmed >= $slot_cap);
                                            $slot_allow_waitlist = !empty($slot['allow_waitlist']) || !empty($session['allow_waitlist']);
                                            $slot_is_waitlist = ($slot_is_full && $slot_allow_waitlist);
                                            $slot_url = base_path($session['slug']) . '?slot_id=' . (int)$slot['id'];
                                        ?>
                                            <?php if ($slot_is_full && !$slot_allow_waitlist): ?>
                                                <span class="btn-tour-slot-full" title="This tour session is full">
                                                    <span>&#128337; <?= htmlspecialchars($slot['slot_time']) ?></span>
                                                    <span class="full-tag">Tour Full</span>
                                                </span>
                                            <?php else: ?>
                                                <a href="<?= htmlspecialchars($slot_url) ?>" 
                                                   class="btn-tour-slot <?= $slot_is_waitlist ? 'btn-tour-slot-waitlist' : '' ?>" 
                                                   title="Register for <?= htmlspecialchars($slot['slot_time']) ?>">
                                                    <span><?= htmlspecialchars($slot['slot_time']) ?></span>
                                                    <span class="slot-capacity-pill">
                                                        <?php if ($slot_is_full): ?>
                                                            <span class="waitlist-tag">Waitlist</span>
                                                        <?php else: ?>
                                                            <?= $slot_cap > 0 ? $slot_remaining . ' seat' . ($slot_remaining === 1 ? '' : 's') . ' left' : 'Open' ?>
                                                        <?php endif; ?>
                                                    </span>
                                                </a>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <!-- Single Session (Direct Button) -->
                                    <?php if ($is_full): ?>
                                        <?php if ($has_waitlist): ?>
                                            <a href="<?= htmlspecialchars($session_url) ?>" class="btn-tour-slot btn-tour-slot-waitlist" title="Click to join waitlist for <?= htmlspecialchars($time_label) ?>">
                                                <span><?= htmlspecialchars($time_label) ?></span>
                                                <span class="waitlist-tag">Waitlist</span>
                                            </a>
                                        <?php else: ?>
                                            <span class="btn-tour-slot-full" title="This tour session is full">
                                                <span>&#128337; <?= htmlspecialchars($time_label) ?></span>
                                                <span class="full-tag">Tour Full</span>
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <a href="<?= htmlspecialchars($session_url) ?>" class="btn-tour-slot" title="Register for <?= htmlspecialchars($time_label) ?>">
                                            <span><?= htmlspecialchars($time_label) ?></span>
                                            <span class="slot-capacity-pill">
                                                <?= $cap > 0 ? $seats_left . ' seat' . ($seats_left === 1 ? '' : 's') . ' left' : 'Register' ?>
                                            </span>
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</article>
