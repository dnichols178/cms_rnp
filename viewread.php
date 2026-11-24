<?php
header('Content-Type: text/plain; charset=utf-8');

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=YOUR_DB_NAME;charset=utf8',
    'DB_USER',
    'DB_PASS',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$vid = 123; // Replace with your VID

$stmt = $pdo->prepare("
    SELECT id AS display_id, display_title, display_plugin, display_options
    FROM views_display
    WHERE vid = ?
    ORDER BY position ASC
");
$stmt->execute([$vid]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "No displays found for VID {$vid}.\n";
    exit;
}

foreach ($rows as $row) {
    echo "==========================================================\n";
    echo "Display ID: {$row['display_id']} | Title: {$row['display_title']} | Plugin: {$row['display_plugin']}\n";
    echo "==========================================================\n";
    
    $options = @unserialize($row['display_options']);
    if ($options === false) {
        echo "Raw display_options (could not unserialize):\n{$row['display_options']}\n\n";
        continue;
    }

    echo "\n--- FIELDS ---\n";
    print_r($options['fields'] ?? 'Inherited from default');

    echo "\n--- FILTERS ---\n";
    print_r($options['filters'] ?? 'Inherited from default');

    echo "\n--- RELATIONSHIPS ---\n";
    print_r($options['relationships'] ?? 'None');

    echo "\n--- SORTS ---\n";
    print_r($options['sorts'] ?? 'Inherited from default');
    echo "\n";
}
