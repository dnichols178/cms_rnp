<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/mailer.php';

/**
 * Returns all available system events grouped by domain.
 */
function rules_get_events(): array {
    return [
        // Registrations
        'registration_submit' => [
            'label' => 'Registration Completed / Submitted',
            'group' => 'Registrations',
            'entity' => 'registration',
        ],
        'registration_waitlist' => [
            'label' => 'Placed on Waitlist',
            'group' => 'Registrations',
            'entity' => 'registration',
        ],

        // Content / Nodes
        'node_publish' => [
            'label' => 'Content Published',
            'group' => 'Content (Nodes)',
            'entity' => 'node',
        ],
        'node_insert' => [
            'label' => 'Content Created (New Node)',
            'group' => 'Content (Nodes)',
            'entity' => 'node',
        ],
        'node_update' => [
            'label' => 'Content Updated (Existing Node)',
            'group' => 'Content (Nodes)',
            'entity' => 'node',
        ],
        'node_delete' => [
            'label' => 'Content Deleted',
            'group' => 'Content (Nodes)',
            'entity' => 'node',
        ],

        // Users & Authentication
        'user_login' => [
            'label' => 'User Logged In',
            'group' => 'Users',
            'entity' => 'user',
        ],
        'user_insert' => [
            'label' => 'User Account Created',
            'group' => 'Users',
            'entity' => 'user',
        ],
        'user_update' => [
            'label' => 'User Account Profile Updated',
            'group' => 'Users',
            'entity' => 'user',
        ],
    ];
}

/**
 * Fetch all rules from database.
 */
function rules_get_all_rules(): array {
    $db = get_db();
    return $db->query('SELECT * FROM custom_rules ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Invokes an event and executes all matching active rules.
 */
function rules_invoke_event(string $event_name, array $context = []): void {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM custom_rules WHERE event = :event AND status = 1');
    $stmt->execute(['event' => $event_name]);
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rules as $rule) {
        rules_execute_rule($rule, $context);
    }
}

/**
 * Evaluates conditions and executes actions for a single rule.
 */
function rules_execute_rule(array $rule, array $context): void {
    $conditions = json_decode($rule['conditions'] ?? '[]', true) ?: [];

    // Evaluate all conditions
    foreach ($conditions as $cond) {
        if (!rules_evaluate_condition($cond, $context)) {
            return; // Condition failed, abort rule execution
        }
    }

    $actions = json_decode($rule['actions'] ?? '[]', true) ?: [];
    foreach ($actions as $action) {
        rules_execute_action($action, $context, $rule);
    }
}

/**
 * Evaluates a single rule condition against the current context across Nodes, Users, and Registrations.
 */
function rules_evaluate_condition(array $cond, array $context): bool {
    $field = trim((string)($cond['field'] ?? ''));
    $operator = trim((string)($cond['operator'] ?? 'equals'));
    $target_val = trim((string)($cond['value'] ?? ''));

    if ($field === '') {
        return true;
    }

    $actual_val = '';

    // 1. Registration / Form Scope
    if ($field === 'form_type' || $field === 'form:type') {
        $actual_val = (string)($context['form']['form_type'] ?? 'general');
    } elseif ($field === 'form_id' || $field === 'form:id') {
        $actual_val = (string)($context['form']['id'] ?? ($context['registration']['form_id'] ?? ''));
    } elseif ($field === 'registration:state') {
        $actual_val = (string)($context['registration']['state'] ?? '');
    }

    // 2. Node Scope
    elseif ($field === 'node_type' || $field === 'node:type') {
        $actual_val = (string)($context['node']['type'] ?? '');
    } elseif ($field === 'node_status' || $field === 'node:status') {
        $actual_val = (string)($context['node']['status'] ?? '');
    }

    // 3. User Scope
    elseif ($field === 'user_role' || $field === 'user:role') {
        $roles = $context['user']['roles'] ?? [];
        if (is_string($roles)) {
            $roles = json_decode($roles, true) ?: [$roles];
        }
        if ($operator === 'equals' || $operator === 'contains') {
            return in_array($target_val, (array)$roles, true);
        }
        if ($operator === 'not_equals') {
            return !in_array($target_val, (array)$roles, true);
        }
    }

    // 4. Generic Key Lookup
    elseif (isset($context[$field])) {
        $actual_val = is_scalar($context[$field]) ? (string)$context[$field] : '';
    } elseif (isset($context['form'][$field])) {
        $actual_val = (string)$context['form'][$field];
    } elseif (isset($context['node'][$field])) {
        $actual_val = (string)$context['node'][$field];
    } elseif (isset($context['user'][$field])) {
        $actual_val = (string)$context['user'][$field];
    } elseif (isset($context['registration'][$field])) {
        $actual_val = (string)$context['registration'][$field];
    }

    $actual_val_lower = strtolower($actual_val);
    $target_val_lower = strtolower($target_val);

    switch ($operator) {
        case 'equals':
            return $actual_val_lower === $target_val_lower;
        case 'not_equals':
            return $actual_val_lower !== $target_val_lower;
        case 'contains':
            return str_contains($actual_val_lower, $target_val_lower);
        case 'not_contains':
            return !str_contains($actual_val_lower, $target_val_lower);
        case 'starts_with':
            return str_starts_with($actual_val_lower, $target_val_lower);
        case 'ends_with':
            return str_ends_with($actual_val_lower, $target_val_lower);
        case 'greater_than':
            return is_numeric($actual_val) && is_numeric($target_val) && ((float)$actual_val > (float)$target_val);
        case 'less_than':
            return is_numeric($actual_val) && is_numeric($target_val) && ((float)$actual_val < (float)$target_val);
        case 'is_empty':
            return trim($actual_val) === '';
        case 'is_not_empty':
            return trim($actual_val) !== '';
        default:
            return true;
    }
}

/**
 * Executes a single action (send_email, watchdog, webhook).
 */
function rules_execute_action(array $action, array $context, array $rule): void {
    $type = $action['type'] ?? '';

    if ($type === 'send_email') {
        $to      = rules_replace_tokens((string)($action['to'] ?? ''), $context);
        $to_name = rules_replace_tokens((string)($action['recipient_name'] ?? ''), $context);
        $subject = rules_replace_tokens((string)($action['subject'] ?? ''), $context);
        $body    = rules_replace_tokens((string)($action['message'] ?? ''), $context);

        if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $site_name = function_exists('get_site_setting') ? get_site_setting('site_name', 'Four Rivers Nuclear Partnership') : 'Four Rivers Nuclear Partnership';
            queue_email($to, $subject, $body, $to_name, $site_name, 1);
            watchdog('rules', "Rule '{$rule['label']}' dispatched email to {$to} [Subject: {$subject}]", 'notice');
        } else {
            watchdog('rules', "Rule '{$rule['label']}' failed: Invalid recipient address '{$to}'", 'warning');
        }
    } elseif ($type === 'watchdog') {
        $msg = rules_replace_tokens((string)($action['message'] ?? ''), $context);
        $sev = $action['severity'] ?? 'notice';
        watchdog('rules_action', $msg, $sev);
    }
}

/**
 * Replaces token placeholders across Site, Node, User, and Registration entities.
 */
function rules_replace_tokens(string $text, array $context): string {
    $site_name   = function_exists('get_site_setting') ? get_site_setting('site_name', 'Four Rivers Nuclear Partnership') : 'Four Rivers Nuclear Partnership';
    $site_slogan = function_exists('get_site_setting') ? get_site_setting('site_slogan', 'Paducah Deactivation Project') : '';
    $site_url    = function_exists('base_path') ? base_path('') : '/';

    $replacements = [
        // Global / Site
        '[site:name]'   => $site_name,
        '[site:slogan]' => $site_slogan,
        '[site:url]'    => $site_url,
    ];

    // 1. Content / Node Tokens
    if (!empty($context['node'])) {
        $n = $context['node'];
        $n_id = (string)($n['id'] ?? '');
        $n_slug = (string)($n['slug'] ?? '');
        $n_url = function_exists('base_path') && $n_slug !== '' ? base_path($n_slug) : '';

        $replacements['[node:id]']      = $n_id;
        $replacements['[node:title]']   = (string)($n['title'] ?? '');
        $replacements['[node:type]']    = (string)($n['type'] ?? '');
        $replacements['[node:slug]']    = $n_slug;
        $replacements['[node:url]']     = $n_url;
        $replacements['[node:author]']  = (string)($n['author_name'] ?? ($n['user_id'] ?? 'Admin'));
        $replacements['[node:created]'] = !empty($n['created_at']) ? date('M j, Y g:i A', strtotime($n['created_at'])) : '';
        $replacements['[node:summary]'] = !empty($n['body']) ? mb_strimwidth(strip_tags((string)$n['body']), 0, 160, '...') : '';

        // Dynamic custom fields on node
        $fields_data = is_array($n['fields_data'] ?? null)
            ? $n['fields_data']
            : (json_decode((string)($n['fields_data'] ?? '{}'), true) ?: []);
        foreach ($fields_data as $fk => $fv) {
            $replacements['[node:' . $fk . ']'] = is_array($fv) ? implode(', ', $fv) : (string)$fv;
        }
    }

    // 2. User Tokens
    if (!empty($context['user'])) {
        $u = $context['user'];
        $u_roles = $u['roles'] ?? [];
        if (is_array($u_roles)) {
            $roles_str = implode(', ', $u_roles);
        } else {
            $roles_str = (string)$u_roles;
        }

        $replacements['[user:id]']    = (string)($u['id'] ?? '');
        $replacements['[user:name]']  = (string)($u['username'] ?? ($u['name'] ?? ''));
        $replacements['[user:email]'] = (string)($u['email'] ?? '');
        $replacements['[user:roles]'] = $roles_str;
    }

    // 3. Registration, Form & Tour Tokens
    if (!empty($context['registration']) || !empty($context['form'])) {
        $reg  = $context['registration'] ?? [];
        $form = $context['form'] ?? [];

        $sub_data = [];
        if (!empty($reg['submission_data'])) {
            $sub_data = is_array($reg['submission_data'])
                ? $reg['submission_data']
                : (json_decode((string)$reg['submission_data'], true) ?: []);
        }

        $tour_date = (string)($form['tour_date'] ?? '');
        $tour_date_formatted = '';
        if ($tour_date !== '') {
            $ts = strtotime($tour_date);
            $tour_date_formatted = date('l, F j, Y', $ts);
        }
        $tour_time = (string)($form['tour_time'] ?? '');

        $name = !empty($reg['name']) ? $reg['name'] : ($sub_data['full_name'] ?? '');
        $dl_state = $sub_data['dl_state'] ?? ($reg['dl_state'] ?? '');
        $conf_num = sprintf('REG-%04d', (int)($reg['id'] ?? 0));

        // Formatted structured summary
        $summary = '<table style="width:100%; border-collapse:collapse; margin:15px 0; font-family:sans-serif; font-size:14px;">';
        $summary .= '<tr><td style="padding:6px 10px; font-weight:bold; width:35%; border-bottom:1px solid #e2e8f0;">Confirmation #:</td><td style="padding:6px 10px; border-bottom:1px solid #e2e8f0;">' . htmlspecialchars($conf_num) . '</td></tr>';
        if ($name !== '') {
            $summary .= '<tr><td style="padding:6px 10px; font-weight:bold; border-bottom:1px solid #e2e8f0;">Full Name:</td><td style="padding:6px 10px; border-bottom:1px solid #e2e8f0;">' . htmlspecialchars((string)$name) . '</td></tr>';
        }
        if (!empty($reg['email'])) {
            $summary .= '<tr><td style="padding:6px 10px; font-weight:bold; border-bottom:1px solid #e2e8f0;">Email:</td><td style="padding:6px 10px; border-bottom:1px solid #e2e8f0;">' . htmlspecialchars((string)$reg['email']) . '</td></tr>';
        }
        if (!empty($reg['phone']) || !empty($sub_data['phone'])) {
            $phone_val = (string)(!empty($reg['phone']) ? $reg['phone'] : $sub_data['phone']);
            $summary .= '<tr><td style="padding:6px 10px; font-weight:bold; border-bottom:1px solid #e2e8f0;">Phone:</td><td style="padding:6px 10px; border-bottom:1px solid #e2e8f0;">' . htmlspecialchars($phone_val) . '</td></tr>';
        }
        if ($dl_state !== '') {
            $summary .= '<tr><td style="padding:6px 10px; font-weight:bold; border-bottom:1px solid #e2e8f0;">Driver\'s License State:</td><td style="padding:6px 10px; border-bottom:1px solid #e2e8f0;">' . htmlspecialchars((string)$dl_state) . '</td></tr>';
        }
        if ($tour_date_formatted !== '') {
            $summary .= '<tr><td style="padding:6px 10px; font-weight:bold; border-bottom:1px solid #e2e8f0;">Tour Date:</td><td style="padding:6px 10px; border-bottom:1px solid #e2e8f0;">' . htmlspecialchars($tour_date_formatted) . '</td></tr>';
        }
        if ($tour_time !== '') {
            $summary .= '<tr><td style="padding:6px 10px; font-weight:bold; border-bottom:1px solid #e2e8f0;">Tour Time:</td><td style="padding:6px 10px; border-bottom:1px solid #e2e8f0;">' . htmlspecialchars($tour_time) . '</td></tr>';
        }
        $summary .= '</table>';

        $replacements['[registration:id]']                  = (string)($reg['id'] ?? '');
        $replacements['[registration:confirmation_number]'] = $conf_num;
        $replacements['[registration:name]']                = (string)$name;
        $replacements['[registration:email]']               = (string)($reg['email'] ?? '');
        $replacements['[registration:phone]']               = (string)(!empty($reg['phone']) ? $reg['phone'] : ($sub_data['phone'] ?? ''));
        $replacements['[registration:dl_state]']            = (string)$dl_state;
        $replacements['[registration:organization]']        = (string)($reg['organization'] ?? '');
        $replacements['[registration:slots]']               = (string)($reg['slots'] ?? '1');
        $replacements['[registration:state]']               = (string)($reg['state'] ?? 'complete');
        $replacements['[registration:summary]']             = $summary;

        $replacements['[form:id]']                   = (string)($form['id'] ?? '');
        $replacements['[form:title]']                = (string)($form['title'] ?? '');
        $replacements['[form:type]']                 = (string)($form['form_type'] ?? 'general');
        $replacements['[form:url]']                  = (!empty($form['slug']) && function_exists('base_path')) ? base_path($form['slug']) : '';
        $replacements['[form:confirmation_message]'] = (string)($form['confirmation_message'] ?? '');

        $replacements['[tour:date]']                 = $tour_date;
        $replacements['[tour:date_formatted]']       = $tour_date_formatted;
        $replacements['[tour:time]']                 = $tour_time;

        // Custom schema attributes
        foreach ($sub_data as $sk => $sv) {
            $replacements['[registration:' . $sk . ']'] = is_array($sv) ? implode(', ', $sv) : (string)$sv;
        }
    }

    return str_replace(array_keys($replacements), array_values($replacements), $text);
}
