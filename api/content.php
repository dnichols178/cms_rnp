<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/content.php';

$db = get_db();

// 1. Single Node Fetch (?slug=about-us)
if (isset($_GET['slug']) && trim($_GET['slug']) !== '') {
    $node = get_node_by_slug(trim($_GET['slug']), false);
    if (!$node) {
        http_response_code(404);
        echo json_encode(['error' => 'Node not found or unpublished.'], JSON_PRETTY_PRINT);
        exit;
    }
    echo json_encode(['data' => $node], JSON_PRETTY_PRINT);
    exit;
}

// 2. Collection Query (?type=article&limit=10)
$type = trim($_GET['type'] ?? '');
$limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));

$sql = '
    SELECT n.id, n.type, n.title, n.seo_title, n.seo_description, n.seo_focus_keyword, 
           n.slug, n.fields_data, n.created_at, n.updated_at,
           SUBSTRING(REGEXP_REPLACE(COALESCE(n.body, ''), \'<[^>]+>\', \'\'), 1, 260) AS excerpt,
           ct.name AS type_name, u.username AS author
    FROM nodes n
    JOIN content_types ct ON ct.type = n.type
    JOIN users u ON u.id = n.uid
    WHERE n.status = 1
';
$params = [];

if ($type !== '') {
    $sql .= ' AND n.type = :type';
    $params['type'] = $type;
}

$sql .= ' ORDER BY n.created_at DESC LIMIT ' . $limit;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

foreach ($items as &$item) {
    $item['fields_data'] = json_decode($item['fields_data'] ?? '{}', true);
    $item['terms'] = get_node_terms((int)$item['id']);
    $item['canonical_url'] = base_path($item['slug']);
}
unset($item);

echo json_encode([
    'count' => count($items),
    'data'  => $items
], JSON_PRETTY_PRINT);
