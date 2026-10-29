<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_permission('create_content');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['upload_file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded.']);
    exit;
}

$file = $_FILES['upload_file'];
$allowed = ['pdf', 'docx', 'xlsx', 'png', 'jpg', 'jpeg', 'webp'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowed, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Disallowed file extension. Permitted: ' . implode(', ', $allowed)]);
    exit;
}

$clean_name = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
$filename = sprintf('%s_%s.%s', $clean_name, bin2hex(random_bytes(4)), $ext);
$destination = __DIR__ . '/../uploads/files/' . $filename;

if (move_uploaded_file($file['tmp_name'], $destination)) {
    echo json_encode([
        'success'  => true,
        'filepath' => base_path('uploads/files/' . $filename),
        'filename' => $filename
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to move uploaded file. Check directory permissions.']);
}