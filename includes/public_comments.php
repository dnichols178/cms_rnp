<?php
// includes/public_comments.php

function get_comments_settings($pdo) {
    $stmt = $pdo->query("SELECT * FROM public_comments_settings WHERE id = 1 LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$settings) {
        return [
            'id' => 1,
            'title' => 'Public Comments',
            'description' => '',
            'start_date' => date('Y-m-d H:i:s'),
            'end_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'is_enabled' => 1,
            'closed_message' => 'The public comment period has concluded.'
        ];
    }
    return $settings;
}

function check_comment_period_status($settings) {
    if (empty($settings['is_enabled'])) {
        return ['allowed' => false, 'reason' => 'disabled'];
    }
    $now = time();
    $start = strtotime($settings['start_date']);
    $end = strtotime($settings['end_date']);

    if ($now < $start) {
        return [
            'allowed' => false, 
            'reason' => 'not_started',
            'message' => 'The public comment period will open on ' . date('F j, Y \a\t g:i A T', $start) . '.'
        ];
    }
    if ($now > $end) {
        return [
            'allowed' => false, 
            'reason' => 'closed',
            'message' => !empty($settings['closed_message']) 
                ? $settings['closed_message'] 
                : 'The public comment period closed on ' . date('F j, Y \a\t g:i A T', $end) . '.'
        ];
    }
    return ['allowed' => true, 'end_timestamp' => $end];
}

function get_visitor_geolocation($ip) {
    if (in_array($ip, ['127.0.0.1', '::1']) || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return [
            'country' => 'Local Network',
            'region' => 'Internal',
            'city' => 'Localhost',
            'org' => 'Private IP / DMZ'
        ];
    }

    $ch = curl_init("http://ip-api.com/json/" . urlencode($ip) . "?fields=status,country,regionName,city,org");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $json = json_decode($response, true);
        if (!empty($json['status']) && $json['status'] === 'success') {
            return [
                'country' => $json['country'] ?? 'Unknown',
                'region'  => $json['regionName'] ?? 'Unknown',
                'city'    => $json['city'] ?? 'Unknown',
                'org'     => $json['org'] ?? 'Unknown'
            ];
        }
    }

    return [
        'country' => 'Unknown',
        'region'  => 'Unknown',
        'city'    => 'Unknown',
        'org'     => 'Unknown'
    ];
}
