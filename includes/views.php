<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/auth.php';

// Auto-heal schema for views table
try {
    $db_init = get_db();
    $has_views = $db_init->query("SHOW TABLES LIKE 'views'")->fetch();
    if (!$has_views) {
        $db_init->exec("
            CREATE TABLE IF NOT EXISTS `views` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(128) NOT NULL,
                `machine_name` VARCHAR(64) NOT NULL UNIQUE,
                `description` TEXT NULL,
                `base_table` VARCHAR(64) NOT NULL DEFAULT 'nodes',
                `display_type` VARCHAR(20) NOT NULL DEFAULT 'block',
                `path` VARCHAR(255) NULL,
                `block_id` INT UNSIGNED NULL,
                `configuration` LONGTEXT NOT NULL,
                `status` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX (`machine_name`),
                INDEX (`status`),
                INDEX (`path`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
} catch (\Throwable $e) {}

/**
 * Fetch a single view by machine name, path, or ID.
 */
function get_view(int|string $identifier): ?array {
    $db = get_db();
    if (is_numeric($identifier)) {
        $stmt = $db->prepare('SELECT * FROM views WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int)$identifier]);
    } else {
        $clean_id = trim((string)$identifier, '/');
        // Unique placeholders prevent PDO HY093 errors when emulate prepares is false
        $stmt = $db->prepare('SELECT * FROM views WHERE machine_name = :mname OR path = :path LIMIT 1');
        $stmt->execute([
            'mname' => $clean_id,
            'path'  => $clean_id
        ]);
    }
    $view = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($view) {
        $view['configuration'] = json_decode($view['configuration'] ?? '{}', true) ?: [];
    }
    return $view ?: null;
}

/**
 * Fetch all views.
 */
function get_all_views(bool $active_only = false): array {
    $db = get_db();
    $sql = 'SELECT * FROM views';
    if ($active_only) {
        $sql .= ' WHERE status = 1';
    }
    $sql .= ' ORDER BY name ASC';
    $views = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($views as &$v) {
        $v['configuration'] = json_decode($v['configuration'] ?? '{}', true) ?: [];
    }
    unset($v);
    return $views;
}

/**
 * Introspect available variables based on selected base table and content type.
 */
function get_available_views_variables(string $base_table = 'nodes', string $content_type = 'all'): array {
    if ($base_table === 'users') {
        return [
            'id'         => ['label' => 'User ID', 'type' => 'number', 'group' => 'User Properties'],
            'username'   => ['label' => 'Username', 'type' => 'text', 'group' => 'User Properties'],
            'email'      => ['label' => 'Email Address', 'type' => 'text', 'group' => 'User Properties'],
            'status'     => ['label' => 'Account Status', 'type' => 'status', 'group' => 'User Properties'],
            'created_at' => ['label' => 'Registration Date', 'type' => 'date', 'group' => 'User Properties']
        ];
    }

    if ($base_table === 'taxonomy_terms') {
        return [
            'id'          => ['label' => 'Term ID', 'type' => 'number', 'group' => 'Taxonomy Properties'],
            'name'        => ['label' => 'Term Name', 'type' => 'text', 'group' => 'Taxonomy Properties'],
            'slug'        => ['label' => 'URL Slug', 'type' => 'text', 'group' => 'Taxonomy Properties'],
            'description' => ['label' => 'Term Description', 'type' => 'textarea', 'group' => 'Taxonomy Properties']
        ];
    }

    // Default: Content (Nodes)
    $variables = [
        'title'         => ['label' => 'Title', 'type' => 'text', 'group' => 'Content', 'description' => 'The title of the content item.'],
        'created_at'    => ['label' => 'Authored on', 'type' => 'date', 'group' => 'Content', 'description' => 'The date and time that the content was created.'],
        'updated_at'    => ['label' => 'Updated on', 'type' => 'date', 'group' => 'Content', 'description' => 'The date and time that the content was last updated.'],
        'status'        => ['label' => 'Published', 'type' => 'status', 'group' => 'Content', 'description' => 'Whether the content is published or unpublished.'],
        'type'          => ['label' => 'Content type', 'type' => 'type', 'group' => 'Content', 'description' => 'The content type of the content.'],
        'author_name'   => ['label' => 'Authored by', 'type' => 'author', 'group' => 'Content', 'description' => 'The author who published or created the item.'],
        'taxonomy_term' => ['label' => 'Taxonomy terms', 'type' => 'taxonomy', 'group' => 'Taxonomy', 'description' => 'Taxonomy categories associated with the item.'],
        'body'          => ['label' => 'Body', 'type' => 'textarea', 'group' => 'Content', 'description' => 'The body or main text content.'],
        'id'            => ['label' => 'Node ID', 'type' => 'number', 'group' => 'Content', 'description' => 'Unique numerical identifier.']
    ];

    $all_content_types = get_content_types();
    foreach ($all_content_types as $ct) {
        if ($content_type !== 'all' && $ct['type'] !== $content_type) {
            continue;
        }
        $schema = json_decode($ct['fields_schema'] ?? '[]', true) ?: [];
        foreach ($schema as $f) {
            $fname = $f['name'] ?? '';
            if ($fname !== '' && !isset($variables[$fname])) {
                $variables[$fname] = [
                    'label'       => ($f['label'] ?? $fname) . ' [' . $ct['name'] . ']',
                    'type'        => $f['type'] ?? 'text',
                    'group'       => 'Custom Fields',
                    'description' => 'Custom field from ' . $ct['name'] . ' schema.'
                ];
            }
        }
    }

    return $variables;
}

/**
 * Formats a field's value honoring formatters, style stripping, HTML rendering, and tokens.
 */
function views_format_field_value(array $fld_cfg, array $row): string {
    $fname = $fld_cfg['name'] ?? 'title';
    $val = $row[$fname] ?? ($row['fields_data'][$fname] ?? '');

    if (is_array($val)) {
        $val = implode(', ', $val);
    }
    $val = (string)$val;

    // Output Rewriting (Tokens)
    if (!empty($fld_cfg['rewrite_enabled']) && !empty($fld_cfg['rewrite_template'])) {
        $val = views_replace_tokens((string)$fld_cfg['rewrite_template'], $row);
    } else {
        $formatter = $fld_cfg['formatter'] ?? ($fname === 'body' ? 'html' : 'plain_text');

        switch ($formatter) {
            case 'html':
                // Rendered HTML directly without escaping markup
                break;

            case 'plain_text':
                $val = htmlspecialchars(strip_tags($val));
                break;

            case 'trimmed':
                $length = max(1, (int)($fld_cfg['trim_length'] ?? 150));
                $val = htmlspecialchars(mb_strimwidth(strip_tags($val), 0, $length, '...'));
                break;

            case 'date':
                $fmt = !empty($fld_cfg['date_format']) ? $fld_cfg['date_format'] : 'F j, Y';
                $val = !empty($val) && strtotime($val) ? date($fmt, strtotime($val)) : $val;
                $val = htmlspecialchars((string)$val);
                break;

            case 'image':
                if (!empty($val)) {
                    $img_src = str_starts_with($val, 'http') ? $val : base_path($val);
                    $val = '<img src="' . htmlspecialchars($img_src) . '" alt="" style="max-width:100%; height:auto; border-radius:4px;">';
                }
                break;

            case 'link':
                $url = str_starts_with($val, 'http') ? $val : base_path($val);
                $val = '<a href="' . htmlspecialchars($url) . '" target="_blank">' . htmlspecialchars($val) . '</a>';
                break;

            default:
                if ($fname !== 'body') {
                    $val = htmlspecialchars($val);
                }
                break;
        }
    }

    // Strip Styles
    if (!empty($fld_cfg['strip_styles'])) {
        $val = (string)preg_replace('/\s*style\s*=\s*(["\'])(.*?)\1/i', '', $val);
    }

    // Empty Handling
    $is_empty = ($val === '' || $val === null);
    if ($is_empty && !empty($fld_cfg['hide_empty'])) {
        return '';
    }
    if ($is_empty && !empty($fld_cfg['empty_text'])) {
        $val = htmlspecialchars($fld_cfg['empty_text']);
    }

    // Link to Original Content Node
    if (!empty($fld_cfg['link_to_node']) && !empty($row['slug'])) {
        $val = '<a href="' . base_path($row['slug']) . '">' . $val . '</a>';
    }

    // Custom Wrapper Element, ID, and Class
    $custom_class = trim((string)($fld_cfg['custom_class'] ?? ''));
    $custom_id    = trim((string)($fld_cfg['custom_id'] ?? ''));
    $wrapper_tag  = !empty($fld_cfg['wrapper_tag']) 
        ? preg_replace('/[^a-z0-9_-]/i', '', strtolower(trim((string)$fld_cfg['wrapper_tag']))) 
        : 'p';

    // Apply wrapper if a custom class, ID, or explicit wrapper tag is defined
    if ($custom_class !== '' || $custom_id !== '' || !empty($fld_cfg['wrapper_tag'])) {
        $id_attr    = ($custom_id !== '') ? ' id="' . htmlspecialchars($custom_id, ENT_QUOTES, 'UTF-8') . '"' : '';
        $class_attr = ($custom_class !== '') ? ' class="' . htmlspecialchars($custom_class, ENT_QUOTES, 'UTF-8') . '"' : '';
        $val        = "<{$wrapper_tag}{$id_attr}{$class_attr}>{$val}</{$wrapper_tag}>";
    }

    return $val;
}

/**
 * Replace tokens in template text.
 */
function views_replace_tokens(string $template, array $row): string {
    $tokens = [];
    foreach ($row as $k => $v) {
        if (is_scalar($v)) {
            $tokens["{{ {$k} }}"] = (string)$v;
            $tokens["[{$k}]"] = (string)$v;
        }
    }
    if (!empty($row['fields_data']) && is_array($row['fields_data'])) {
        foreach ($row['fields_data'] as $fk => $fv) {
            $flat_val = is_array($fv) ? implode(', ', $fv) : (string)$fv;
            $tokens["{{ {$fk} }}"] = $flat_val;
            $tokens["[{$fk}]"] = $flat_val;
        }
    }
    $tokens['{{ url }}'] = base_path($row['slug'] ?? '');
    $tokens['[url]'] = base_path($row['slug'] ?? '');

    return str_replace(array_keys($tokens), array_values($tokens), $template);
}

/**
 * Compiles and executes dynamic Views SQL query with rich operators.
 */
function execute_view_query(array $view, string $display_id = '', array $exposed_input = []): array {
    $db = get_db();
    $cfg = $view['configuration'];
    $displays = $cfg['displays'] ?? [];

    if ($display_id !== '' && isset($displays[$display_id])) {
        $disp = $displays[$display_id];
    } elseif (isset($displays['default'])) {
        $disp = $displays['default'];
    } elseif (isset($displays['block'])) {
        $disp = $displays['block'];
    } else {
        $disp = reset($displays) ?: [];
    }

    $base_table = $cfg['base_table'] ?? ($view['base_table'] ?? 'nodes');
    $content_type = $cfg['content_type'] ?? 'all';
    $filters = $disp['filters'] ?? [];
    $sorts = $disp['sorts'] ?? [['field' => 'created_at', 'order' => 'DESC']];
    $items_per_page = max(0, (int)($disp['items_per_page'] ?? 10));
    $use_pager = !empty($disp['use_pager']) && $items_per_page > 0;
    $offset_base = max(0, (int)($disp['offset'] ?? 0));

    $where = ['1=1'];
    $params = [];

    if ($base_table === 'users') {
        $from_sql = "FROM users u";
        $select_cols = ['u.id', 'u.username AS title', 'u.username', 'u.email', 'u.status', 'u.created_at'];
    } elseif ($base_table === 'taxonomy_terms') {
        $from_sql = "FROM taxonomy_terms t LEFT JOIN taxonomy_vocabularies v ON v.id = t.vocabulary_id";
        $select_cols = ['t.id', 't.name AS title', 't.name', 't.slug', 't.description AS body', 'v.name AS vocabulary_name'];
    } else {
        // Base Table: nodes
        $from_sql = "FROM nodes n LEFT JOIN users u ON u.id = n.uid";
        $select_cols = [
            'n.id', 'n.type', 'n.title', 'n.slug', 'n.body',
            'n.fields_data', 'n.uid', 'n.status', 'n.created_at', 'n.updated_at',
            'COALESCE(u.username, "Staff") AS author_name'
        ];

        if ($content_type !== 'all' && $content_type !== '') {
            $where[] = 'n.type = :wiz_type';
            $params['wiz_type'] = $content_type;
        }
    }

    // Dynamic Filter Processing with Drupal-style Operators
    foreach ($filters as $idx => $f) {
        $fld = $f['field'] ?? '';
        $op = $f['operator'] ?? '=';
        $val = $f['value'] ?? '';
        $val_type = $f['value_type'] ?? 'date';
        $exposed = !empty($f['exposed']);

        // Check if visitor overridden via exposed input
        if ($exposed && isset($exposed_input['flt_' . $idx]) && $exposed_input['flt_' . $idx] !== '') {
            $val = $exposed_input['flt_' . $idx];
        }

        // Relative Date Offset Handling (e.g., "+1 day", "-7 days")
        if (in_array($fld, ['created_at', 'updated_at'], true) && $val_type === 'offset' && is_string($val) && trim($val) !== '') {
            $ts = strtotime(trim($val));
            if ($ts !== false) {
                $val = date('Y-m-d H:i:s', $ts);
            }
        }

        // Published / Status normalization
        if ($fld === 'status') {
            if ($val === 'any' || $val === '') {
                continue;
            }
            if ($val === 'yes' || $val === 'published' || $val === '1') {
                $val = 'published';
            } elseif ($val === 'no' || $val === 'draft' || $val === '0') {
                $val = 'draft';
            }
        }

        // Taxonomy Term Relation Filter
        if ($fld === 'taxonomy_term') {
            if ($val === '' || $val === 'all') continue;
            $param_key = 'tax_' . $idx;
            if ($op === '!=') {
                $where[] = "NOT EXISTS (SELECT 1 FROM node_terms nt WHERE nt.node_id = n.id AND nt.term_id = :{$param_key})";
            } else {
                $where[] = "EXISTS (SELECT 1 FROM node_terms nt WHERE nt.node_id = n.id AND nt.term_id = :{$param_key})";
            }
            $params[$param_key] = (int)$val;
            continue;
        }

        // Authored by: use distinct parameter names for username and uid
        if ($fld === 'author_name' || $fld === 'uid') {
            if ($val === '' || $val === 'all') continue;
            $param_user = 'auth_u_' . $idx;
            $param_uid  = 'auth_id_' . $idx;
            $where[] = ($op === '!=')
                ? "(u.username != :{$param_user} AND n.uid != :{$param_uid})"
                : "(u.username = :{$param_user} OR n.uid = :{$param_uid})";
            $params[$param_user] = $val;
            $params[$param_uid]  = is_numeric($val) ? (int)$val : 0;
            continue;
        }

        // Empty check exemptions
        if ($val === '' && !in_array($op, ['EMPTY', 'NOT_EMPTY'], true)) {
            continue;
        }

        $param_key = 'fltr_' . $idx;
        $column_sql = in_array($fld, ['id', 'type', 'title', 'slug', 'status', 'created_at', 'updated_at', 'uid'], true)
            ? (($base_table === 'users') ? "u.{$fld}" : (($base_table === 'taxonomy_terms') ? "t.{$fld}" : "n.{$fld}"))
            : "JSON_UNQUOTE(JSON_EXTRACT(n.fields_data, '$.{$fld}'))";

        switch ($op) {
            case '!=':
                $where[] = "{$column_sql} != :{$param_key}";
                $params[$param_key] = $val;
                break;
            case '<':
                $where[] = "{$column_sql} < :{$param_key}";
                $params[$param_key] = $val;
                break;
            case '<=':
                $where[] = "{$column_sql} <= :{$param_key}";
                $params[$param_key] = $val;
                break;
            case '>':
                $where[] = "{$column_sql} > :{$param_key}";
                $params[$param_key] = $val;
                break;
            case '>=':
                $where[] = "{$column_sql} >= :{$param_key}";
                $params[$param_key] = $val;
                break;
            case 'BETWEEN':
                $p_min = $param_key . '_min';
                $p_max = $param_key . '_max';
                $where[] = "{$column_sql} BETWEEN :{$p_min} AND :{$p_max}";
                $params[$p_min] = $f['min'] ?? $val;
                $params[$p_max] = $f['max'] ?? $val;
                break;
            case 'NOT_BETWEEN':
                $p_min = $param_key . '_min';
                $p_max = $param_key . '_max';
                $where[] = "{$column_sql} NOT BETWEEN :{$p_min} AND :{$p_max}";
                $params[$p_min] = $f['min'] ?? $val;
                $params[$p_max] = $f['max'] ?? $val;
                break;
            case 'CONTAINS':
                $where[] = "{$column_sql} LIKE :{$param_key}";
                $params[$param_key] = "%{$val}%";
                break;
            case 'STARTS_WITH':
                $where[] = "{$column_sql} LIKE :{$param_key}";
                $params[$param_key] = "{$val}%";
                break;
            case 'ENDS_WITH':
                $where[] = "{$column_sql} LIKE :{$param_key}";
                $params[$param_key] = "%{$val}";
                break;
            case 'EMPTY':
                $where[] = "({$column_sql} IS NULL OR {$column_sql} = '')";
                break;
            case 'NOT_EMPTY':
                $where[] = "({$column_sql} IS NOT NULL AND {$column_sql} != '')";
                break;
            default: // '='
                $where[] = "{$column_sql} = :{$param_key}";
                $params[$param_key] = $val;
                break;
        }
    }

    $where_sql = implode(' AND ', $where);

    // Count Query for Pagination
    $total_count = 0;
    if ($use_pager) {
        $count_stmt = $db->prepare("SELECT COUNT(*) {$from_sql} WHERE {$where_sql}");
        $count_stmt->execute($params);
        $total_count = (int)$count_stmt->fetchColumn();
    }

    // Sorting
    $order_clauses = [];
    if (!empty($exposed_input['vsort'])) {
        $vsort = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$exposed_input['vsort']);
        $vdir = strtoupper((string)($exposed_input['vorder'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $order_clauses[] = "{$vsort} {$vdir}";
    }

    if (empty($order_clauses)) {
        foreach ($sorts as $s) {
            $sf = $s['field'] ?? 'created_at';
            $sd = strtoupper($s['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
            $prefix = ($base_table === 'users') ? 'u.' : (($base_table === 'taxonomy_terms') ? 't.' : 'n.');
            $order_clauses[] = "{$prefix}{$sf} {$sd}";
        }
    }
    $order_sql = !empty($order_clauses) ? 'ORDER BY ' . implode(', ', $order_clauses) : '';

    // Pagination
    $page = max(1, (int)($exposed_input['vpage'] ?? 1));
    $limit_sql = '';
    if ($items_per_page > 0) {
        $offset = (($page - 1) * $items_per_page) + $offset_base;
        $limit_sql = "LIMIT {$items_per_page} OFFSET {$offset}";
    }

    $sql = "SELECT " . implode(', ', $select_cols) . " {$from_sql} WHERE {$where_sql} {$order_sql} {$limit_sql}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        if (isset($r['fields_data'])) {
            $r['fields_data'] = json_decode($r['fields_data'] ?? '{}', true) ?: [];
        }
    }
    unset($r);

    return [
        'rows'           => $rows,
        'total'          => $total_count ?: count($rows),
        'items_per_page' => $items_per_page,
        'current_page'   => $page,
        'total_pages'    => $items_per_page > 0 ? (int)ceil($total_count / $items_per_page) : 1,
        'sql'            => $sql,
        'params'         => $params
    ];
}

/**
 * Full HTML & JSON renderer for Views.
 */
function render_view(string|array $view_input, string $display_id = '', array $exposed_input = []): string {
    $view = is_array($view_input) ? $view_input : get_view($view_input);
    if (!$view || empty($view['status'])) {
        return '';
    }
    $cfg = $view['configuration'];
    $displays = $cfg['displays'] ?? [];

    // Fallback order: Requested ID -> default -> block -> first display in configuration
    if ($display_id !== '' && isset($displays[$display_id])) {
        $disp = $displays[$display_id];
    } elseif (isset($displays['default'])) {
        $disp = $displays['default'];
        $display_id = 'default';
    } elseif (isset($displays['block'])) {
        $disp = $displays['block'];
        $display_id = 'block';
    } else {
        $disp = reset($displays) ?: [];
        $display_id = (string)(key($displays) ?? 'default');
    }

    $format = $disp['format'] ?? 'cards';
    $fields = $disp['fields'] ?? [
        ['name' => 'title', 'label' => 'Title', 'show_label' => 0, 'link_to_node' => 1, 'formatter' => 'plain_text'],
        ['name' => 'created_at', 'label' => 'Date', 'show_label' => 1, 'formatter' => 'date', 'date_format' => 'M j, Y']
    ];
    $merged_exposed = array_merge($_GET, $exposed_input);
    $query_result = execute_view_query($view, $display_id, $merged_exposed);
    $rows = $query_result['rows'];

    if ($format === 'json' || ($merged_exposed['format'] ?? '') === 'json') {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        return (string)json_encode([
            'view'  => $view['machine_name'],
            'total' => $query_result['total'],
            'page'  => $query_result['current_page'],
            'data'  => $rows
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    ob_start();
    ?>
    <div class="frnp-view-wrapper frnp-view-<?= htmlspecialchars($view['machine_name']) ?>">
        <?php if (!empty($disp['display_title'])): ?>
    <h2 class="view-header-title"><?= htmlspecialchars($disp['display_title']) ?></h2>
<?php endif; ?>

        <!-- Exposed Filters Form -->
        <?php 
            $exposed_filters_to_render = array_filter($disp['filters'] ?? [], fn($f) => !empty($f['exposed']));
        ?>
        <?php if (!empty($exposed_filters_to_render)): ?>
            <form method="GET" class="view-exposed-filters" style="display:flex; gap:14px; margin-bottom:20px; flex-wrap:wrap; align-items:flex-end; background:#f8fafc; padding:16px; border:1px solid #e2e8f0; border-radius:6px;">
                <?php foreach ($disp['filters'] as $idx => $flt): ?>
                    <?php if (empty($flt['exposed'])) continue; ?>
                    <div style="display:flex; flex-direction:column; gap:4px;">
                        <label style="font-size:0.84rem; font-weight:700; color:#0b2545;">
                            <?= htmlspecialchars(!empty($flt['exposed_label']) ? $flt['exposed_label'] : ucfirst($flt['field'])) ?>
                        </label>
                        <?php if ($flt['field'] === 'status'): ?>
                            <select name="flt_<?= $idx ?>" style="padding:8px 12px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.9rem;">
                                <option value="any">-- Any --</option>
                                <option value="published" <?= ($merged_exposed['flt_' . $idx] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                                <option value="draft" <?= ($merged_exposed['flt_' . $idx] ?? '') === 'draft' ? 'selected' : '' ?>>Unpublished</option>
                            </select>
                        <?php else: ?>
                            <input type="text" name="flt_<?= $idx ?>" value="<?= htmlspecialchars((string)($merged_exposed['flt_' . $idx] ?? '')) ?>" placeholder="Filter value..." style="padding:8px 12px; border:1px solid #cbd5e1; border-radius:4px; font-size:0.9rem;">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <button type="submit" class="btn-primary" style="padding:8px 18px; font-size:0.88rem; cursor:pointer;">Apply</button>
                <a href="<?= strtok($_SERVER["REQUEST_URI"], '?') ?>" class="btn-secondary" style="padding:8px 14px; font-size:0.88rem; text-decoration:none;">Reset</a>
            </form>
        <?php endif; ?>

        <?php if (empty($rows)): ?>
            <div class="view-empty-state" style="padding:25px; text-align:center; color:#64748b; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:6px;">
                <?= htmlspecialchars($disp['empty_text'] ?? 'No records found.') ?>
            </div>
        <?php else: ?>
            <?php if ($format === 'table'): ?>
                <table class="view-table" style="width:100%; border-collapse:collapse; margin-bottom:20px;">
                    <thead>
                        <tr style="border-bottom:2px solid #0b2545; text-align:left;">
                            <?php foreach ($fields as $f): ?>
                                <?php if (!empty($f['exclude_display'])) continue; ?>
                                <th style="padding:10px 12px; font-size:0.88rem; font-weight:700; color:#0b2545;">
                                    <?= !empty($f['show_label']) && !empty($f['label']) ? htmlspecialchars($f['label']) : htmlspecialchars(ucfirst($f['name'])) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr style="border-bottom:1px solid #e2e8f0;">
                                <?php foreach ($fields as $f): ?>
                                    <?php if (!empty($f['exclude_display'])) continue; ?>
                                    <td style="padding:10px 12px; font-size:0.9rem;">
                                        <?= views_format_field_value($f, $r) ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php elseif ($format === 'list'): ?>
                <ul class="view-list" style="margin-left:20px; line-height:1.8;">
                    <?php foreach ($rows as $r): ?>
                        <li>
                            <?php foreach ($fields as $f): ?>
                                <?php if (!empty($f['exclude_display'])) continue; ?>
                                <span class="views-field-item">
                                    <?php if (!empty($f['show_label']) && !empty($f['label'])): ?>
                                        <strong class="views-label"><?= htmlspecialchars($f['label']) ?>:</strong>
                                    <?php endif; ?>
                                    <?= views_format_field_value($f, $r) ?>
                                </span>
                            <?php endforeach; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

            <?php elseif ($format === 'unformatted'): ?>
                <div class="view-unformatted">
                    <?php foreach ($rows as $r): ?>
                        <div class="views-row" style="margin-bottom:18px;">
                            <?php foreach ($fields as $f): ?>
                                <?php if (!empty($f['exclude_display'])) continue; ?>
                                <div class="views-field-row" style="margin-bottom:4px;">
                                    <?php if (!empty($f['show_label']) && !empty($f['label'])): ?>
                                        <strong class="views-label"><?= htmlspecialchars($f['label']) ?>:</strong>
                                    <?php endif; ?>
                                    <?= views_format_field_value($f, $r) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: /* Default: Cards / Grid */ ?>
                <?php $cols = max(1, min(6, (int)($disp['grid_columns'] ?? 3))); ?>
                <div class="view-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(calc(100% / <?= $cols ?> - 20px), 1fr)); gap:20px; margin-bottom:24px;">
                    <?php foreach ($rows as $r): ?>
                        <div class="view-card" style="background:#ffffff; border:1px solid #e2e8f0; border-top:3px solid #0b2545; border-radius:6px; padding:20px; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                            <div>
                                <?php foreach ($fields as $f): ?>
                                    <?php if (!empty($f['exclude_display'])) continue; ?>
                                    <div class="views-field-group" style="margin-bottom:8px;">
                                        <?php if (!empty($f['show_label']) && !empty($f['label'])): ?>
                                            <span style="font-size:0.75rem; text-transform:uppercase; font-weight:700; color:#64748b;"><?= htmlspecialchars($f['label']) ?></span><br>
                                        <?php endif; ?>
                                        <?= views_format_field_value($f, $r) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if (!empty($disp['use_pager']) && $query_result['total_pages'] > 1): ?>
                <div class="view-pagination" style="display:flex; justify-content:center; gap:6px; margin:25px 0;">
                    <?php for ($p = 1; $p <= $query_result['total_pages']; $p++): ?>
                        <?php 
                            $q = $merged_exposed;
                            $q['vpage'] = $p;
                            $page_url = '?' . http_build_query($q);
                            $is_current = ($p === $query_result['current_page']);
                        ?>
                        <a href="<?= $page_url ?>" style="padding:6px 12px; border-radius:4px; text-decoration:none; font-weight:700; font-size:0.85rem; <?= $is_current ? 'background:#0b2545; color:#ffffff;' : 'background:#f1f5f9; color:#334155;' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Filter token processor: Replaces [view:machine_name:display_id] in text.
 */
function replace_view_tags(string $text): string {
    return preg_replace_callback('/\[view:([a-zA-Z0-9_-]+)(?::([a-zA-Z0-9_-]+))?\]/', function($matches) {
        $view_name = trim($matches[1]);
        $display = !empty($matches[2]) ? trim($matches[2]) : '';
        return render_view($view_name, $display);
    }, $text);
}