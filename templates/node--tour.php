<?php
declare(strict_types=1);

/**
 * Node Template: Tour Information & 3-Column Schedule Layout
 * Used for the main /tour or /tours listing page.
 */

$db = get_db();

// 1. Query all active, upcoming tour sessions
$tour_stmt = $db->prepare("
    SELECT f.*, 
           COALESCE(SUM(CASE WHEN r.state = 'complete' THEN r.slots ELSE 0 END), 0) AS confirmed_slots,
           COALESCE(SUM(CASE WHEN r.state = 'waitlist' THEN r.slots ELSE 0 END), 0) AS waitlist_slots
    FROM registration_forms f
    LEFT JOIN registrations r ON r.form_id = f.id
    WHERE f.status = 1 
      AND (f.form_type = 'tour' OR f.tour_date IS NOT NULL OR f.title LIKE '%Tour%')
    GROUP BY f.id
    ORDER BY f.tour_date ASC, f.tour_time ASC
");
$tour_stmt->execute();
$all_tours = $tour_stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Group tours directly by Date (e.g. '2026-04-16')
$tours_by_date = [];
foreach ($all_tours as $t) {
    $ts = !empty($t['tour_date']) ? strtotime($t['tour_date']) : time();
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
    .tour-date-weekday {
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: var(--frnp-gold, #c59b27);
        font-weight: 700;
        margin-bottom: 2px;
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

    /* Full State (Disabled Link) */
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
        padding: 35px 20px;
        text-align: center;
        color: #64748b;
        font-size: 0.95rem;
    }

    .btn-tour-slot.slot-waitlist {
        background: gray;
    }
</style>

<article class="node-page tours-content-wrapper">
    <!-- 1. CMS Node Body Content -->
    <?php if (!empty($node['body'])): ?>
        <div class="node-body tours-body-region">
            <?= check_markup($node['body'], $node['format'] ?? 'filtered_html', ['node' => $node]) ?>
        </div>
    <?php endif; ?>

    <!-- 2. 3-Column Side-by-Side Tour Schedule -->
    <section class="tour-schedule-section">
        <h2 style="color: var(--frnp-navy, #0b2545); font-size: 1.45rem; font-weight: 800; margin-bottom: 6px;">
            Public Tour Schedule
        </h2>
        <p style="color: #64748b; font-size: 0.92rem; margin: 0 0 16px 0;">
            Select an available tour session below to complete your registration and security badging request.
        </p>

        <?php if (empty($tours_by_date)): ?>
            <div class="no-tours-alert">
                <strong style="color: var(--frnp-navy, #0b2545); display: block; margin-bottom: 4px;">No upcoming tours currently scheduled</strong>
                <span>Please check back periodically or contact site communications for future session announcements.</span>
            </div>
        <?php else: ?>
            <div class="tour-schedule-grid-3col">
                <?php foreach ($tours_by_date as $date_info): ?>
                    <div class="tour-date-card">
                        <div class="tour-date-header">
                            <!--<div class="tour-date-weekday"><?= htmlspecialchars($date_info['weekday']) ?></div>-->
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
                                ?>

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
                                    <?php
// 1. Fetch the tour registration form matching this page/node (e.g. slug = 'tours/2026/november')
$db = get_db();
$slug_path = trim($path ?? 'tours/2026/november', '/');

$form_stmt = $db->prepare("
    SELECT * FROM registration_forms 
    WHERE form_type = 'tour' AND status = 1 AND (slug = ? OR slug LIKE ?)
    LIMIT 1
");
$form_stmt->execute([$slug_path, $slug_path . '%']);
$tour_form = $form_stmt->fetch(PDO::FETCH_ASSOC);

$time_slots = [];
if ($tour_form) {
    // 2. Fetch all active time slots with live confirmed attendee counts
    $slots_stmt = $db->prepare("
        SELECT ts.*,
               COALESCE(SUM(CASE WHEN r.state = 'complete' THEN r.slots ELSE 0 END), 0) AS confirmed_slots
        FROM registration_time_slots ts
        LEFT JOIN registrations r ON r.slot_id = ts.id
        WHERE ts.form_id = ? AND ts.status = 1
        GROUP BY ts.id
        ORDER BY ts.weight ASC, ts.id ASC
    ");
    $slots_stmt->execute([$tour_form['id']]);
    $time_slots = $slots_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="tour-slots-container" style="display: flex; flex-direction: column; gap: 12px;">
    <?php if (!empty($time_slots)): ?>
        <?php foreach ($time_slots as $slot): 
            $capacity = (int)$slot['capacity'];
            $confirmed = (int)$slot['confirmed_slots'];
            $waitlist_count = (int)($slot['waitlist_slots'] ?? 0);
            $remaining = max(0, $capacity - $confirmed);
            $is_full = ($capacity > 0 && $confirmed >= $capacity);
            $allow_waitlist = !empty($slot['allow_waitlist']) || !empty($tour_form['allow_waitlist']);
            
            // Check if slot is full and waitlist is active
            $is_waitlist = ($is_full && $allow_waitlist);
            
            // Build the registration link with slot preselection query parameter
            $reg_url = base_path($tour_form['slug'] ?? 'tours') . '?slot_id=' . (int)$slot['id'];
        ?>
            <a href="<?= htmlspecialchars($reg_url) ?>" 
               class="btn-tour-slot <?= $is_full ? ($is_waitlist ? 'slot-waitlist' : 'slot-full') : '' ?>" 
               title="Register for <?= htmlspecialchars($slot['slot_time']) ?>"
               >
                
                <span>
                    <?= htmlspecialchars($slot['slot_time']) ?>
                </span>

                <span class="slot-capacity-pill">
                    <?php if ($is_full): ?>
                        <?php if ($is_waitlist): ?>
                            Waitlist Available
                        <?php else: ?>
                            Fully Booked
                        <?php endif; ?>
                    <?php else: ?>
                        <?= $remaining ?> seat<?= $remaining === 1 ? '' : 's' ?> left
                    <?php endif; ?>
                </span>
            </a>
        <?php endforeach; ?>
    <?php else: ?>
        <p style="color: #64748b; font-style: italic;">No tour time slots currently available.</p>
    <?php endif; ?>
</div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</article>
