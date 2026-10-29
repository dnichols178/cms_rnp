<?php
declare(strict_types=1);

require_once __DIR__ . '/content.php';

/**
 * Replace supported tokens in a template pattern string.
 * Dynamically resolves custom content fields, shorthands, and taxonomy terms.
 */
function token_replace(string $pattern, array $data = []): string {
    $node = $data['node'] ?? [];
    $tokens = [];

    // Core Node Tokens
    $tokens['[node:id]']         = (string)($node['id'] ?? '');
    $tokens['[node:title]']      = (string)($node['title'] ?? '');
    $tokens['[node:type]']       = (string)($node['type'] ?? 'page');
    $tokens['[node:author]']     = (string)($node['author_name'] ?? 'admin');

    // Node Timestamps
    $timestamp = !empty($node['created_at']) ? strtotime($node['created_at']) : time();
    $tokens['[node:created:Y]']  = date('Y', $timestamp);
    $tokens['[node:created:m]']  = date('m', $timestamp);
    $tokens['[node:created:d]']  = date('d', $timestamp);

    // Global Site Tokens
    $tokens['[site:name]']       = get_site_setting('site_name', 'FRNP');
    $tokens['[site:slogan]']     = get_site_setting('site_slogan', 'Paducah Deactivation Project');
    $tokens['[current-date:Y]']  = date('Y');
    $tokens['[current-date:m]']  = date('m');
    $tokens['[current-date:d]']  = date('d');

    // Primary Taxonomy Term
    if (!empty($node['terms'][0]['slug'])) {
        $tokens['[term:slug]']   = $node['terms'][0]['slug'];
        $tokens['[term:name]']   = $node['terms'][0]['name'];
    } else {
        $tokens['[term:slug]']   = 'general';
        $tokens['[term:name]']   = 'General';
    }

    // Collective Term Lists
    if (!empty($node['terms']) && is_array($node['terms'])) {
        $term_names = array_column($node['terms'], 'name');
        $term_slugs = array_column($node['terms'], 'slug');
        $tokens['[node:terms]']      = implode(', ', $term_names);
        $tokens['[node:term-slugs]'] = implode(', ', $term_slugs);
    } else {
        $tokens['[node:terms]']      = '';
        $tokens['[node:term-slugs]'] = '';
    }

    // Dynamic Content Type Schema Introspection (Default Fallbacks)
    $type_key = $node['type'] ?? '';
    if ($type_key !== '') {
        $ct = get_content_type($type_key);
        if ($ct && !empty($ct['fields_schema'])) {
            foreach ($ct['fields_schema'] as $f) {
                $fname = $f['name'] ?? '';
                if ($fname === '') continue;
                $tokens["[node:{$fname}]"]       = '';
                $tokens["[node:field:{$fname}]"] = '';
                if (str_starts_with($fname, 'field_')) {
                    $tokens["[node:" . substr($fname, 6) . "]"] = '';
                }
            }
        }
    }

    // Dynamic Values from fields_data
    $fields_data = $node['fields_data'] ?? [];
    if (is_string($fields_data)) {
        $fields_data = json_decode($fields_data, true) ?: [];
    }

    if (is_array($fields_data)) {
        foreach ($fields_data as $key => $val) {
            $val_str = '';
            if (is_array($val)) {
                $val_str = implode(', ', array_map('strval', $val));
            } elseif (is_scalar($val)) {
                $val_str = (string)$val;
            }

            // Register standard and namespaced tokens
            $tokens["[node:{$key}]"]       = $val_str;
            $tokens["[node:field:{$key}]"] = $val_str;

            // Register shorthand alias (e.g. [node:rfp_number] for field_rfp_number)
            if (str_starts_with($key, 'field_')) {
                $short_key = substr($key, 6);
                $tokens["[node:{$short_key}]"] = $val_str;
            }

            // Date Sub-tokens for Custom Date Fields (YYYY-MM-DD)
            if ($val_str !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $val_str) && ($ts = strtotime($val_str)) !== false) {
                $tokens["[node:{$key}:Y]"] = date('Y', $ts);
                $tokens["[node:{$key}:m]"] = date('m', $ts);
                $tokens["[node:{$key}:d]"] = date('d', $ts);
                if (str_starts_with($key, 'field_')) {
                    $tokens["[node:{$short_key}:Y]"] = date('Y', $ts);
                    $tokens["[node:{$short_key}:m]"] = date('m', $ts);
                    $tokens["[node:{$short_key}:d]"] = date('d', $ts);
                }
            }
        }
    }

    return str_replace(array_keys($tokens), array_values($tokens), $pattern);
}

/**
 * Sanitizes a raw string or token-substituted path into a clean, lowercased URL slug.
 */
function pathauto_clean_token_url(string $raw_path): string {
    $segments = explode('/', $raw_path);
    $clean_segments = [];
    foreach ($segments as $segment) {
        $clean = preg_replace('/[^a-zA-Z0-9\s_\-]/', '', $segment) ?? '';
        $clean = preg_replace('/[\s_]+/', '-', trim($clean)) ?? '';
        $clean = strtolower(trim($clean, '-'));
        if ($clean !== '') {
            $clean_segments[] = $clean;
        }
    }
    return implode('/', $clean_segments);
}

/**
 * Builds an automatic URL alias for a node based on its content type pattern.
 */
function generate_pathauto_slug(array $node_data, ?int $node_id = null, ?string $custom_pattern = null): string {
    if (!empty($custom_pattern)) {
        $pattern = trim($custom_pattern);
    } else {
        $content_type = get_content_type($node_data['type']);
        $pattern = trim($content_type['url_pattern'] ?? '');
    }

    if ($pattern === '') {
        $pattern = ($node_data['type'] === 'article') ? 'news/[node:created:Y]/[node:title]' : '[node:title]';
    }

    $raw_alias = token_replace($pattern, ['node' => $node_data]);
    $clean_base = pathauto_clean_token_url($raw_alias);

    if ($clean_base === '') {
        $clean_base = 'node-' . bin2hex(random_bytes(3));
    }

    $db = get_db();
    $slug = $clean_base;
    $counter = 1;

    while (true) {
        $sql = 'SELECT COUNT(*) FROM nodes WHERE slug = :slug';
        $params = ['slug' => $slug];
        if ($node_id !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $node_id;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        if ((int)$stmt->fetchColumn() === 0) {
            break;
        }
        $slug = $clean_base . '-' . $counter;
        $counter++;
    }

    return $slug;
}

/**
 * Returns categorized token definitions for admin documentation and pattern editors.
 * Dynamically aggregates custom fields across all content types when $type_key is omitted.
 */
function get_available_tokens(?string $type_key = null): array {
    $tokens = [
        'Content Properties' => [
            '[node:id]'          => 'Node unique ID',
            '[node:title]'       => 'Node title',
            '[node:type]'        => 'Content type machine name',
            '[node:author]'      => 'Username of author',
            '[node:created:Y]'   => 'Created 4-digit year (e.g. 2026)',
            '[node:created:m]'   => 'Created 2-digit month (01-12)',
            '[node:created:d]'   => 'Created 2-digit day (01-31)',
        ],
        'Taxonomy Terms' => [
            '[term:name]'        => 'Primary taxonomy category name',
            '[term:slug]'        => 'Primary taxonomy URL slug',
            '[node:terms]'       => 'Comma-separated list of all assigned terms',
            '[node:term-slugs]'  => 'Comma-separated list of assigned term slugs',
        ],
        'Site Information' => [
            '[site:name]'        => 'Global site name',
            '[site:slogan]'      => 'Global site tagline/slogan',
            '[current-date:Y]'   => 'Current calendar year',
            '[current-date:m]'   => 'Current calendar month',
        ]
    ];

    // Determine target content types
    $content_types = [];
    if ($type_key !== null && $type_key !== '') {
        $ct = get_content_type($type_key);
        if ($ct) $content_types[] = $ct;
    } else {
        $content_types = get_content_types();
    }

    $custom = [];
    foreach ($content_types as $ct) {
        $raw_schema = $ct['fields_schema'] ?? '[]';
        $schema = is_array($raw_schema) ? $raw_schema : json_decode((string)$raw_schema, true);
        if (!is_array($schema)) continue;

        $ct_label = $ct['name'] ?? $ct['type'];

        foreach ($schema as $f) {
            $fname = $f['name'] ?? '';
            if ($fname === '') continue;

            $flabel = $f['label'] ?? $fname;
            $ftype  = $f['type'] ?? 'text';
            $context = ($type_key !== null) ? "({$ftype})" : "({$ftype}) [{$ct_label}]";

            $custom["[node:{$fname}]"] = "{$flabel} {$context}";

            // Register shorthand display
            if (str_starts_with($fname, 'field_')) {
                $short = substr($fname, 6);
                $custom["[node:{$short}]"] = "{$flabel} (shorthand) [{$ct_label}]";
            }

            if ($ftype === 'date') {
                $custom["[node:{$fname}:Y]"] = "{$flabel} - Year (YYYY) [{$ct_label}]";
                $custom["[node:{$fname}:m]"] = "{$flabel} - Month (MM) [{$ct_label}]";
                $custom["[node:{$fname}:d]"] = "{$flabel} - Day (DD) [{$ct_label}]";
            }
        }
    }

    if (!empty($custom)) {
        $tokens['Custom Content Fields'] = $custom;
    }

    return $tokens;
}