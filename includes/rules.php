<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/tokens.php';
require_once __DIR__ . '/mailer.php';

/**
 * Supported Events Dictionary
 */
function rules_get_events(): array {
    return [
        'node_insert'           => 'After saving new content',
        'node_update'           => 'After updating existing content',
        'node_publish'          => 'When content is published live',
        'contact_submit'        => 'After submitting contact inquiry form',
        'user_login'            => 'After user successfully logs in',
        'registration_create'   => 'After attendee registers for an event',
        'registration_waitlist' => 'When attendee is placed on event waitlist',
    ];
}
/**
 * Invokes an event and processes all matching, enabled rules.
 */
function rules_invoke_event(string $event, array $context = []): void {
    try {
        $db = get_db();
        $stmt = $db->prepare('SELECT * FROM custom_rules WHERE event = :event AND status = 1 ORDER BY id ASC');
        $stmt->execute(['event' => $event]);
        $active_rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($active_rules)) {
            return;
        }

        // Enrich context with global parameters
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $context['site_name'] = get_site_setting('site_name', 'Four Rivers Nuclear Partnership');
        $context['site_url']  = $proto . $host . (function_exists('base_path') ? base_path() : '');

        foreach ($active_rules as $rule) {
            $conditions = json_decode($rule['conditions'] ?? '[]', true) ?: [];
            $actions    = json_decode($rule['actions'] ?? '[]', true) ?: [];

            if (rules_evaluate_conditions($conditions, $context)) {
                rules_execute_actions($actions, $context, $rule['label']);
            }
        }
    } catch (\Throwable $e) {
        error_log('Rules Engine Error: ' . $e->getMessage());
    }
}

/**
 * Evaluates whether current context passes the rule's condition criteria.
 */
function rules_evaluate_conditions(array $conditions, array $context): bool {
    // 1. Content Type condition
    if (!empty($conditions['content_type']) && $conditions['content_type'] !== 'all') {
        $node_type = $context['node']['type'] ?? '';
        if ($node_type !== $conditions['content_type']) {
            return false;
        }
    }

    // 2. Node Status condition
    if (!empty($conditions['node_status']) && $conditions['node_status'] !== 'all') {
        $node_status = $context['node']['status'] ?? '';
        if ($node_status !== $conditions['node_status']) {
            return false;
        }
    }

    return true;
}

/**
 * Executes defined actions (HTML Email, Watchdog Logger, etc.)
 */
function rules_execute_actions(array $actions, array $context, string $rule_label): void {
    foreach ($actions as $act) {
        $type = $act['type'] ?? '';

        if ($type === 'send_email') {
            $to        = rules_token_replace(trim($act['recipient'] ?? ''), $context);
            $subject   = rules_token_replace(trim($act['subject'] ?? ''), $context);
            $raw_html  = rules_token_replace(trim($act['body_html'] ?? ''), $context);
            $cta_label = !empty($act['cta_label']) ? rules_token_replace(trim($act['cta_label']), $context) : null;
            $cta_url   = !empty($act['cta_url']) ? rules_token_replace(trim($act['cta_url']), $context) : null;

            if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $formatted_html = build_frnp_email_html($subject, $raw_html, $cta_url, $cta_label);
                queue_email($to, $subject, $formatted_html, '', 'FRNP Automation', 2);
                watchdog('rules', "Action 'Send Email' executed by rule '{$rule_label}' to {$to}", 'info');
            }
        } elseif ($type === 'watchdog_log') {
            $msg = rules_token_replace(trim($act['log_message'] ?? ''), $context);
            $sev = $act['severity'] ?? 'notice';
            watchdog('rules', $msg, $sev);
        }
    }
}

/**
 * Replaces tokens across node, contact, user, and site scopes.
 */
function rules_token_replace(string $text, array $context): string {
    $tokens = [];

    // Global Site Tokens
    $tokens['[site:name]']      = $context['site_name'] ?? get_site_setting('site_name', 'FRNP');
    $tokens['[site:url]']       = $context['site_url'] ?? '';
    $tokens['[current-date:Y]'] = date('Y');
    $tokens['[current-date:m]'] = date('m');
    $tokens['[current-date:d]'] = date('d');

    // Node Scope
    if (!empty($context['node'])) {
        $n = $context['node'];
        $tokens['[node:id]']           = (string)($n['id'] ?? $context['node_id'] ?? '');
        $tokens['[node:title]']        = (string)($n['title'] ?? '');
        $tokens['[node:type]']         = (string)($n['type'] ?? '');
        $tokens['[node:status]']       = (string)($n['status'] ?? '');
        $tokens['[node:slug]']         = (string)($n['slug'] ?? '');
        
        $slug = (string)($n['slug'] ?? '');
        $tokens['[node:url]']          = ($context['site_url'] ?? '') . '/' . ltrim($slug, '/');
        $tokens['[node:author]']       = (string)($n['author_name'] ?? $context['user']['username'] ?? 'Editor');
        $tokens['[node:author:email]'] = (string)($n['author_email'] ?? $context['user']['email'] ?? '');
    }

    // Contact Submission Scope
    if (!empty($context['contact'])) {
        $c = $context['contact'];
        $tokens['[contact:name]']    = (string)($c['name'] ?? '');
        $tokens['[contact:email]']   = (string)($c['email'] ?? '');
        $tokens['[contact:subject]'] = (string)($c['subject'] ?? '');
        $tokens['[contact:message]'] = nl2br(htmlspecialchars((string)($c['message'] ?? '')));
    }

    // User Session Scope
    if (!empty($context['user'])) {
        $u = $context['user'];
        $tokens['[user:username]'] = (string)($u['username'] ?? '');
        $tokens['[user:email]']    = (string)($u['email'] ?? '');
    }

    // Registration

    if (!empty($context['registration'])) {
    $r = $context['registration'];
    $tokens['[registration:id]']           = (string)($r['id'] ?? '');
    $tokens['[registration:name]']         = (string)($r['name'] ?? '');
    $tokens['[registration:email]']        = (string)($r['email'] ?? '');
    $tokens['[registration:phone]']        = (string)($r['phone'] ?? '');
    $tokens['[registration:organization]'] = (string)($r['organization'] ?? '');
    $tokens['[registration:slots]']        = (string)($r['slots'] ?? '1');
    $tokens['[registration:state]']        = (string)($r['state'] ?? 'complete');
    }

    return str_replace(array_keys($tokens), array_values($tokens), $text);
}