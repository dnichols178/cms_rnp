<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/tokens.php';
require_once __DIR__ . '/filters.php';

if (!function_exists('base_path')) {
    /**
     * Resolves the dynamic web root path of the CMS instance.
     * Returns "" when deployed at domain root, or "/subdirectory" when in a folder.
     *
     * Examples:
     *  base_path()                  => "" (at root) or "/cms" (in subfolder)
     *  base_path('home')            => "/home" or "/cms/home"
     *  base_path('admin/nodes.php') => "/admin/nodes.php" or "/cms/admin/nodes.php"
     */
    function base_path(string $path = ''): string {
    static $base = null;
    if ($base === null) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if (preg_match('#^(.*?)/(admin|api|includes|custom_pages)/#', $script, $matches)) {
            $base = rtrim($matches[1], '/');
        } else {
            $dir = dirname($script);
            $base = ($dir === '/' || $dir === '\\' || $dir === '.') ? '' : rtrim($dir, '/');
        }
    }
    $clean = ltrim($path, '/');
    if ($clean === 'home') {
        return $base !== '' ? $base . '/' : '/';
    }
    if ($clean === '') {
        return $base !== '' ? $base : '/';
    }
    return ($base !== '' ? $base : '') . '/' . $clean;
}
}

if (!function_exists('url')) {
    function url(string $path = ''): string {
        return base_path($path);
    }
}

function get_content_types(): array {
    $db = get_db();
    return $db->query('SELECT * FROM content_types ORDER BY name ASC')->fetchAll();
}

function get_content_type(string $type): ?array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM content_types WHERE type = :type LIMIT 1');
    $stmt->execute(['type' => $type]);
    $result = $stmt->fetch();
    if ($result) {
        $schema = json_decode($result['fields_schema'] ?? '[]', true);
        if (!is_array($schema)) {
            $schema = [];
        }

        // If content type has body enabled but not yet represented in schema, inject it
        if (!empty($result['has_body'])) {
            $has_body_field = false;
            foreach ($schema as $f) {
                if (($f['name'] ?? '') === 'body' || ($f['type'] ?? '') === 'body') {
                    $has_body_field = true;
                    break;
                }
            }
            if (!$has_body_field) {
                $schema[] = [
                    'name'          => 'body',
                    'label'         => 'Body Content',
                    'type'          => 'body',
                    'required'      => 0,
                    'label_display' => 'hidden',
                    'weight'        => 50,
                    'help_text'     => 'Main article text or rich content.'
                ];
            }
        }

        // Sort entire schema by weight to guarantee unified display & edit order
        usort($schema, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
        $result['fields_schema'] = $schema;
    }
    return $result ?: null;
}

function get_node_by_slug(string $slug, bool $allow_drafts = false): ?array {
    $db = get_db();
    $sql = '
        SELECT n.*, u.username AS author_name, ct.name AS type_name 
        FROM nodes n
        JOIN users u ON u.id = n.uid
        JOIN content_types ct ON ct.type = n.type
        WHERE n.slug = :slug
    ';
    if (!$allow_drafts) {
        $sql .= " AND (n.status = 'published' OR n.status = '1' OR n.status = 1)";
    }
    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute(['slug' => $slug]);
    $node = $stmt->fetch();
    if ($node) {
        $node['fields_data'] = json_decode($node['fields_data'] ?? '{}', true);
        $node['terms'] = get_node_terms((int)$node['id']);
    }
    return $node ?: null;
}

function get_node_by_id(int $id): ?array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT n.*, u.username AS author_name, ct.name AS type_name 
        FROM nodes n
        JOIN users u ON u.id = n.uid
        JOIN content_types ct ON ct.type = n.type
        WHERE n.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id]);
    $node = $stmt->fetch();
    if ($node) {
        $node['fields_data'] = json_decode($node['fields_data'] ?? '{}', true);
        $node['terms'] = get_node_terms((int)$node['id']);
    }
    return $node ?: null;
}

function get_node_terms(int $node_id): array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT t.id, t.name, t.slug 
        FROM taxonomy_terms t
        JOIN node_terms nt ON nt.term_id = t.id
        WHERE nt.node_id = :nid
        ORDER BY t.weight ASC, t.name ASC
    ');
    $stmt->execute(['nid' => $node_id]);
    return $stmt->fetchAll();
}

function save_node(array $data, ?int $id = null, array $term_ids = [], string $log_msg = ''): int {
    $db = get_db();
    $fields_json = json_encode($data['fields_data'] ?? []);
    $keywords = !empty($data['keywords']) ? trim($data['keywords']) : null;
    $format = !empty($data['format']) ? $data['format'] : 'filtered_html';
    $auto_slug = isset($data['auto_slug']) ? (int)$data['auto_slug'] : 1;

    require_once __DIR__ . '/tokens.php';

    // 1. Determine base pattern/string to process
    if ($auto_slug === 1 || empty($data['slug'])) {
        $content_type = get_content_type($data['type']);
        $pattern = trim($content_type['url_pattern'] ?? '');
        if ($pattern === '') {
            $pattern = ($data['type'] === 'article') ? 'news/[node:created:Y]/[node:title]' : '[node:title]';
        }
    } else {
        // User customized URL: may contain static text and/or tokens
        $pattern = trim($data['slug']);
    }

    // 2. Resolve tokens against the current node payload
    $raw_alias = token_replace($pattern, ['node' => $data]);
    $clean_base = pathauto_clean_token_url($raw_alias);

    if ($clean_base === '') {
        $clean_base = 'node-' . bin2hex(random_bytes(3));
    }

    // 3. Collision safety: Guarantee uniqueness in database
    $slug = $clean_base;
    $counter = 1;
    while (true) {
        $sql = 'SELECT COUNT(*) FROM nodes WHERE slug = :slug';
        $params = ['slug' => $slug];
        if ($id !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $id;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        if ((int)$stmt->fetchColumn() === 0) {
            break;
        }
        $slug = $clean_base . '-' . $counter;
        $counter++;
    }

    if ($id === null) {
        $stmt = $db->prepare('
            INSERT INTO nodes (type, title, slug, auto_slug, body, format, keywords, fields_data, uid, status, publish_on, unpublish_on, template)
            VALUES (:type, :title, :slug, :auto_slug, :body, :format, :keywords, :fields_data, :uid, :status, :publish_on, :unpublish_on, :template)
        ');
        $stmt->execute([
            'type'         => $data['type'],
            'title'        => $data['title'],
            'slug'         => $slug,
            'auto_slug'    => $auto_slug,
            'body'         => $data['body'] ?? null,
            'format'       => $format,
            'keywords'     => $keywords,
            'fields_data'  => $fields_json,
            'uid'          => $data['uid'],
            'status'       => $data['status'] ?? 'published',
            'publish_on'   => !empty($data['publish_on']) ? $data['publish_on'] : null,
            'unpublish_on' => !empty($data['unpublish_on']) ? $data['unpublish_on'] : null,
            'template'     => $data['template'] ?? null
        ]);
        $saved_id = (int)$db->lastInsertId();
    } else {
        $stmt = $db->prepare('
            UPDATE nodes 
            SET title = :title, slug = :slug, auto_slug = :auto_slug, body = :body, format = :format, 
                keywords = :keywords, fields_data = :fields_data, status = :status, 
                publish_on = :publish_on, unpublish_on = :unpublish_on, template = :template
            WHERE id = :id
        ');
        $stmt->execute([
            'title'        => $data['title'],
            'slug'         => $slug,
            'auto_slug'    => $auto_slug,
            'body'         => $data['body'] ?? null,
            'format'       => $format,
            'keywords'     => $keywords,
            'fields_data'  => $fields_json,
            'status'       => $data['status'] ?? 'published',
            'publish_on'   => !empty($data['publish_on']) ? $data['publish_on'] : null,
            'unpublish_on' => !empty($data['unpublish_on']) ? $data['unpublish_on'] : null,
            'template'     => $data['template'] ?? null,
            'id'           => $id
        ]);
        $saved_id = $id;
    }

    // Save snapshot revision
    $rev_stmt = $db->prepare('
        INSERT INTO node_revisions (node_id, title, body, keywords, fields_data, log_message, uid) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $rev_stmt->execute([
        $saved_id,
        $data['title'],
        $data['body'] ?? '',
        $keywords,
        $fields_json,
        $log_msg !== '' ? $log_msg : ($id === null ? 'Initial node creation' : 'Content update'),
        $data['uid']
    ]);

    // Synchronize taxonomy terms
    $del = $db->prepare('DELETE FROM node_terms WHERE node_id = :nid');
    $del->execute(['nid' => $saved_id]);
    if (!empty($term_ids)) {
        $ins = $db->prepare('INSERT INTO node_terms (node_id, term_id) VALUES (?, ?)');
        foreach ($term_ids as $tid) {
            $ins->execute([$saved_id, (int)$tid]);
        }
    }

    watchdog('content', "Node '{$data['title']}' saved (Type: {$data['type']}).", 'notice', "/cms/{$slug}");
    require_once __DIR__ . '/cache.php';
    cache_clear();

    // Trigger Rules and Automations
    if (file_exists(__DIR__ . '/rules.php')) {
        require_once __DIR__ . '/rules.php';
        $rule_context = [
            'node'    => array_merge($data, ['id' => $saved_id, 'slug' => $slug]),
            'node_id' => $saved_id,
            'user'    => function_exists('current_user') ? current_user() : null
        ];

        if ($id === null) {
            rules_invoke_event('node_insert', $rule_context);
        } else {
            rules_invoke_event('node_update', $rule_context);
        }

        if (($data['status'] ?? '') === 'published' || ($data['status'] ?? '') === '1') {
            rules_invoke_event('node_publish', $rule_context);
        }
    }
    // Synchronize taxonomy terms (if not passed as $term_ids explicitly, check custom fields)
        if (empty($term_ids) && !empty($data['fields_data'])) {
            foreach ($data['fields_data'] as $k => $v) {
                if ((str_contains($k, 'tax') || str_contains($k, 'term')) && !empty($v)) {
                    $term_ids = is_array($v) ? array_map('intval', $v) : array_map('intval', explode(',', (string)$v));
                break;
                }
            }
        }

    return $saved_id;
}

function get_all_taxonomy_terms(): array {
    $db = get_db();
    return $db->query('
        SELECT t.id, t.name, t.slug, v.name AS vocabulary_name
        FROM taxonomy_terms t
        JOIN taxonomy_vocabularies v ON v.id = t.vocabulary_id
        ORDER BY v.name ASC, t.weight ASC, t.name ASC
    ')->fetchAll();
}

function get_menu_items(string $menu_name = 'main'): array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT * FROM menu_items 
        WHERE menu_name = :m AND status = 1 
        ORDER BY weight ASC, id ASC
    ');
    $stmt->execute(['m' => $menu_name]);
    return $stmt->fetchAll();
}

function get_node_revisions(int $node_id): array {
    $db = get_db();
    $stmt = $db->prepare('
        SELECT r.*, u.username 
        FROM node_revisions r
        JOIN users u ON u.id = r.uid
        WHERE r.node_id = :nid
        ORDER BY r.created_at DESC
    ');
    $stmt->execute(['nid' => $node_id]);
    return $stmt->fetchAll();
}

function rollback_node_revision(int $revision_id, int $user_id): bool {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM node_revisions WHERE id = :rid LIMIT 1');
    $stmt->execute(['rid' => $revision_id]);
    $rev = $stmt->fetch();

    if (!$rev) {
        return false;
    }

    // Backup current state before rollback
    $node_stmt = $db->prepare('SELECT * FROM nodes WHERE id = :id');
    $node_stmt->execute(['id' => $rev['node_id']]);
    $current = $node_stmt->fetch();

    if ($current) {
        $log_stmt = $db->prepare('
            INSERT INTO node_revisions (node_id, title, body, keywords, fields_data, log_message, uid) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $log_stmt->execute([
            $current['id'],
            $current['title'],
            $current['body'],
            $current['keywords'] ?? null,
            $current['fields_data'],
            'Auto-backup prior to rollback to Rev #' . $revision_id,
            $user_id
        ]);
    }

    // Apply the revision
    $upd = $db->prepare('UPDATE nodes SET title = :t, body = :b, keywords = :k, fields_data = :f WHERE id = :id');
    return $upd->execute([
        't'  => $rev['title'],
        'b'  => $rev['body'],
        'k'  => $rev['keywords'] ?? null,
        'f'  => $rev['fields_data'],
        'id' => $rev['node_id']
    ]);
}

function get_site_setting(string $key, string $default = ''): string {
    static $settings = null;
    if ($settings === null) {
        $db = get_db();
        $rows = $db->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
        $settings = [];
        foreach ($rows as $r) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
    }
    return $settings[$key] ?? $default;
}

function set_site_setting(string $key, string $value): void {
    $db = get_db();
    $stmt = $db->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

function get_blocks_for_region(string $region, string $current_slug, ?array $node = null): array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM blocks WHERE region = :reg AND status = 1 ORDER BY weight ASC, id ASC');
    $stmt->execute(['reg' => $region]);
    $candidates = $stmt->fetchAll();

    $user_roles = ['anonymous'];
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user']['roles'])) {
        $user_roles = $_SESSION['user']['roles'];
        $user_roles[] = 'authenticated';
    }

    $node_type = $node['type'] ?? '';
    $visible = [];

    foreach ($candidates as $b) {
        // 1. Role Visibility Matrix
        $req_roles = array_filter(array_map('trim', explode(',', $b['visibility_roles'] ?? '')));
        if (!empty($req_roles)) {
            $role_match = array_intersect($user_roles, $req_roles);
            if (empty($role_match)) {
                continue;
            }
        }

        // 2. Content Type Visibility Matrix
        $req_types = array_filter(array_map('trim', explode(',', $b['visibility_types'] ?? '')));
        if (!empty($req_types)) {
            if (!$node_type || !in_array($node_type, $req_types, true)) {
                continue;
            }
        }

        // 3. Path Visibility Matrix (Supports <front>, wildcards *)
        $path_rules = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $b['visibility_pages'] ?? ''))));
        $mode = $b['visibility_mode'] ?? 'show_only';

        if (empty($path_rules)) {
            $visible[] = $b;
            continue;
        }

        $matched_path = false;
        foreach ($path_rules as $rule) {
            $rule = trim($rule);
            if ($rule === '<front>' && ($current_slug === 'home' || $current_slug === '')) {
                $matched_path = true;
                break;
            }
            if ($rule === $current_slug) {
                $matched_path = true;
                break;
            }
            if (str_contains($rule, '*')) {
                $pattern = '#^' . str_replace('\*', '.*', preg_quote($rule, '#')) . '$#i';
                if (preg_match($pattern, $current_slug)) {
                    $matched_path = true;
                    break;
                }
            }
        }

        if ($mode === 'show_only' && $matched_path) {
            $visible[] = $b;
        } elseif ($mode === 'show_except' && !$matched_path) {
            $visible[] = $b;
        }
    }

    return $visible;
}

function render_region(string $region, string $current_slug, ?array $node = null): void {
    $blocks = get_blocks_for_region($region, $current_slug, $node);
    foreach ($blocks as $b) {
        $body = check_markup($b['body'], 'filtered_html', ['node' => $node]);

        // Process custom Views shortcode tokens
        if (function_exists('replace_view_tags')) {
            $body = replace_view_tags($body);
        }

        // Replace registration block embeds if subsystem exists
        if (function_exists('replace_registration_form_tags')) {
            $body = replace_registration_form_tags($body);
        }

        // Replace dynamic similar content block tags
        if (function_exists('replace_similar_content_tags')) {
            $body = replace_similar_content_tags($body, $node);
        }

        // If the processed body is blank (e.g. no similar content), suppress the block entirely
        if (trim(strip_tags($body, '<img><svg><video><audio><input><button><hr><form><canvas><embed><object>')) === '') {
            continue;
        }

        echo '<div class="block-widget block-region-' . htmlspecialchars($region) . '" style="margin-bottom: 22px;">';
        if (!empty($b['title'])) {
            echo '<h3 style="color:var(--frnp-navy); font-size:1.1rem; border-bottom:1px solid #e2e8f0; padding-bottom:8px; margin-bottom:12px;">' . htmlspecialchars($b['title']) . '</h3>';
        }
        echo '<div class="block-content">' . $body . '</div>';
        echo '</div>';
    }
}

function watchdog(string $type, string $message, string $severity = 'notice', ?string $link = null): void {
    try {
        $db = get_db();
        $uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $stmt = $db->prepare('
            INSERT INTO watchdog (uid, type, message, severity, link, ip_address) 
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$uid, $type, $message, $severity, $link, $ip]);
    } catch (\Throwable $e) {
        error_log("Watchdog failure: " . $e->getMessage());
    }
}

/**
 * Generates a clean, unique URL slug for a node.
 */
function generate_node_slug(string $title, string $type, ?string $custom_slug = null, ?int $current_id = null): string {
    $db = get_db();

    if ($custom_slug !== null && trim($custom_slug) !== '') {
        $base_slug = preg_replace('/[^a-z0-9_\-\/]/', '', strtolower(str_replace(' ', '-', trim($custom_slug))));
        $base_slug = trim(preg_replace('/-+/', '-', $base_slug), '-/');
    } else {
        $clean = preg_replace('/[^a-z0-9\s-]/', '', strtolower($title));
        $base_slug = preg_replace('/[\s-]+/', '-', trim($clean));
        if ($base_slug === '') {
            $base_slug = 'node-' . bin2hex(random_bytes(3));
        }

        switch ($type) {
            case 'article':
            case 'news':
            case 'news_updates':
                $base_slug = sprintf('news/%s/%s', date('Y'), $base_slug);
                break;
            case 'page':
            default:
                break;
        }
    }

    $slug = $base_slug;
    $counter = 1;

    while (true) {
        $sql = 'SELECT COUNT(*) FROM nodes WHERE slug = :slug';
        $params = ['slug' => $slug];

        if ($current_id !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $current_id;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        if ((int)$stmt->fetchColumn() === 0) {
            break;
        }

        $slug = $base_slug . '-' . $counter;
        $counter++;
    }

    return $slug;
}

function render_custom_fields(array $node, string $position = 'all'): string {
    return render_node_fields($node);
}

function render_node_fields(array $node): string {
    static $rendered_nodes = [];
    if (!empty($node['id']) && isset($rendered_nodes[$node['id']])) {
        return '';
    }

    if (empty($node['type'])) {
        return !empty($node['body']) ? '<div class="node-body">' . $node['body'] . '</div>' : '';
    }

    $content_type = get_content_type($node['type']);
    if (!$content_type || empty($content_type['fields_schema'])) {
        return !empty($node['body']) ? '<div class="node-body">' . $node['body'] . '</div>' : '';
    }

    $fields_data = $node['fields_data'] ?? [];
    $schema = $content_type['fields_schema'];
    $html = '';

    foreach ($schema as $f) {
        $fname = $f['name'];
        $ftype = $f['type'] ?? 'text';

        // 1. Render Body Field in its designated sequence position
        if ($fname === 'body' || $ftype === 'body') {
            if (!empty($node['body']) && trim($node['body']) !== '') {
                $body_html = check_markup($node['body'], $node['format'] ?? 'filtered_html', ['node' => $node]);
                $disp = $f['label_display'] ?? 'hidden';
                $html .= '<div class="field-item field-item-body" style="margin-bottom: 24px;">';
                if ($disp === 'above') {
                    $html .= '<div class="field-label" style="font-weight:700; color:var(--frnp-navy); margin-bottom:6px; font-size:1.05rem;">' . htmlspecialchars($f['label']) . '</div>';
                }
                $html .= '<div class="node-body">' . $body_html . '</div>';
                $html .= '</div>';
            }
            continue;
        }

        // 2. Render all other field types (Images, Files, Text, Dates, Selects)
        if (!isset($fields_data[$fname]) || $fields_data[$fname] === '' || $fields_data[$fname] === null) {
            continue;
        }

        $formatted_val = format_field_value($f, $fields_data[$fname]);
        if ($formatted_val === '') {
            continue;
        }

        $disp = $f['label_display'] ?? 'inline';
        $label_safe = htmlspecialchars($f['label']);

        $html .= '<div class="field-item field-item-' . htmlspecialchars($fname) . '" style="margin-bottom: 20px;">';
        if ($disp === 'hidden') {
            $html .= '<div class="field-value">' . $formatted_val . '</div>';
        } elseif ($disp === 'above') {
            $html .= '<div class="field-label" style="font-weight: 700; color: var(--frnp-navy); margin-bottom: 6px; font-size: 0.95rem;">' . $label_safe . '</div>';
            $html .= '<div class="field-value">' . $formatted_val . '</div>';
        } else {
            $html .= '<strong style="color: var(--frnp-navy);">' . $label_safe . ':</strong> ';
            $html .= '<span class="field-value">' . $formatted_val . '</span>';
        }
        $html .= '</div>';
    }

    if (!empty($node['id'])) {
        $rendered_nodes[$node['id']] = true;
    }

    return '<div class="node-rendered-content">' . $html . '</div>';
}

function render_field(array $node, string $field_name): string {
    $content_type = get_content_type($node['type']);
    if (!$content_type || empty($content_type['fields_schema'])) {
        return '';
    }

    $fields_data = $node['fields_data'] ?? [];
    if (!isset($fields_data[$field_name]) || $fields_data[$field_name] === '') {
        return '';
    }

    foreach ($content_type['fields_schema'] as $f) {
        if ($f['name'] === $field_name) {
            $formatted_val = format_field_value($f, $fields_data[$field_name]);
            $disp = $f['label_display'] ?? 'inline';
            $label_safe = htmlspecialchars($f['label']);

            if ($disp === 'hidden') {
                return '<div class="field-value">' . $formatted_val . '</div>';
            } elseif ($disp === 'above') {
                return '<div class="field-label" style="font-weight:700; color:var(--frnp-navy);">' . $label_safe . '</div><div class="field-value">' . $formatted_val . '</div>';
            } else {
                return '<strong>' . $label_safe . ':</strong> ' . $formatted_val;
            }
        }
    }
    return '';
}

function format_field_value(array $field, mixed $val): string {
    $type = $field['type'] ?? 'text';

    switch ($type) {
        case 'body':
        case 'html':
        case 'rich_text':
            return check_markup((string)$val, 'filtered_html');

        case 'taxonomy_term':
        case 'taxonomy':
            if (empty($val)) {
                return '';
            }
            $tids = is_array($val) ? $val : array_filter(array_map('trim', explode(',', (string)$val)));
            if (empty($tids)) {
                return '';
            }
            $all_terms = get_all_taxonomy_terms();
            $term_map = [];
            foreach ($all_terms as $t) {
                $term_map[(int)$t['id']] = $t;
            }
            $pills = [];
            foreach ($tids as $tid) {
                $tid_int = (int)$tid;
                if (isset($term_map[$tid_int])) {
                    $term = $term_map[$tid_int];
                    $url = base_path('term/' . htmlspecialchars($term['slug']));
                    $pills[] = '<a href="' . $url . '" class="tag-pill" style="display:inline-block; background:#e2e8f0; color:#0b2545; padding:3px 10px; border-radius:12px; font-size:0.82rem; text-decoration:none; margin-right:6px; margin-bottom:4px; font-weight:600;">' . htmlspecialchars($term['name']) . '</a>';
                }
            }
            return implode(' ', $pills);

        case 'image':
            return '<img src="' . htmlspecialchars((string)$val) . '" alt="" style="max-width: 100%; height: auto; border-radius: 4px; border: 1px solid #e2e8f0; margin-top: 5px;">';

        case 'video':
            $raw_val = trim((string)$val);
            if ($raw_val === '') {
                return '';
            }
            $yt_id = get_youtube_id($raw_val);
            if ($yt_id) {
                return '<div class="video-embed-wrapper" style="position:relative; padding-bottom:56.25%; height:0; overflow:hidden; max-width:100%; border-radius:6px; margin-top:8px; box-shadow:0 2px 8px rgba(0,0,0,0.1);">'
                     . '<iframe src="https://www.youtube-nocookie.com/embed/' . htmlspecialchars($yt_id) . '" '
                     . 'style="position:absolute; top:0; left:0; width:100%; height:100%; border:0;" '
                     . 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" '
                     . 'allowfullscreen '
                     . 'loading="lazy" '
                     . 'title="' . htmlspecialchars($field['label'] ?? 'Video') . '">'
                     . '</iframe>'
                     . '</div>';
            }
            // Fallback for non-YouTube URLs: render as clickable link
            if (filter_var($raw_val, FILTER_VALIDATE_URL)) {
                return '<a href="' . htmlspecialchars($raw_val) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($raw_val) . '</a>';
            }
            return htmlspecialchars($raw_val);
            
        case 'file':
            $filename = basename((string)$val);
            return '<a class="file-link btn-download" href="' . htmlspecialchars((string)$val) . '" target="_blank">' . htmlspecialchars($filename) . '</a>';

        case 'date':
            return '<time>' . date('F j, Y', strtotime((string)$val)) . '</time>';

        case 'select':
            $opts = $field['options'] ?? [];
            $display_text = $opts[$val] ?? $val;
            return htmlspecialchars((string)$display_text);

        case 'boolean':
            return !empty($val) ? '<span class="badge" style="background:#2e7d32;">Yes</span>' : '<span class="badge" style="background:#64748b;">No</span>';

        case 'textarea':
        case 'text':
        case 'number':
        default:
            $raw = (string)$val;
            $fname = strtolower(trim((string)($field['name'] ?? '')));
            
            // Render HTML if explicitly named description or if HTML tags are present
            if ($fname === 'description' || preg_match('/<[a-z][\s\S]*>/i', $raw)) {
                return check_markup($raw, 'filtered_html');
            }

            return ($type === 'textarea') ? nl2br(htmlspecialchars($raw)) : htmlspecialchars($raw);
    }
}

function get_menu_tree(string $menu_name = 'main'): array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM menu_items WHERE menu_name = :m ORDER BY weight ASC, id ASC');
    $stmt->execute(['m' => $menu_name]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tree = [];
    $lookup = [];

    foreach ($items as $item) {
        $item['children'] = [];
        $raw_url = $item['url'];
        if (!str_starts_with($raw_url, 'http://') && !str_starts_with($raw_url, 'https://')) {
            $clean_slug = preg_replace('#^/?cms/#', '', ltrim($raw_url, '/'));
            $item['url'] = base_path($clean_slug);
        }
        $lookup[(int)$item['id']] = $item;
    }

    foreach ($lookup as $id => $item) {
        $pid = !empty($item['parent_id']) ? (int)$item['parent_id'] : 0;
        if ($pid > 0 && isset($lookup[$pid])) {
            $lookup[$pid]['children'][] = &$lookup[$id];
        }
    }

    foreach ($lookup as $id => $item) {
        $pid = !empty($item['parent_id']) ? (int)$item['parent_id'] : 0;
        if ($pid === 0 || !isset($lookup[$pid])) {
            $tree[] = $lookup[$id];
        }
    }

    return $tree;
}

function get_top_level_menu_items(string $menu_name = 'main', ?int $exclude_node_id = null): array {
    $db = get_db();

    try {
        $check = $db->query("SHOW COLUMNS FROM menu_items LIKE 'parent_id'")->fetch();
        if (!$check) {
            $db->exec("ALTER TABLE menu_items ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL AFTER weight, ADD INDEX (parent_id)");
        }
    } catch (\Throwable $e) {}

    $sql = 'SELECT id, title FROM menu_items WHERE menu_name = :m AND (parent_id IS NULL OR parent_id = 0)';
    $params = ['m' => $menu_name];

    if ($exclude_node_id !== null) {
        $sql .= ' AND (node_id IS NULL OR node_id != :ex_nid)';
        $params['ex_nid'] = $exclude_node_id;
    }

    $sql .= ' ORDER BY weight ASC, title ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_node_menu_link(int $node_id, string $menu_name = 'main'): ?array {
    $db = get_db();
    $stmt = $db->prepare('SELECT * FROM menu_items WHERE node_id = :nid AND menu_name = :m LIMIT 1');
    $stmt->execute(['nid' => $node_id, 'm' => $menu_name]);
    $link = $stmt->fetch();
    return $link ?: null;
}

function save_node_menu_link(int $node_id, string $slug, string $title, bool $enabled, ?int $parent_id = null, int $weight = 0, string $menu_name = 'main'): void {
    $db = get_db();
    $existing = get_node_menu_link($node_id, $menu_name);
    $url = '/' . ltrim($slug, '/');

    if ($enabled) {
        $link_title = trim($title) !== '' ? trim($title) : 'Page';
        $p_id = ($parent_id && $parent_id > 0) ? $parent_id : null;
        if ($existing) {
            $stmt = $db->prepare('UPDATE menu_items SET title = :t, url = :u, parent_id = :pid, weight = :w WHERE id = :id');
            $stmt->execute(['t' => $link_title, 'u' => $url, 'pid' => $p_id, 'w' => $weight, 'id' => $existing['id']]);
        } else {
            $stmt = $db->prepare('INSERT INTO menu_items (menu_name, node_id, title, url, parent_id, weight) VALUES (:m, :nid, :t, :u, :pid, :w)');
            $stmt->execute(['m' => $menu_name, 'nid' => $node_id, 't' => $link_title, 'u' => $url, 'pid' => $p_id, 'w' => $weight]);
        }
    } else {
        if ($existing) {
            $db->prepare('UPDATE menu_items SET parent_id = NULL WHERE parent_id = ?')->execute([$existing['id']]);
            $db->prepare('DELETE FROM menu_items WHERE id = ?')->execute([$existing['id']]);
        }
    }
    require_once __DIR__ . '/cache.php';
    cache_clear();
}

/**
 * Sets a session flash message to persist across a PRG redirect.
 */
function set_flash(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_messages'][] = [
        'type'    => $type,
        'message' => $message
    ];
}

/**
 * Retrieves and clears all queued session flash messages.
 */
function get_flashes(): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $flashes = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $flashes;
}

/**
 * Renders queued flash messages as HTML notification blocks.
 */
function render_flash_messages(): void {
    $flashes = get_flashes();
    if (empty($flashes)) {
        return;
    }
    foreach ($flashes as $f) {
        $type = htmlspecialchars($f['type'] ?? 'info');
        $msg  = htmlspecialchars($f['message'] ?? '');
        $is_success = ($type === 'success');
        $border = $is_success ? '#059669' : '#dc2626';
        $bg     = $is_success ? '#ecfdf5' : '#fef2f2';
        $color  = $is_success ? '#065f46' : '#991b1b';
        
        echo "<div class=\"flash-message flash-{$type}\" style=\"margin-bottom: 20px; padding: 12px 18px; border-radius: 4px; font-size: 0.92rem; border-left: 4px solid {$border}; background: {$bg}; color: {$color};\">{$msg}</div>";
    }
}
require_once __DIR__ . '/views.php';

function render_filtered_html(string $text): string {
    // 1. Process custom Views tokens
    $text = replace_view_tags($text);

    // 2. Process Registration forms
    if (function_exists('replace_registration_form_tags')) {
        $text = replace_registration_form_tags($text);
    }

    return $text;
}
/**
 * Extracts plain text from the node body (or dynamic fields data) and trims to length.
 */
function get_node_teaser(array $node, int $length = 130): string {
    $raw = $node['body'] ?? '';

    // Fallback to fields_data if body column is empty on this row
    if (trim((string)$raw) === '' && !empty($node['fields_data'])) {
        $fd = is_array($node['fields_data'])
            ? $node['fields_data']
            : (json_decode((string)$node['fields_data'], true) ?: []);
        $raw = $fd['body'] ?? $fd['field_body'] ?? $fd['body_value'] ?? $fd['teaser'] ?? '';
    }

    // Decode HTML entities (&lt;p&gt;, &amp;, &nbsp;, etc.)
    $text = html_entity_decode((string)$raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Strip HTML and PHP tags
    $text = strip_tags($text);

    // Replace non-breaking spaces and invisible characters with standard spaces
    $text = str_replace(["\xc2\xa0", '&nbsp;', '&#160;'], ' ', $text);

    // Collapse multi-line whitespace and trim edges
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));

    if ($text === '') {
        return '';
    }

    return mb_strimwidth($text, 0, $length, '...');
}
/**
 * Retrieves up to $limit similar nodes based on shared taxonomy terms or content type.
 */
function get_similar_nodes(array $node, int $limit = 2): array {
    $current_id = (int)($node['id'] ?? 0);
    if ($current_id <= 0) {
        return [];
    }

    $db = get_db();
    $content_type = (string)($node['type'] ?? '');
    $limit = max(1, min(10, $limit));

    $terms = get_node_terms($current_id);
    $term_ids = array_filter(array_map('intval', array_column($terms, 'id')));

    $similar = [];
    $seen_ids = [$current_id];

    // 1. Primary Strategy: Match content sharing one or more taxonomy terms
    if (!empty($term_ids)) {
        $placeholders = implode(',', array_fill(0, count($term_ids), '?'));
        $sql = "
            SELECT n.id, n.type, n.title, n.slug, n.body, n.fields_data, n.created_at, COUNT(DISTINCT nt.term_id) AS matched_terms
            FROM nodes n
            JOIN node_terms nt ON nt.node_id = n.id
            WHERE nt.term_id IN ({$placeholders})
              AND n.id != ?
              AND (n.status = 'published' OR n.status = '1' OR n.status = 1)
            GROUP BY n.id, n.type, n.title, n.slug, n.created_at
            ORDER BY matched_terms DESC, n.created_at DESC
            LIMIT " . (int)$limit;

        $params = array_merge($term_ids, [$current_id]);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $nid = (int)$r['id'];
            if (!in_array($nid, $seen_ids, true)) {
                $similar[] = $r;
                $seen_ids[] = $nid;
            }
        }
    } 
    // 2. Untagged Nodes Only: If the active node has NO taxonomy terms at all, find recent nodes of the same type
    elseif ($content_type !== '') {
        $sql = "
            SELECT n.id, n.type, n.title, n.slug, n.body, n.fields_data, n.created_at
            FROM nodes n
            WHERE n.type = ?
              AND n.id != ?
              AND (n.status = 'published' OR n.status = '1' OR n.status = 1)
            ORDER BY n.created_at DESC
            LIMIT " . (int)$limit;

        $stmt = $db->prepare($sql);
        $stmt->execute([$content_type, $current_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $nid = (int)$r['id'];
            if (!in_array($nid, $seen_ids, true)) {
                $similar[] = $r;
                $seen_ids[] = $nid;
            }
        }
    }

    return $similar;
}

/**
 * Renders the HTML markup for the Similar Content widget.
 */
function render_similar_content(?array $node, int $limit = 2): string {
    if (!$node) {
        return '';
    }

    $items = get_similar_nodes($node, $limit);
    if (empty($items)) {
        return '';
    }

    ob_start();
    ?>
    <div class="similar-content-block">
        <?php foreach ($items as $idx => $item): ?>
            <?php
                $teaser = get_node_teaser($item, 130);
                $article_url = base_path($item['slug']);
                $border_style = ($idx < count($items) - 1) ? 'border-bottom: 1px solid #e2e8f0; padding-bottom: 14px; margin-bottom: 14px;' : '';
            ?>
            <article class="similar-article-item" style="<?= $border_style ?>">
                <h4 style="margin: 0 0 4px 0; font-size: 0.98rem; line-height: 1.35;">
                    <a href="<?= htmlspecialchars($article_url) ?>" style="color: var(--frnp-navy, #0b2545); text-decoration: none; font-weight: 700;">
                        <?= htmlspecialchars($item['title']) ?>
                    </a>
                </h4>
                <div style="font-size: 0.78rem; color: #64748b; margin-bottom: 6px;">
                    <?= date('F j, Y', strtotime($item['created_at'])) ?>
                </div>
                <?php if ($teaser !== ''): ?>
                    <p style="margin: 0 0 8px 0; font-size: 0.86rem; color: #334155; line-height: 1.45;">
                        <?= htmlspecialchars($teaser) ?>
                    </p>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($article_url) ?>" style="display: inline-block; font-size: 0.82rem; font-weight: 700; color: var(--frnp-navy, #0b2545); text-decoration: underline;">
                    Read More &raquo;
                </a>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Parses [similar_content] or [similar_content:N] tags inside block markup.
 */
function replace_similar_content_tags(string $text, ?array $node = null): string {
    return (string)preg_replace_callback('/\[(?:similar_content|related_articles)(?::(\d+))?\]/i', function($matches) use ($node) {
        $limit = !empty($matches[1]) ? (int)$matches[1] : 2;
        return render_similar_content($node, $limit);
    }, $text);
}
/**
 * Extracts the 11-character YouTube video ID from various URL formats
 * (standard watch URLs, short URLs, embeds, shorts, or raw IDs).
 */
function get_youtube_id(string $url): ?string {
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    // 1. Standard query parameter: ?v=VIDEO_ID or &v=VIDEO_ID
    if (preg_match('/[?&]v=([a-zA-Z0-9_-]{11})/i', $url, $matches)) {
        return $matches[1];
    }

    // 2. Short URLs, embed URLs, shorts, or v/: youtu.be/VIDEO_ID, youtube.com/embed/VIDEO_ID, youtube.com/shorts/VIDEO_ID
    if (preg_match('#(?:youtu\.be/|youtube\.com/(?:embed/|v/|shorts/))([a-zA-Z0-9_-]{11})#i', $url, $matches)) {
        return $matches[1];
    }

    // 3. User entered only the 11-character ID directly
    if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $url)) {
        return $url;
    }

    return null;
}