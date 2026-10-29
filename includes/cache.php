<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function cache_get(string $cid): ?array {
    $db = get_db();
    $stmt = $db->prepare('SELECT data, expire FROM cache_data WHERE cid = :cid LIMIT 1');
    $stmt->execute(['cid' => $cid]);
    $cached = $stmt->fetch();

    if (!$cached) {
        return null;
    }

    // Check expiration if not permanent (0)
    if ((int)$cached['expire'] !== 0 && time() > (int)$cached['expire']) {
        cache_clear($cid);
        return null;
    }

    return json_decode($cached['data'], true);
}

function cache_set(string $cid, array $data, int $lifetime = 0): void {
    $db = get_db();
    $expire = $lifetime > 0 ? (time() + $lifetime) : 0;
    $json = json_encode($data);

    $stmt = $db->prepare('
        INSERT INTO cache_data (cid, data, expire) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE data = VALUES(data), expire = VALUES(expire)
    ');
    $stmt->execute([$cid, $json, $expire]);
}

function cache_clear(?string $cid = null): void {
    $db = get_db();
    if ($cid === null) {
        $db->exec('TRUNCATE TABLE cache_data');
    } else {
        $stmt = $db->prepare('DELETE FROM cache_data WHERE cid = :cid');
        $stmt->execute(['cid' => $cid]);
    }
}