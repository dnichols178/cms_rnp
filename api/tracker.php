<?php
// api/tracker.php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    http_response_code(400);
    echo json_encode(['error' => 'No payload']);
    exit;
}

$data = json_decode($rawInput, true);
if (!$data || empty($data['visitor_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data']);
    exit;
}

function get_client_ip() {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

$visitorId   = substr($data['visitor_id'], 0, 64);
$sessionId   = !empty($data['session_id']) ? substr($data['session_id'], 0, 64) : null;
$eventType   = in_array($data['event_type'], ['pageview', 'dwell_time', 'link_click', 'file_download']) ? $data['event_type'] : 'pageview';
$pageUrl     = substr($data['page_url'] ?? '', 0, 1000);
$pageTitle   = substr($data['page_title'] ?? '', 0, 255);
$targetUrl   = !empty($data['target_url']) ? substr($data['target_url'], 0, 1000) : null;
$dwellSec    = isset($data['dwell_seconds']) ? (int)$data['dwell_seconds'] : 0;
$referrer    = !empty($data['referrer']) ? substr($data['referrer'], 0, 1000) : null;
$metaData    = !empty($data['meta_data']) ? (is_string($data['meta_data']) ? $data['meta_data'] : json_encode($data['meta_data'])) : null;
$ipAddress   = get_client_ip();

try {
    $stmt = $pdo->prepare("
        INSERT INTO visitor_activity_log 
        (visitor_id, session_id, event_type, page_url, page_title, target_url, dwell_seconds, referrer, ip_address, meta_data, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $visitorId,
        $sessionId,
        $eventType,
        $pageUrl,
        $pageTitle,
        $targetUrl,
        $dwellSec,
        $referrer,
        $ipAddress,
        $metaData
    ]);

    echo json_encode(['status' => 'logged']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database write failed']);
}
