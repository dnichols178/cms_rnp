<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Record an inbound request for real-time site analytics.
 */
function record_pageview(?array $node = null): void {
    // Exclude API requests, static assets, and administration panel
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if (str_starts_with($uri, base_path('admin')) || str_starts_with($uri, base_path('api'))) {
        return;
    }

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $bot_patterns = '/(bot|crawl|slurp|spider|mediapartners|lighthouse|headlesschrome)/i';
    $device = 'desktop';
    if (preg_match($bot_patterns, $ua)) {
        $device = 'bot';
    } elseif (preg_match('/(mobile|android|iphone|ipod)/i', $ua)) {
        $device = 'mobile';
    } elseif (preg_match('/(ipad|tablet)/i', $ua)) {
        $device = 'tablet';
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $path = trim($_GET['q'] ?? parse_url($uri, PHP_URL_PATH) ?? '', '/');
    if ($path === '' || $path === 'cms' || $path === 'cms/index.php') {
        $path = 'home';
    } else {
        $path = preg_replace('#^cms/#', '', $path);
    }

    $session_hash = md5($ip . ($ua) . date('Y-m-d'));
    $referer = isset($_SERVER['HTTP_REFERER']) ? substr(trim($_SERVER['HTTP_REFERER']), 0, 255) : null;
    $is_staff = (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user'])) ? 1 : 0;
    $node_id = !empty($node['id']) ? (int)$node['id'] : null;

    try {
        $db = get_db();
        $stmt = $db->prepare('
            INSERT INTO analytics_hits 
            (path, node_id, ip_address, user_agent, referer, device_type, session_hash, is_staff)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $path,
            $node_id,
            $ip,
            substr($ua, 0, 255),
            $referer,
            $device,
            $session_hash,
            $is_staff
        ]);
    } catch (\Throwable $e) {
        error_log('Analytics hit failure: ' . $e->getMessage());
    }
}

/**
 * Returns real-time analytics aggregation for the dashboard.
 */
function get_realtime_metrics(): array {
    $db = get_db();
    
    // Active visitors in the last 15 minutes (distinct sessions)
    $active_stmt = $db->prepare('
        SELECT COUNT(DISTINCT session_hash) 
        FROM analytics_hits 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
          AND device_type != "bot"
    ');
    $active_stmt->execute();
    $active_now = (int)$active_stmt->fetchColumn();

    // Today's total views
    $today_stmt = $db->prepare('
        SELECT COUNT(*) 
        FROM analytics_hits 
        WHERE created_at >= CURDATE()
          AND device_type != "bot"
    ');
    $today_stmt->execute();
    $today_views = (int)$today_stmt->fetchColumn();

    // Top active pages in the last 24 hours
    $pages_stmt = $db->prepare('
        SELECT path, COUNT(*) as hits, COUNT(DISTINCT session_hash) as unique_visitors
        FROM analytics_hits 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
          AND device_type != "bot"
        GROUP BY path 
        ORDER BY hits DESC 
        LIMIT 10
    ');
    $pages_stmt->execute();
    $top_pages = $pages_stmt->fetchAll();

    // Device breakdown today
    $dev_stmt = $db->prepare('
        SELECT device_type, COUNT(*) as count 
        FROM analytics_hits 
        WHERE created_at >= CURDATE()
        GROUP BY device_type
    ');
    $dev_stmt->execute();
    $devices = $dev_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Recent referrer traffic
    $ref_stmt = $db->prepare('
        SELECT referer, COUNT(*) as count 
        FROM analytics_hits 
        WHERE referer IS NOT NULL 
          AND referer != "" 
          AND referer NOT LIKE "%' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '%"
          AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY referer 
        ORDER BY count DESC 
        LIMIT 10
    ');
    $ref_stmt->execute();
    $referrers = $ref_stmt->fetchAll();

    return [
        'active_15m' => $active_now,
        'today_views' => $today_views,
        'top_pages' => $top_pages,
        'devices' => $devices,
        'referrers' => $referrers
    ];
}