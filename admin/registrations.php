<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content.php';
require_once __DIR__ . '/../includes/cache.php';
if (file_exists(__DIR__ . '/../includes/registrations.php')) {
    require_once __DIR__ . '/../includes/registrations.php';
}

require_permission('view_registrations');

$db = get_db();

// Auto-heal columns and tables if not yet present
try {
    $has_unique_col = $db->query("SHOW COLUMNS FROM registration_forms LIKE 'unique_email'")->fetch();
    if (!$has_unique_col) {
        $db->exec("ALTER TABLE registration_forms ADD COLUMN unique_email TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_waitlist");
    }
    $has_form_type_col = $db->query("SHOW COLUMNS FROM registration_forms LIKE 'form_type'")->fetch();
    if (!$has_form_type_col) {
        $db->exec("ALTER TABLE registration_forms ADD COLUMN form_type ENUM('general', 'tour') NOT NULL DEFAULT 'general' AFTER host_type");
    }
    $has_tour_date_col = $db->query("SHOW COLUMNS FROM registration_forms LIKE 'tour_date'")->fetch();
    if (!$has_tour_date_col) {
        $db->exec("ALTER TABLE registration_forms ADD COLUMN tour_date DATE NULL DEFAULT NULL AFTER close_date");
    }
    $has_tour_time_col = $db->query("SHOW COLUMNS FROM registration_forms LIKE 'tour_time'")->fetch();
    if (!$has_tour_time_col) {
        $db->exec("ALTER TABLE registration_forms ADD COLUMN tour_time VARCHAR(60) NULL DEFAULT NULL AFTER tour_date");
    }
    $has_slots_table = $db->query("SHOW TABLES LIKE 'registration_time_slots'")->fetch();
    if (!$has_slots_table) {
        $db->exec("
            CREATE TABLE `registration_time_slots` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `form_id` INT UNSIGNED NOT NULL,
                `slot_time` VARCHAR(60) NOT NULL,
                `capacity` INT UNSIGNED NOT NULL DEFAULT 20,
                `allow_waitlist` TINYINT(1) NOT NULL DEFAULT 1,
                `weight` INT NOT NULL DEFAULT 0,
                `status` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_slot_form` (`form_id`),
                CONSTRAINT `fk_slot_form_rf` FOREIGN KEY (`form_id`) REFERENCES `registration_forms` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    $has_slot_id_col = $db->query("SHOW COLUMNS FROM registrations LIKE 'slot_id'")->fetch();
    if (!$has_slot_id_col) {
        $db->exec("ALTER TABLE registrations ADD COLUMN slot_id INT UNSIGNED NULL DEFAULT NULL AFTER form_id, ADD INDEX idx_reg_slot (slot_id)");
    }
} catch (\Throwable $e) {}

$action = $_REQUEST['action'] ?? 'list';
$active_form_id = isset($_GET['form_id']) ? (int)$_GET['form_id'] : 0;

// Helper to resolve cell content across core columns and submission_data JSON
function get_roster_cell_value(array $reg, string $key): string {
    $sub_data = is_array($reg['submission_data'] ?? null)
        ? $reg['submission_data']
        : (json_decode((string)($reg['submission_data'] ?? '{}'), true) ?: []);

    $val = null;

    if (array_key_exists($key, $reg) && $reg[$key] !== null && $reg[$key] !== '') {
        $val = $reg[$key];
    } elseif (isset($sub_data[$key]) && $sub_data[$key] !== null && $sub_data[$key] !== '') {
        $val = $sub_data[$key];
    } elseif (in_array($key, ['name', 'full_name'], true)) {
        $val = !empty($sub_data['full_name']) ? $sub_data['full_name'] : (!empty($reg['name']) ? $reg['name'] : null);
    } elseif (in_array($key, ['email', 'email_address', 'reg_email'], true)) {
        $val = !empty($sub_data['email_address']) ? $sub_data['email_address'] : (!empty($reg['email']) ? $reg['email'] : null);
    } elseif (in_array($key, ['phone', 'phone_number', 'phone_numbers'], true)) {
        $val = !empty($sub_data['phone_number']) ? $sub_data['phone_number'] : (!empty($sub_data['phone_numbers']) ? $sub_data['phone_numbers'] : (!empty($reg['phone']) ? $reg['phone'] : null));
    } elseif (in_array($key, ['organization', 'org'], true)) {
        $val = !empty($sub_data['organization']) ? $sub_data['organization'] : (!empty($reg['organization']) ? $reg['organization'] : null);
    } elseif (in_array($key, ['slots', 'attendees', 'party_size'], true)) {
        $val = isset($reg['slots']) ? $reg['slots'] : ($sub_data['slots'] ?? null);
    }

    if ($val === null || $val === '') {
        return '<span style="color:#94a3b8;">&mdash;</span>';
    }

    if (is_array($val)) {
        return htmlspecialchars(implode(', ', array_map('strval', $val)));
    }

    $val_str = (string)$val;

    if (filter_var($val_str, FILTER_VALIDATE_EMAIL)) {
        return '<a href="mailto:' . htmlspecialchars($val_str) . '" style="color:#0b2545; text-decoration:underline;">' . htmlspecialchars($val_str) . '</a>';
    }

    if (in_array($key, ['phone', 'phone_number', 'phone_numbers'], true) || preg_match('/^\+?[0-9\(\)\s\-]{7,20}$/', $val_str)) {
        $clean_tel = preg_replace('/[^0-9+]/', '', $val_str);
        return '<a href="tel:' . $clean_tel . '" style="color:#0b2545; text-decoration:none;">' . htmlspecialchars($val_str) . '</a>';
    }

    if (str_starts_with($val_str, 'http://') || str_starts_with($val_str, 'https://') || str_contains($val_str, '/uploads/')) {
        return '<a href="' . htmlspecialchars($val_str) . '" target="_blank" class="btn-download" style="font-size:0.75rem; padding:3px 7px;">View File</a>';
    }

    return htmlspecialchars($val_str);
}

// 1. Handle Form Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_form'])) {
    require_permission('administer_site');
    validate_csrf();

    $id             = (int)($_POST['form_id'] ?? 0);
    $form_type      = in_array($_POST['form_type'] ?? '', ['general', 'tour'], true) ? $_POST['form_type'] : 'general';
    $title          = trim($_POST['title'] ?? '');
    $description    = ($form_type === 'tour') ? '' : trim($_POST['description'] ?? '');
    $host_type      = in_array($_POST['host_type'] ?? '', ['page', 'block'], true) ? $_POST['host_type'] : 'page';
    $raw_slug       = strtolower(trim(preg_replace('/[^a-z0-9_\-\/]/i', '', str_replace(' ', '-', $_POST['slug'] ?? ''))));
    $slug           = ($host_type === 'page' && $raw_slug !== '') ? $raw_slug : null;
    $capacity       = max(0, (int)($_POST['capacity'] ?? 0));
    $allow_waitlist = isset($_POST['allow_waitlist']) ? 1 : 0;
    $unique_email   = isset($_POST['unique_email']) ? 1 : 0;
    $status         = isset($_POST['status']) ? 1 : 0;
    $open_date      = !empty($_POST['open_date']) ? $_POST['open_date'] : null;
    $close_date     = !empty($_POST['close_date']) ? $_POST['close_date'] : null;
    $confirm_msg    = trim($_POST['confirmation_message'] ?? 'Thank you! Your registration has been confirmed.');

    $tour_date      = ($form_type === 'tour' && !empty($_POST['tour_date'])) ? $_POST['tour_date'] : null;
    $tour_time      = null;

    $slot_times      = $_POST['slot_times'] ?? [];
    $slot_capacities = $_POST['slot_capacities'] ?? [];
    $slot_waitlists  = $_POST['slot_waitlists'] ?? [];

    if ($form_type === 'tour' && !empty($slot_times)) {
        $total_tour_capacity = 0;
        $valid_times = [];
        foreach ($slot_times as $idx => $stime) {
            $stime = trim((string)$stime);
            if ($stime !== '') {
                $scap = max(1, (int)($slot_capacities[$idx] ?? 20));
                $total_tour_capacity += $scap;
                $valid_times[] = $stime;
            }
        }
        $capacity = $total_tour_capacity;
        if (!empty($valid_times)) {
            $tour_time = implode(', ', $valid_times);
        }
    }

    $raw_schema     = $_POST['fields_schema'] ?? '[]';
    $fields_schema  = is_string($raw_schema) ? json_decode($raw_schema, true) : $raw_schema;
    if (!is_array($fields_schema)) {
        $fields_schema = [];
    }

    if ($title === '') {
        set_flash('error', 'Form title cannot be blank.');
    } elseif ($host_type === 'page' && empty($slug)) {
        set_flash('error', 'A URL Slug is required when hosting target is set to Page.');
    } else {
        if ($id > 0) {
            $stmt = $db->prepare('
                UPDATE registration_forms 
                SET form_type = :form_type, title = :title, description = :description, host_type = :host_type, slug = :slug,
                    capacity = :capacity, allow_waitlist = :allow_waitlist, unique_email = :unique_email,
                    status = :status, open_date = :open_date, close_date = :close_date, 
                    tour_date = :tour_date, tour_time = :tour_time,
                    confirmation_message = :confirm_msg, fields_schema = :schema
                WHERE id = :id
            ');
            $stmt->execute([
                'form_type'      => $form_type,
                'title'          => $title,
                'description'    => $description,
                'host_type'      => $host_type,
                'slug'           => $slug,
                'capacity'       => $capacity,
                'allow_waitlist' => $allow_waitlist,
                'unique_email'   => $unique_email,
                'status'         => $status,
                'open_date'      => $open_date,
                'close_date'     => $close_date,
                'tour_date'      => $tour_date,
                'tour_time'      => $tour_time,
                'confirm_msg'    => $confirm_msg,
                'schema'         => json_encode($fields_schema),
                'id'             => $id,
            ]);
            $saved_form_id = $id;
            set_flash('success', ($form_type === 'tour' ? 'Tour session' : 'Registration form') . " '{$title}' updated successfully.");
            watchdog('registrations', "Updated {$form_type} form '{$title}' (ID #{$id}).", 'notice');
        } else {
            $stmt = $db->prepare('
                INSERT INTO registration_forms 
                (form_type, title, description, host_type, slug, capacity, allow_waitlist, unique_email, status, open_date, close_date, tour_date, tour_time, confirmation_message, fields_schema)
                VALUES 
                (:form_type, :title, :description, :host_type, :slug, :capacity, :allow_waitlist, :unique_email, :status, :open_date, :close_date, :tour_date, :tour_time, :confirm_msg, :schema)
            ');
            $stmt->execute([
                'form_type'      => $form_type,
                'title'          => $title,
                'description'    => $description,
                'host_type'      => $host_type,
                'slug'           => $slug,
                'capacity'       => $capacity,
                'allow_waitlist' => $allow_waitlist,
                'unique_email'   => $unique_email,
                'status'         => $status,
                'open_date'      => $open_date,
                'close_date'     => $close_date,
                'tour_date'      => $tour_date,
                'tour_time'      => $tour_time,
                'confirm_msg'    => $confirm_msg,
                'schema'         => json_encode($fields_schema),
            ]);
            $saved_form_id = (int)$db->lastInsertId();
            set_flash('success', ($form_type === 'tour' ? 'Tour session' : 'Registration form') . " '{$title}' created.");
            watchdog('registrations', "Created {$form_type} form '{$title}' (ID #{$saved_form_id}).", 'notice');
        }

        if ($form_type === 'tour' && $saved_form_id > 0) {
            $existing_slots = $db->query("SELECT id, slot_time FROM registration_time_slots WHERE form_id = {$saved_form_id}")->fetchAll(PDO::FETCH_KEY_PAIR);
            $processed_slot_ids = [];

            $ins_slot = $db->prepare("INSERT INTO registration_time_slots (form_id, slot_time, capacity, allow_waitlist, weight) VALUES (?, ?, ?, ?, ?)");
            $upd_slot = $db->prepare("UPDATE registration_time_slots SET slot_time = ?, capacity = ?, allow_waitlist = ?, weight = ? WHERE id = ? AND form_id = ?");

            foreach ($slot_times as $idx => $time_val) {
                $time_clean = trim((string)$time_val);
                if ($time_clean === '') continue;

                $cap_clean = max(1, (int)($slot_capacities[$idx] ?? 20));
                $wait_clean = isset($slot_waitlists[$idx]) ? 1 : 0;
                $weight = $idx * 10;

                $existing_id = array_search($time_clean, $existing_slots, true);
                if ($existing_id !== false) {
                    $upd_slot->execute([$time_clean, $cap_clean, $wait_clean, $weight, $existing_id, $saved_form_id]);
                    $processed_slot_ids[] = (int)$existing_id;
                } else {
                    $ins_slot->execute([$saved_form_id, $time_clean, $cap_clean, $wait_clean, $weight]);
                    $processed_slot_ids[] = (int)$db->lastInsertId();
                }
            }

            if (!empty($processed_slot_ids)) {
                $id_placeholders = implode(',', array_map('intval', $processed_slot_ids));
                $db->exec("
                    DELETE FROM registration_time_slots 
                    WHERE form_id = {$saved_form_id} 
                      AND id NOT IN ({$id_placeholders})
                      AND NOT EXISTS (SELECT 1 FROM registrations r WHERE r.slot_id = registration_time_slots.id)
                ");
            }
        }

        cache_clear();
        header('Location: ' . base_path('admin/registrations.php'));
        exit;
    }
}

// 2. Handle Form Status Toggle
if ($action === 'toggle_form' && isset($_GET['id'])) {
    require_permission('administer_site');
    validate_csrf();
    $form_toggle_id = (int)$_GET['id'];
    $stmt = $db->prepare('UPDATE registration_forms SET status = IF(status = 1, 0, 1) WHERE id = :id');
    $stmt->execute(['id' => $form_toggle_id]);
    cache_clear();
    set_flash('success', 'Form status updated.');
    header('Location: ' . base_path('admin/registrations.php'));
    exit;
}

// 3. Handle Form Deletion
if ($action === 'delete_form' && isset($_GET['id'])) {
    require_permission('administer_site');
    validate_csrf();
    $form_del_id = (int)$_GET['id'];
    $stmt = $db->prepare('DELETE FROM registration_forms WHERE id = :id');
    $stmt->execute(['id' => $form_del_id]);
    $db->prepare('DELETE FROM registration_time_slots WHERE form_id = :id')->execute(['id' => $form_del_id]);
    $db->prepare('DELETE FROM registrations WHERE form_id = :id')->execute(['id' => $form_del_id]);
    cache_clear();
    watchdog('registrations', "Deleted registration form #{$form_del_id} and all attendee submissions.", 'notice');
    set_flash('success', 'Registration form and its submissions deleted.');
    header('Location: ' . base_path('admin/registrations.php'));
    exit;
}

// 4. Handle Registration Deletion (Attendee Submission - Supports both POST and GET)
if ($action === 'delete_registration') {
    require_permission('delete_registrations');
    validate_csrf();

    $reg_id = (int)($_POST['reg_id'] ?? $_GET['reg_id'] ?? $_REQUEST['id'] ?? 0);
    $target_form_id = (int)($_POST['form_id'] ?? $_GET['form_id'] ?? $active_form_id);

    if ($reg_id > 0) {
        try {
            $stmt = $db->prepare('DELETE FROM registrations WHERE id = :id');
            $stmt->execute(['id' => $reg_id]);

            if ($stmt->rowCount() > 0) {
                if (function_exists('watchdog')) {
                    watchdog('registrations', "Deleted registration submission #{$reg_id}.", 'notice');
                }
                set_flash('success', "Attendee submission #{$reg_id} deleted successfully.");
            } else {
                set_flash('error', "Submission #{$reg_id} was not found or has already been deleted.");
            }
        } catch (\PDOException $e) {
            set_flash('error', 'Database error deleting registration: ' . $e->getMessage());
        }
    } else {
        $keys = htmlspecialchars(implode(', ', array_keys($_REQUEST)));
        set_flash('error', "Invalid registration ID provided for deletion. (Received keys: [{$keys}])");
    }

    $redirect_url = 'admin/registrations.php' . ($target_form_id > 0 ? '?form_id=' . $target_form_id : '');
    header('Location: ' . base_path($redirect_url));
    exit;
}

// 5. Handle Status / State Toggle (complete, waitlist, canceled)
if ($action === 'update_state' && isset($_GET['reg_id']) && isset($_GET['state'])) {
    require_permission('edit_registrations');
    validate_csrf();
    $reg_id = (int)$_GET['reg_id'];
    $new_state = $_GET['state'];
    if (in_array($new_state, ['complete', 'waitlist', 'canceled', 'pending'], true)) {
        $stmt = $db->prepare('UPDATE registrations SET state = :st WHERE id = :id');
        $stmt->execute(['st' => $new_state, 'id' => $reg_id]);
        set_flash('success', "Registration #{$reg_id} status updated to " . ucfirst($new_state) . '.');
    }
    header('Location: ' . base_path('admin/registrations.php?form_id=' . $active_form_id));
    exit;
}

// 6. Fetch Active Form Details if selected
$active_form = null;
$active_form_slots = [];
if ($active_form_id > 0) {
    $stmt = $db->prepare('SELECT * FROM registration_forms WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $active_form_id]);
    $active_form = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($active_form) {
        $slots_stmt = $db->prepare("
            SELECT ts.*,
                   COALESCE(SUM(CASE WHEN r.state = 'complete' THEN r.slots ELSE 0 END), 0) AS confirmed_slots,
                   COALESCE(SUM(CASE WHEN r.state = 'waitlist' THEN r.slots ELSE 0 END), 0) AS waitlist_slots
            FROM registration_time_slots ts
            LEFT JOIN registrations r ON r.slot_id = ts.id
            WHERE ts.form_id = :fid
            GROUP BY ts.id
            ORDER BY ts.weight ASC, ts.id ASC
        ");
        $slots_stmt->execute(['fid' => $active_form_id]);
        $active_form_slots = $slots_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// 7. Handle CSV Export
if ($action === 'export' && $active_form) {
    validate_csrf();
    $stmt = $db->prepare('
        SELECT r.*, ts.slot_time 
        FROM registrations r 
        LEFT JOIN registration_time_slots ts ON ts.id = r.slot_id
        WHERE r.form_id = :fid 
        ORDER BY r.created_at ASC
    ');
    $stmt->execute(['fid' => $active_form_id]);
    $export_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $export_cols = [];
    $schema = json_decode((string)($active_form['fields_schema'] ?? '[]'), true) ?: [];

    $has_email_in_schema = false;
    foreach ($schema as $f) {
        $fn = trim($f['name'] ?? '');
        if (in_array($fn, ['email', 'reg_email', 'email_address'], true)) {
            $has_email_in_schema = true;
            break;
        }
    }
    if (!$has_email_in_schema) {
        $export_cols['email'] = 'Email Address';
    }

    if (($active_form['form_type'] ?? 'general') === 'tour') {
        $export_cols['slot_time'] = 'Tour Time Slot';
    }

    foreach ($schema as $f) {
        $fn = trim($f['name'] ?? '');
        if ($fn !== '') {
            $export_cols[$fn] = !empty($f['label']) ? $f['label'] : ucwords(str_replace(['_', '-'], ' ', $fn));
        }
    }

    foreach ($export_rows as $er) {
        $sd = json_decode((string)($er['submission_data'] ?? '{}'), true) ?: [];
        foreach (array_keys($sd) as $sdk) {
            if (!isset($export_cols[$sdk])) {
                $export_cols[$sdk] = ucwords(str_replace(['_', '-'], ' ', (string)$sdk));
            }
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="roster-' . preg_replace('/[^a-z0-9]/i', '-', $active_form['title']) . '-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, array_merge(['Confirmation #'], array_values($export_cols), ['Status', 'Registered Date']));

    foreach ($export_rows as $er) {
        $row_data = [sprintf('%00d', $er['id'])];
        foreach (array_keys($export_cols) as $ck) {
            if ($ck === 'slot_time') {
                $clean_val = !empty($er['slot_time']) ? $er['slot_time'] : 'Standard';
            } else {
                $clean_val = strip_tags(get_roster_cell_value($er, $ck));
            }
            $row_data[] = ($clean_val === '—') ? '' : $clean_val;
        }
        $row_data[] = ucfirst((string)$er['state']);
        $row_data[] = date('Y-m-d H:i', strtotime($er['created_at']));
        fputcsv($out, $row_data);
    }
    fclose($out);
    exit;
}

// 8. Query Registrations for Roster View
$registrations = [];
$roster_columns = [];

if ($active_form) {
    $filter_state = trim($_GET['state'] ?? '');
    $filter_slot  = isset($_GET['slot_id']) ? (int)$_GET['slot_id'] : 0;

    $sql = '
        SELECT r.id AS reg_id, r.*, ts.slot_time 
        FROM registrations r 
        LEFT JOIN registration_time_slots ts ON ts.id = r.slot_id
        WHERE r.form_id = :fid
    ';
    $params = ['fid' => $active_form_id];

    if ($filter_state !== '') {
        $sql .= ' AND r.state = :st';
        $params['st'] = $filter_state;
    }
    if ($filter_slot > 0) {
        $sql .= ' AND r.slot_id = :slot_id';
        $params['slot_id'] = $filter_slot;
    }
    $sql .= ' ORDER BY r.created_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $schema = json_decode((string)($active_form['fields_schema'] ?? '[]'), true) ?: [];

    $has_email_in_schema = false;
    foreach ($schema as $f) {
        $fname = trim($f['name'] ?? '');
        if (in_array($fname, ['email', 'reg_email', 'email_address'], true)) {
            $has_email_in_schema = true;
            break;
        }
    }
    if (!$has_email_in_schema) {
        $roster_columns['email'] = [
            'key'   => 'email',
            'label' => 'Email Address',
            'type'  => 'email'
        ];
    }

    if (($active_form['form_type'] ?? 'general') === 'tour') {
        $roster_columns['slot_time'] = [
            'key'   => 'slot_time',
            'label' => 'Time Slot',
            'type'  => 'text'
        ];
    }

    foreach ($schema as $f) {
        $fname = trim($f['name'] ?? '');
        if ($fname === '') continue;
        $roster_columns[$fname] = [
            'key'   => $fname,
            'label' => !empty($f['label']) ? $f['label'] : ucwords(str_replace(['_', '-'], ' ', $fname)),
            'type'  => $f['type'] ?? 'text'
        ];
    }

    foreach ($registrations as $r_row) {
        $sd = json_decode((string)($r_row['submission_data'] ?? '{}'), true) ?: [];
        foreach (array_keys($sd) as $sdk) {
            $sdk_str = (string)$sdk;
            if (!isset($roster_columns[$sdk_str])) {
                $roster_columns[$sdk_str] = [
                    'key'   => $sdk_str,
                    'label' => ucwords(str_replace(['_', '-'], ' ', $sdk_str)),
                    'type'  => 'text'
                ];
            }
        }
    }
} else {
    $all_forms = $db->query("
        SELECT f.*, 
               COUNT(r.id) AS total_count,
               COALESCE(SUM(CASE WHEN r.state = 'complete' THEN r.slots ELSE 0 END), 0) AS confirmed_slots,
               COALESCE(SUM(CASE WHEN r.state = 'waitlist' THEN r.slots ELSE 0 END), 0) AS waitlist_slots
        FROM registration_forms f
        LEFT JOIN registrations r ON r.form_id = f.id
        GROUP BY f.id
        ORDER BY f.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $all_form_slots = [];
    $raw_slots = $db->query("SELECT * FROM registration_time_slots ORDER BY weight ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($raw_slots as $slot_item) {
        $all_form_slots[$slot_item['form_id']][] = $slot_item;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $active_form ? htmlspecialchars($active_form['title']) . ' Roster' : 'Registration Forms' ?> - FRNP CMS</title>
    <link rel="stylesheet" href="<?= base_path('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Trumbowyg/2.27.3/ui/trumbowyg.min.css">
    <style>
        .trumbowyg-box { margin-top: 5px; margin-bottom: 0; border: 1px solid #cbd5e1; border-radius: 4px; }
        .trumbowyg-editor { min-height: 140px; font-size: 0.92rem; font-family: inherit; }
        
        .stat-banner {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 16px 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .stat-val {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--frnp-navy, #0b2545);
            line-height: 1.1;
        }
        .stat-lbl {
            font-size: 0.8rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-top: 4px;
            font-weight: 600;
        }

        .status-full {
            background: #fef3c7 !important;
            color: #92400e !important;
            border: 1px solid #fde68a !important;
        }
        .badge-tour {
            background: #0b2545 !important;
            color: #ffffff !important;
            font-weight: 700;
        }
        .badge-general {
            background: #e2e8f0 !important;
            color: #334155 !important;
            font-weight: 600;
        }

        .roster-table-wrap {
            width: 100%;
            overflow-x: auto;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: #ffffff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .roster-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
            white-space: nowrap;
        }
        .roster-table th {
            background: #f8fafc;
            color: #0b2545;
            font-weight: 700;
            padding: 11px 14px;
            border-bottom: 2px solid #cbd5e1;
            text-align: left;
            font-size: 0.84rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .roster-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            vertical-align: middle;
        }
        .roster-table tr:hover td {
            background: #f8fafc;
        }
        .state-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .state-complete { background: #dcfce7; color: #15803d; }
        .state-waitlist { background: #fef9c3; color: #854d0e; }
        .state-canceled { background: #fee2e2; color: #b91c1c; }
        .state-pending  { background: #e0f2fe; color: #0369a1; }
        .filter-pill {
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            color: #475569;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
        }
        .filter-pill.active {
            background: #0b2545;
            color: #ffffff;
            border-color: #0b2545;
        }
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
            width: 780px;
            max-width: 95vw;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 8px;
            padding: 24px 28px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        .schema-field-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            margin-bottom: 6px;
        }
        .slot-row-item {
            display: flex;
            gap: 12px;
            align-items: center;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 8px 12px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-nav">
            <?php require __DIR__ . '/nav.php'; ?>
        </aside>

        <main class="admin-main" style="max-width: 1400px;">
            <?php if ($active_form): ?>
                <!-- 1. ROSTER VIEW -->
                <div style="font-size: 0.84rem; color: #64748b; margin-bottom: 8px;">
                    <a href="<?= base_path('admin/registrations.php') ?>" style="color: #0b2545; text-decoration: none;">&laquo; All Registration Forms</a> &raquo;
                    <span><?= htmlspecialchars($active_form['title']) ?></span>
                </div>

                <!-- ROSTER HEADER PANEL -->
<div class="roster-header-card" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
        
        <!-- Left Column: Title & Metadata Badges -->
        <div style="flex: 1; min-width: 320px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px; flex-wrap: wrap;">
                <h1 style="margin: 0; font-size: 1.6rem; font-weight: 800; color: #0b2545; letter-spacing: -0.02em;">
                    <?= htmlspecialchars($active_form['title']) ?> Tour &mdash; Attendee Roster
                </h1>
                <?php if (($active_form['form_type'] ?? 'general') === 'tour'): ?>
                    <span class="badge" style="background: #0b2545; color: #ffffff; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.05em; padding: 3px 8px; border-radius: 4px;">SITE TOUR</span>
                <?php endif; ?>
            </div>

            <!-- Meta Information Chips -->
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 10px;">
                <?php if (!empty($active_form['tour_date'])): ?>
                    <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 0.8rem; font-weight: 600; color: #0b2545; background: #eff6ff; border: 1px solid #bfdbfe; padding: 3px 10px; border-radius: 5px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        <?= date('l, F j, Y', strtotime($active_form['tour_date'])) ?>
                    </span>
                <?php endif; ?>

                <?php if ($active_form['host_type'] === 'page'): ?>
                    <a href="<?= base_path($active_form['slug']) ?>" target="_blank" style="display: inline-flex; align-items: center; gap: 5px; font-size: 0.8rem; font-family: monospace; font-weight: 600; color: #334155; background: #f8fafc; border: 1px solid #cbd5e1; padding: 3px 9px; border-radius: 5px; text-decoration: none;" title="Open Public Page">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                        /<?= htmlspecialchars($active_form['slug']) ?>
                    </a>
                <?php else: ?>
                    <span style="font-size: 0.8rem; font-weight: 600; color: #64748b; background: #f1f5f9; padding: 3px 8px; border-radius: 4px;">Target: Widget Block</span>
                <?php endif; ?>

                <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 0.8rem; color: #475569; background: #f1f5f9; border: 1px solid #e2e8f0; padding: 3px 9px; border-radius: 5px;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    Capacity: <strong><?= (int)$active_form['capacity'] > 0 ? (int)$active_form['capacity'] . ' seats' : 'Unlimited' ?></strong>
                </span>

                <?php if (!empty($active_form['unique_email'])): ?>
                    <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.78rem; font-weight: 600; color: #0284c7; background: #e0f2fe; padding: 3px 9px; border-radius: 5px;">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        1 Reg/Email
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right Column: Actions -->
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <button type="button" class="btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; padding: 7px 13px; font-size: 0.84rem; font-weight: 600; border-radius: 5px; cursor: pointer;" onclick='openFormModal(<?= json_encode($active_form, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, "<?= $active_form['form_type'] ?>", <?= json_encode($active_form_slots, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                Edit Settings
            </button>

            <a href="<?= base_path('admin/registrations.php?action=export&form_id=' . $active_form['id'] . '&csrf_token=' . get_csrf_token()) ?>" class="btn-primary" style="display: inline-flex; align-items: center; gap: 6px; padding: 5px 15px; font-size: 0.84rem; font-weight: 600; border-radius: 5px; text-decoration: none;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                Export CSV
            </a>
        </div>

    </div>
</div>

                <?php render_flash_messages(); ?>

                <!-- STAT BANNER WITH FULL CAPACITY RECOGNITION -->
                <?php 
                    $stat = function_exists('get_form_attendee_counts') 
                        ? get_form_attendee_counts((int)$active_form['id'])
                        : [
                            'confirmed' => (int)$db->query("SELECT COALESCE(SUM(slots), 0) FROM registrations WHERE form_id = " . (int)$active_form['id'] . " AND state = 'complete'")->fetchColumn(),
                            'waitlist'  => (int)$db->query("SELECT COALESCE(SUM(slots), 0) FROM registrations WHERE form_id = " . (int)$active_form['id'] . " AND state = 'waitlist'")->fetchColumn(),
                            'total'     => (int)$db->query("SELECT COUNT(*) FROM registrations WHERE form_id = " . (int)$active_form['id'])->fetchColumn(),
                        ];
                    $cap = (int)$active_form['capacity'];
                    $is_cap_reached = ($cap > 0 && $stat['confirmed'] >= $cap);
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
                            <?php if (!$active_form['status']): ?>
                                <span class="badge-status status-archived" style="font-size:0.85rem;">CLOSED</span>
                            <?php elseif ($is_cap_reached): ?>
                                <span class="badge-status status-full" style="font-size:0.85rem;">FULL</span>
                            <?php else: ?>
                                <span class="badge-status status-published" style="font-size:0.85rem;">OPEN</span>
                            <?php endif; ?>
                        </div>
                        <div class="stat-lbl">Form State</div>
                    </div>
                </div>

                <!-- Multiple Slot Breakdown Cards for Tours -->
                <?php if (($active_form['form_type'] ?? 'general') === 'tour' && !empty($active_form_slots)): ?>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 20px;">
                        <?php foreach ($active_form_slots as $slot): 
                            $scap = (int)$slot['capacity'];
                            $sconf = (int)$slot['confirmed_slots'];
                            $srem = max(0, $scap - $sconf);
                            $s_full = ($scap > 0 && $sconf >= $scap);
                        ?>
                            <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:6px; padding:12px 16px; border-left:4px solid <?= $s_full ? '#f59e0b' : '#0b2545' ?>;">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <strong style="color:#0b2545; font-size:1rem;"><?= htmlspecialchars($slot['slot_time']) ?></strong>
                                    <?php if ($s_full): ?>
                                        <span class="badge status-full" style="font-size:0.7rem; padding:2px 6px;">FULL</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#ecfdf5; color:#065f46; font-size:0.7rem; padding:2px 6px;"><?= $srem ?> seats left</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:0.82rem; color:#64748b; margin-top:4px;">
                                    Booked: <strong><?= $sconf ?></strong> / <?= $scap ?>
                                    <?php if ((int)$slot['waitlist_slots'] > 0): ?>
                                        &bull; Waitlist: <strong><?= (int)$slot['waitlist_slots'] ?></strong>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Filter Status & Slot Pills -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <span style="font-size: 0.84rem; font-weight: 600; color: #64748b; margin-right: 4px;">Filter Status:</span>
                        <?php
                            $cur_st = $_GET['state'] ?? '';
                            $cur_slot = isset($_GET['slot_id']) ? (int)$_GET['slot_id'] : 0;
                            $st_link = fn($st) => base_path('admin/registrations.php?form_id=' . $active_form['id'] . ($st !== '' ? '&state=' . $st : '') . ($cur_slot > 0 ? '&slot_id=' . $cur_slot : ''));
                        ?>
                        <a href="<?= $st_link('') ?>" class="filter-pill <?= $cur_st === '' ? 'active' : '' ?>">All (<?= count($registrations) ?>)</a>
                        <a href="<?= $st_link('complete') ?>" class="filter-pill <?= $cur_st === 'complete' ? 'active' : '' ?>">Complete</a>
                        <a href="<?= $st_link('waitlist') ?>" class="filter-pill <?= $cur_st === 'waitlist' ? 'active' : '' ?>">Waitlist</a>
                        <a href="<?= $st_link('canceled') ?>" class="filter-pill <?= $cur_st === 'canceled' ? 'active' : '' ?>">Canceled</a>
                    </div>

                    <?php if (($active_form['form_type'] ?? 'general') === 'tour' && !empty($active_form_slots)): ?>
                        <div style="display: flex; gap: 6px; align-items: center;">
                           
                            <?php $slot_link = fn($sid) => base_path('admin/registrations.php?form_id=' . $active_form['id'] . ($cur_st !== '' ? '&state=' . $cur_st : '') . ($sid > 0 ? '&slot_id=' . $sid : '')); ?>
                            <a href="<?= $slot_link(0) ?>" class="filter-pill <?= $cur_slot === 0 ? 'active' : '' ?>">All Times</a>
                            <?php foreach ($active_form_slots as $aslot): ?>
                                <a href="<?= $slot_link((int)$aslot['id']) ?>" class="filter-pill <?= $cur_slot === (int)$aslot['id'] ? 'active' : '' ?>">
                                    <?= htmlspecialchars($aslot['slot_time']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- INDIVIDUAL FIELD ROSTER TABLE -->
                <div class="roster-table-wrap">
                    <table class="roster-table">
                        <thead>
                            <tr>
                                <th style="width: 80px;">ID</th>
                                <?php foreach ($roster_columns as $col): ?>
                                    <th><?= htmlspecialchars($col['label']) ?></th>
                                <?php endforeach; ?>
                                <th style="text-align: center;">Status</th>
                                <th>Submitted Date</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($registrations)): ?>
                                <tr>
                                    <td colspan="<?= count($roster_columns) + 4 ?>" style="text-align: center; color: #94a3b8; padding: 40px; font-style: italic;">
                                        No registration submissions recorded for this view.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($registrations as $r): ?>
                                    <?php 
                                        $submission_id = (int)($r['reg_id'] ?? $r['id'] ?? 0); 
                                    ?>
                                    <tr>
                                        <td style="font-family: monospace; font-weight: 700; color: #0b2545;">
                                            <?= sprintf('%00d', $submission_id) ?>
                                        </td>

                                        <?php foreach ($roster_columns as $ck => $col): ?>
                                            <td>
                                                <?php if ($ck === 'slot_time'): ?>
                                                    <strong><?= !empty($r['slot_time']) ? htmlspecialchars($r['slot_time']) : '<span style="color:#94a3b8;">General</span>' ?></strong>
                                                <?php else: ?>
                                                    <?= get_roster_cell_value($r, $ck) ?>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>

                                        <td style="text-align: center;">
                                            <span class="state-badge state-<?= htmlspecialchars($r['state'] ?? 'complete') ?>">
                                                <?= ucfirst(htmlspecialchars($r['state'] ?? 'complete')) ?>
                                            </span>
                                        </td>

                                        <td style="font-size: 0.82rem; color: #64748b;">
                                            <?= date('M j, Y g:i A', strtotime($r['created_at'])) ?>
                                        </td>

                                        <!-- Actions -->
                                        <td style="text-align: right; white-space: nowrap;">
                                            <select onchange="if(this.value) location.href=this.value;" style="font-size: 0.78rem; padding: 3px 6px; border: 1px solid #cbd5e1; border-radius: 4px; vertical-align: middle;">
                                                <option value="">Change Status...</option>
                                                <?php foreach (['complete' => 'Confirm / Complete', 'waitlist' => 'Move to Waitlist', 'canceled' => 'Cancel'] as $sk => $sl): ?>
                                                    <?php if ($sk !== $r['state']): ?>
                                                        <option value="<?= base_path('admin/registrations.php?action=update_state&form_id=' . (int)$active_form['id'] . '&reg_id=' . $submission_id . '&state=' . $sk . '&csrf_token=' . get_csrf_token()) ?>">
                                                            &raquo; <?= $sl ?>
                                                        </option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>

                                            <?php if (user_has_permission('delete_registrations')): ?>
                                                <!-- Reliable POST Form Deletion with Fallback GET -->
                                                <form method="POST" action="<?= base_path('admin/registrations.php') ?>" style="display: inline-block; margin: 0; padding: 0; vertical-align: middle;" onsubmit="return confirm('Permanently delete attendee registration #<?= $submission_id ?>?');">
                                                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                                    <input type="hidden" name="action" value="delete_registration">
                                                    <input type="hidden" name="form_id" value="<?= (int)$active_form['id'] ?>">
                                                    <input type="hidden" name="reg_id" value="<?= $submission_id ?>">
                                                    <button type="submit" style="background: none; border: none; padding: 0; margin-left: 8px; color: #dc2626; font-size: 0.8rem; text-decoration: underline; cursor: pointer;">
                                                        Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>
                <!-- 2. FORMS OVERVIEW & MANAGEMENT -->
                <div class="page-header-actions" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h1 style="margin: 0 0 4px 0;">Structure &raquo; Registration Forms</h1>
                        <p style="color: #64748b; font-size: 0.88rem; margin: 0;">
                            Create event registrations and site tours, set capacities, manage dynamic fields, and review attendees.
                        </p>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn-primary" onclick="openFormModal(null, 'tour')" style="background: #0b2545;">
                            + Add New Tour
                        </button>
                        <button type="button" class="btn-secondary" onclick="openFormModal(null, 'general')">
                            + Add New Form
                        </button>
                    </div>
                </div>

                <?php render_flash_messages(); ?>

                <section class="card">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="width: 28%;">Form Title & Type</th>
                                <th style="width: 18%;">Host Target</th>
                                <th style="width: 14%; text-align: center;">Capacity / Slots</th>
                                <th style="width: 16%; text-align: center;">Attendees</th>
                                <th style="width: 10%; text-align: center;">Status</th>
                                <th style="width: 14%; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($all_forms)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: #94a3b8; padding: 35px; font-style: italic;">
                                        No registration forms created yet. Click "+ Add New Tour" or "+ Add New Form" to get started.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_forms as $f): ?>
                                    <?php 
                                        $f_type = $f['form_type'] ?? 'general';
                                        $f_cap = (int)$f['capacity'];
                                        $f_confirmed = (int)$f['confirmed_slots'];
                                        $f_is_full = ($f_cap > 0 && $f_confirmed >= $f_cap);
                                        $f_slots = $all_form_slots[$f['id']] ?? [];
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 6px;">
                                                <strong style="color: #0b2545; font-size: 0.95rem;">
                                                    <a href="<?= base_path('admin/registrations.php?form_id=' . (int)$f['id']) ?>" style="text-decoration: none; color: #0b2545;">
                                                        <?= htmlspecialchars($f['title']) ?> &raquo;
                                                    </a>
                                                </strong>
                                                <span class="badge <?= $f_type === 'tour' ? 'badge-tour' : 'badge-general' ?>" style="font-size:0.68rem; padding: 2px 6px;">
                                                    <?= strtoupper($f_type) ?>
                                                </span>
                                            </div>
                                            <?php if ($f_type === 'tour' && !empty($f['tour_date'])): ?>
                                                <div style="font-size:0.8rem; color:#64748b; margin-top:3px;">
                                                    <?= date('F j, Y', strtotime($f['tour_date'])) ?>
                                                    <?php if (!empty($f_slots)): ?>
                                                        &bull; <?= count($f_slots) ?> time slot<?= count($f_slots) === 1 ? '' : 's' ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($f['host_type'] === 'page'): ?>
                                                <span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700;">PAGE</span>
                                                <div style="margin-top: 3px;">
                                                    <a href="<?= base_path($f['slug']) ?>" target="_blank" style="font-size: 0.82rem; font-family: monospace;">/<?= htmlspecialchars($f['slug']) ?></a>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge" style="background:#fef3c7; color:#92400e; font-weight:700;">BLOCK</span>
                                                <div style="margin-top: 3px;">
                                                    <a href="<?= base_path('admin/blocks.php') ?>" style="font-size: 0.8rem;">In Blocks &raquo;</a>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center; font-size: 0.88rem;">
                                            <div><?= (int)$f['capacity'] > 0 ? (int)$f['capacity'] : '<span style="color:#64748b;">Unlimited</span>' ?></div>
                                            <?php if (!empty($f_slots)): ?>
                                                <div style="font-size:0.75rem; color:#0b2545; margin-top:2px;">
                                                    <?php 
                                                        $time_labels = array_map(fn($s) => htmlspecialchars($s['slot_time']) . " ({$s['capacity']})", $f_slots);
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <span style="font-weight: 700; color: #059669; font-size: 0.95rem;">
                                                <?= (int)$f['confirmed_slots'] ?>
                                            </span> confirmed
                                            <?php if ((int)$f['waitlist_slots'] > 0): ?>
                                                <span style="color: #d97706; font-size: 0.8rem; display: block;">
                                                    (<?= (int)$f['waitlist_slots'] ?> waitlisted)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <a href="<?= base_path('admin/registrations.php?action=toggle_form&id=' . (int)$f['id'] . '&csrf_token=' . get_csrf_token()) ?>" style="text-decoration: none;">
                                                <?php if (!$f['status']): ?>
                                                    <span class="badge-status status-archived">Closed</span>
                                                <?php elseif ($f_is_full): ?>
                                                    <span class="badge-status status-full">Full</span>
                                                <?php else: ?>
                                                    <span class="badge-status status-published">Active</span>
                                                <?php endif; ?>
                                            </a>
                                        </td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <a href="<?= base_path('admin/registrations.php?form_id=' . (int)$f['id']) ?>" class="btn-primary" style="font-size: 0.8rem; padding: 4px 10px; text-decoration: none;">
                                                Roster
                                            </a>
                                            <button type="button" class="btn-secondary" style="font-size: 0.8rem; padding: 4px 8px; margin-left: 4px;" onclick='openFormModal(<?= json_encode($f, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, "<?= $f_type ?>", <?= json_encode($f_slots, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                                Edit
                                            </button>
                                            <a href="<?= base_path('admin/registrations.php?action=delete_form&id=' . (int)$f['id'] . '&csrf_token=' . get_csrf_token()) ?>" 
                                               onclick="return confirm('Permanently delete this entire registration form and all its submissions?');" 
                                               style="color: #dc2626; font-size: 0.8rem; margin-left: 8px; text-decoration: underline; cursor: pointer;">
                                                Delete Form
                                            </a>
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

    <!-- 3. MODAL: CREATE / EDIT REGISTRATION FORM OR TOUR -->
    <div id="formModal" class="modal-overlay">
        <div class="modal-dialog">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 16px;">
                <h3 id="formModalTitle" style="margin: 0; color: #0b2545;">Configure Registration</h3>
                <button type="button" onclick="closeFormModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;">&times;</button>
            </div>

            <form method="POST" action="<?= base_path('admin/registrations.php') ?>" onsubmit="prepareFormSubmit()">
                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                <input type="hidden" name="save_form" value="1">
                <input type="hidden" name="form_id" id="modal_form_id" value="0">
                <input type="hidden" name="fields_schema" id="modal_fields_schema" value="[]">

                <div class="form-row" style="margin-bottom: 15px;">
                    <label>Registration Type</label>
                    <select name="form_type" id="modal_form_type" onchange="onFormTypeChange(this.value)">
                        <option value="general">General Event Form</option>
                        <option value="tour">Site Tour Registration</option>
                    </select>
                </div>

                <div class="form-row" style="margin-bottom: 15px;">
                    <label>Title *</label>
                    <input type="text" name="title" id="modal_title" required placeholder="e.g. Paducah Deactivation Site Tour" onkeyup="autoSlug(this.value)">
                </div>

                <div id="modal_tour_details_row" style="display: none; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 14px; margin-bottom: 15px;">
                    <div style="font-weight: 700; color: #0b2545; font-size: 0.9rem; margin-bottom: 10px;">
                        &#128197; Tour Schedule & Time Slots
                    </div>
                    <div class="form-row" style="margin-bottom: 14px;">
                        <label style="color: #0b2545;">Tour Date *</label>
                        <input type="date" name="tour_date" id="modal_tour_date" onchange="autoGenerateTourSlug(this.value)">
                    </div>

                    <div style="border-top: 1px solid #bfdbfe; padding-top: 12px; margin-top: 8px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <label style="color: #0b2545; font-weight:700; font-size:0.86rem; margin:0;">
                                Tour Departure Times & Individual Slot Capacities
                            </label>
                            <button type="button" class="btn-secondary" onclick="addTimeSlotRow()" style="font-size:0.75rem; padding:3px 8px;">
                                + Add Time Slot
                            </button>
                        </div>
                        <div id="time_slots_container" style="display:flex; flex-direction:column; gap:8px;">
                        </div>
                        <small style="color: #64748b; font-size:0.78rem; display:block; margin-top:6px;">
                            Total form capacity will automatically be calculated as the sum of all time slots.
                        </small>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="form-row">
                        <label>Host Target</label>
                        <select name="host_type" id="modal_host_type" onchange="toggleHostUI(this.value)">
                            <option value="page">Standalone Page (/slug)</option>
                            <option value="block">Sidebar / Layout Block Widget</option>
                        </select>
                    </div>
                    <div class="form-row" id="modal_slug_wrap">
                        <label>URL Slug *</label>
                        <input type="text" name="slug" id="modal_slug" placeholder="e.g. tour/2026/november-8">
                    </div>
                </div>

                <div class="form-row" id="modal_description_wrap" style="margin-bottom: 15px;">
                    <label>Description / Event Instructions (WYSIWYG)</label>
                    <textarea name="description" id="modal_description" rows="5" placeholder="Brief briefing notes, directions, or requirements..."></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="form-row" id="modal_capacity_wrap">
                        <label>Capacity (0 = Unlimited)</label>
                        <input type="number" name="capacity" id="modal_capacity" value="0" min="0">
                    </div>
                    <div class="form-row">
                        <label>Open Date (Optional)</label>
                        <input type="datetime-local" name="open_date" id="modal_open_date">
                    </div>
                    <div class="form-row">
                        <label>Close Date (Optional)</label>
                        <input type="datetime-local" name="close_date" id="modal_close_date">
                    </div>
                </div>

                <div class="form-row" style="margin-bottom: 18px;">
                    <label>Confirmation Message</label>
                    <textarea name="confirmation_message" id="modal_confirmation_message" rows="2" placeholder="Thank you! Your registration has been confirmed."></textarea>
                </div>

                <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 14px 16px; margin-bottom: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <div>
                            <h4 style="margin: 0; color: #0b2545; font-size: 0.95rem;">Form Fields Schema</h4>
                            <span id="schemaSubtitle" style="font-size: 0.78rem; color: #64748b;">Configure attendee inputs. Email is collected automatically.</span>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" id="btnLoadTourPreset" class="btn-secondary" style="font-size: 0.78rem; padding: 4px 10px; display: none;" onclick="applyTourFieldsPreset()">
                                &#128260; Apply Tour Preset Fields
                            </button>
                            <button type="button" class="btn-secondary" style="font-size: 0.78rem; padding: 4px 10px;" onclick="openFieldModal()">+ Add Field</button>
                        </div>
                    </div>
                    <div id="schemaFieldsList">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr; gap: 10px; margin-top: 15px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; color: #0b2545; cursor: pointer;">
                        <input type="checkbox" name="allow_waitlist" id="modal_allow_waitlist" value="1" checked>
                        <span>Allow Waitlist when capacity full</span>
                    </label>

                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; color: #0b2545; cursor: pointer;">
                        <input type="checkbox" name="unique_email" id="modal_unique_email" value="1">
                        <span>Require unique registrations (only one registration permitted per email address)</span>
                    </label>

                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; color: #0b2545; cursor: pointer;">
                        <input type="checkbox" name="status" id="modal_status" value="1" checked>
                        <span>Active & Accepting Submissions</span>
                    </label>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px; border-top: 1px solid #e2e8f0; padding-top: 14px;">
                    <button type="button" class="btn-secondary" onclick="closeFormModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Save Configuration</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 4. MODAL: ADD / EDIT SCHEMA FIELD -->
    <div id="fieldModal" class="modal-overlay">
        <div class="modal-dialog" style="width: 480px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 14px;">
                <h3 style="margin: 0; color: #0b2545; font-size: 1.1rem;">Add Form Field</h3>
                <button type="button" onclick="closeFieldModal()" style="background: none; border: none; font-size: 1.4rem; cursor: pointer; color: #64748b;">&times;</button>
            </div>
            <div>
                <div class="form-row" style="margin-bottom: 12px;">
                    <label>Field Label *</label>
                    <input type="text" id="f_label" placeholder="e.g. State of Driver's License" onkeyup="autoFieldKey(this.value)">
                </div>
                <div class="form-row" style="margin-bottom: 12px;">
                    <label>Machine Key *</label>
                    <input type="text" id="f_name" placeholder="e.g. dl_state">
                </div>
                <div class="form-row" style="margin-bottom: 12px;">
                    <label>Field Type</label>
                    <select id="f_type">
                        <option value="text">Text (Single line)</option>
                        <option value="tel">Telephone / Phone</option>
                        <option value="textarea">Textarea (Multi-line)</option>
                        <option value="number">Number</option>
                        <option value="date">Date</option>
                        <option value="select">Dropdown Select</option>
                        <option value="checkbox">Checkbox / Attestation</option>
                    </select>
                </div>
                <div class="form-row" style="margin-bottom: 16px;">
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                        <input type="checkbox" id="f_required" value="1" checked>
                        <span>Required Field</span>
                    </label>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" class="btn-secondary" onclick="closeFieldModal()">Cancel</button>
                    <button type="button" class="btn-primary" onclick="confirmAddField()">Add Field</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Trumbowyg/2.27.3/trumbowyg.min.js"></script>

    <script>
        let currentSchema = [];

        $(document).ready(function() {
            if ($.fn.trumbowyg) {
                $('#modal_description').trumbowyg({
                    btns: [
                        ['viewHTML'],
                        ['undo', 'redo'],
                        ['formatting'],
                        ['strong', 'em', 'underline'],
                        ['link'],
                        ['unorderedList', 'orderedList'],
                        ['horizontalRule'],
                        ['removeformat'],
                        ['fullscreen']
                    ],
                    autogrow: true
                });
            }
        });

        function getTourPresetFields() {
            return [
                { name: 'full_name', label: 'Full Legal Name', type: 'text', required: 1 },
                { name: 'dl_state', label: "State of Driver's License", type: 'select', required: 1 },
                { name: 'phone', label: 'Phone Number', type: 'tel', required: 1 },
                { name: 'attestation_citizen', label: 'I confirm that I am a United States citizen (photo ID required upon entry)', type: 'checkbox', required: 1 },
                { name: 'attestation_age', label: 'I confirm that I am 18 years of age or older', type: 'checkbox', required: 1 },
                { name: 'additional_info', label: 'Special Accommodations / Additional Notes', type: 'textarea', required: 0 }
            ];
        }

        function applyTourFieldsPreset() {
            currentSchema = getTourPresetFields();
            renderSchemaList();
        }

        function addTimeSlotRow(timeVal = '', capVal = 20, waitlist = 1) {
            const container = document.getElementById('time_slots_container');
            const row = document.createElement('div');
            row.className = 'slot-row-item';
            row.innerHTML = `
                <input type="text" name="slot_times[]" value="${timeVal.replace(/"/g, '&quot;')}" placeholder="e.g. 8:00 AM or 8:30 AM - 11:30 AM" style="flex:3; padding:7px 10px; font-size:0.86rem; border:1px solid #cbd5e1; border-radius:4px;" required>
                <div style="display:flex; align-items:center; gap:6px; flex:1;">
                    <span style="font-size:0.75rem; color:#64748b; font-weight:600;">Seats:</span>
                    <input type="number" name="slot_capacities[]" value="${parseInt(capVal, 10) || 20}" min="1" style="width:75px; padding:7px 8px; font-size:0.86rem; border:1px solid #cbd5e1; border-radius:4px;" required oninput="calculateTotalTourCapacity()">
                </div>
                <label style="display:flex; align-items:center; gap:4px; font-size:0.75rem; color:#475569; cursor:pointer;" title="Allow waitlist for this slot">
                    <input type="checkbox" name="slot_waitlists[]" value="1" ${waitlist ? 'checked' : ''}>
                    Waitlist
                </label>
                <button type="button" onclick="this.closest('.slot-row-item').remove(); calculateTotalTourCapacity();" style="background:none; border:none; color:#dc2626; font-size:1.2rem; cursor:pointer; padding:0 4px;" title="Remove time slot">&times;</button>
            `;
            container.appendChild(row);
            calculateTotalTourCapacity();
        }

        function calculateTotalTourCapacity() {
            const caps = document.querySelectorAll('input[name="slot_capacities[]"]');
            let total = 0;
            caps.forEach(input => {
                total += parseInt(input.value, 10) || 0;
            });
            if (document.getElementById('modal_form_type').value === 'tour') {
                document.getElementById('modal_capacity').value = total;
            }
        }

        function onFormTypeChange(type) {
            const isTour = (type === 'tour');
            document.getElementById('modal_tour_details_row').style.display = isTour ? 'block' : 'none';
            document.getElementById('modal_description_wrap').style.display = isTour ? 'none' : 'block';
            document.getElementById('btnLoadTourPreset').style.display = isTour ? 'inline-block' : 'none';
            document.getElementById('formModalTitle').innerText = isTour ? 'Configure Site Tour Registration' : 'Configure Registration Form';

            if (isTour) {
                document.getElementById('modal_host_type').value = 'page';
                toggleHostUI('page');
                document.getElementById('modal_tour_date').required = true;
                const container = document.getElementById('time_slots_container');
                if (container.children.length === 0) {
                    addTimeSlotRow('8:00 AM', 20, 1);
                    addTimeSlotRow('11:00 AM', 20, 1);
                    addTimeSlotRow('1:00 PM', 20, 1);
                }
            } else {
                document.getElementById('modal_tour_date').required = false;
            }
        }

        function autoGenerateTourSlug(dateVal) {
            if (document.getElementById('modal_form_type').value === 'tour' && dateVal) {
                const parts = dateVal.split('-');
                if (parts.length < 3) return;

                const year = parts[0];
                const monthIndex = parseInt(parts[1], 10) - 1;

                const monthNames = [
                    'january', 'february', 'march', 'april', 'may', 'june',
                    'july', 'august', 'september', 'october', 'november', 'december'
                ];
                const monthName = monthNames[monthIndex] || 'month';

                const slugInput = document.getElementById('modal_slug');
                const isNewForm = document.getElementById('modal_form_id').value == '0';

                if (isNewForm || slugInput.value.startsWith('tours/') || slugInput.value.startsWith('tour/')) {
                    slugInput.value = `tours/${year}/${monthName}`;
                }
            }
        }

        function renderSchemaList() {
            const wrap = document.getElementById('schemaFieldsList');
            wrap.innerHTML = '';
            if (currentSchema.length === 0) {
                wrap.innerHTML = '<div style="color:#94a3b8; font-size:0.82rem; font-style:italic;">No custom fields configured. Only Email Address will be collected.</div>';
                return;
            }
            currentSchema.forEach((f, idx) => {
                const el = document.createElement('div');
                el.className = 'schema-field-item';
                el.innerHTML = `
                    <div>
                        <strong>${f.label || f.name}</strong> 
                        <code style="font-size:0.75rem; color:#64748b;">(${f.name})</code>
                        <span class="badge" style="background:#e2e8f0; font-size:0.72rem; margin-left:6px;">${f.type}</span>
                        ${f.required ? '<span style="color:#dc2626; font-size:0.8rem; margin-left:4px;">*Required</span>' : ''}
                    </div>
                    <div>
                        <button type="button" onclick="removeSchemaField(${idx})" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:1.1rem;">&times;</button>
                    </div>
                `;
                wrap.appendChild(el);
            });
        }

        function openFormModal(data = null, defaultType = 'general', slotsData = []) {
            const isEdit = !!(data && data.id);
            const formType = data ? (data.form_type || 'general') : defaultType;

            document.getElementById('modal_form_type').value = formType;
            document.getElementById('modal_form_id').value = isEdit ? data.id : 0;
            document.getElementById('modal_title').value = data ? (data.title || '') : (formType === 'tour' ? 'Paducah Deactivation Site Tour' : '');
            
            const desc = (formType === 'general' && data) ? (data.description || '') : '';
            if ($.fn.trumbowyg && $('#modal_description').data('trumbowyg')) {
                $('#modal_description').trumbowyg('html', desc);
            } else {
                document.getElementById('modal_description').value = desc;
            }

            document.getElementById('modal_host_type').value = data ? (data.host_type || 'page') : 'page';
            document.getElementById('modal_slug').value = data ? (data.slug || '') : (formType === 'tour' ? 'tours/' + (new Date().getFullYear()) : '');
            document.getElementById('modal_capacity').value = data ? (data.capacity || 0) : (formType === 'tour' ? 60 : 0);
            document.getElementById('modal_tour_date').value = data ? (data.tour_date || '') : '';
            document.getElementById('modal_open_date').value = (data && data.open_date) ? data.open_date.replace(' ', 'T').substring(0, 16) : '';
            document.getElementById('modal_close_date').value = (data && data.close_date) ? data.close_date.replace(' ', 'T').substring(0, 16) : '';
            
            const defaultConfirm = (formType === 'tour') 
                ? 'Thank you for registering for the Paducah Site Tour! Your security badging information has been received. Please review the security requirements sent to your email.'
                : 'Thank you! Your registration has been confirmed.';
            document.getElementById('modal_confirmation_message').value = data ? (data.confirmation_message || '') : defaultConfirm;

            document.getElementById('modal_allow_waitlist').checked = !data || parseInt(data.allow_waitlist, 10) === 1;
            document.getElementById('modal_unique_email').checked = data ? parseInt(data.unique_email, 10) === 1 : (formType === 'tour');
            document.getElementById('modal_status').checked = !data || parseInt(data.status, 10) === 1;

            const slotsContainer = document.getElementById('time_slots_container');
            slotsContainer.innerHTML = '';
            if (formType === 'tour') {
                if (slotsData && slotsData.length > 0) {
                    slotsData.forEach(s => {
                        addTimeSlotRow(s.slot_time, s.capacity, parseInt(s.allow_waitlist, 10));
                    });
                } else if (data && data.tour_time) {
                    const rawParts = data.tour_time.split(',');
                    rawParts.forEach(tp => {
                        if (tp.trim() !== '') addTimeSlotRow(tp.trim(), 20, 1);
                    });
                } else {
                    addTimeSlotRow('8:00 AM', 20, 1);
                    addTimeSlotRow('11:00 AM', 20, 1);
                    addTimeSlotRow('1:00 PM', 20, 1);
                }
            }

            toggleHostUI(document.getElementById('modal_host_type').value);
            onFormTypeChange(formType);

            try {
                if (data && data.fields_schema) {
                    currentSchema = typeof data.fields_schema === 'string' ? JSON.parse(data.fields_schema) : data.fields_schema;
                } else if (formType === 'tour') {
                    currentSchema = getTourPresetFields();
                } else {
                    currentSchema = [];
                }
            } catch (e) {
                currentSchema = [];
            }
            renderSchemaList();

            document.getElementById('formModal').style.display = 'flex';
        }

        function closeFormModal() {
            document.getElementById('formModal').style.display = 'none';
        }

        function toggleHostUI(val) {
            document.getElementById('modal_slug_wrap').style.display = (val === 'page') ? 'block' : 'none';
            document.getElementById('modal_slug').required = (val === 'page');
        }

        function autoSlug(val) {
            const slugInput = document.getElementById('modal_slug');
            if (document.getElementById('modal_form_id').value == '0' && document.getElementById('modal_form_type').value !== 'tour') {
                slugInput.value = val.toLowerCase().replace(/[^a-z0-9]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
            }
        }

        function openFieldModal() {
            document.getElementById('f_label').value = '';
            document.getElementById('f_name').value = '';
            document.getElementById('f_type').value = 'text';
            document.getElementById('f_required').checked = true;
            document.getElementById('fieldModal').style.display = 'flex';
        }

        function closeFieldModal() {
            document.getElementById('fieldModal').style.display = 'none';
        }

        function autoFieldKey(val) {
            document.getElementById('f_name').value = val.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
        }

        function confirmAddField() {
            const label = document.getElementById('f_label').value.trim();
            const name = document.getElementById('f_name').value.trim();
            const type = document.getElementById('f_type').value;
            const required = document.getElementById('f_required').checked ? 1 : 0;

            if (!label || !name) {
                alert('Field Label and Machine Key are required.');
                return;
            }

            currentSchema.push({ name, label, type, required });
            renderSchemaList();
            closeFieldModal();
        }

        function removeSchemaField(idx) {
            currentSchema.splice(idx, 1);
            renderSchemaList();
        }

        function prepareFormSubmit() {
            const formType = document.getElementById('modal_form_type').value;
            if (formType === 'tour') {
                document.getElementById('modal_description').value = '';
                calculateTotalTourCapacity();
            } else if ($.fn.trumbowyg && $('#modal_description').data('trumbowyg')) {
                $('#modal_description').val($('#modal_description').trumbowyg('html'));
            }
            document.getElementById('modal_fields_schema').value = JSON.stringify(currentSchema);
        }

        window.onclick = function(e) {
            ['formModal', 'fieldModal'].forEach(id => {
                const modal = document.getElementById(id);
                if (e.target === modal) modal.style.display = 'none';
            });
        };
    </script>
</body>
</html>
