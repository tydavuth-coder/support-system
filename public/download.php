<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

// 1. Get ID
$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Invalid ID');

// 2. Get Info from DB
$stmt = $pdo->prepare("SELECT * FROM attachments WHERE id = ?");
$stmt->execute([$id]);
$attachment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$attachment) {
    http_response_code(404);
    die('File record not found in database.');
}

// 3. Construct Path (Windows Friendly)
$projectRoot = dirname(__DIR__); // C:\laragon\www\support-system
$dbPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $attachment['path']); // normalize slashes

// Check if path already starts with 'public'
if (strpos($dbPath, 'public' . DIRECTORY_SEPARATOR) === 0) {
    $relativePath = $dbPath;
} else {
    $relativePath = 'public' . DIRECTORY_SEPARATOR . ltrim($dbPath, DIRECTORY_SEPARATOR);
}

$fullPath = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;

// 4. Verify File Existence
if (!file_exists($fullPath)) {
    http_response_code(404);
    // បង្ហាញ Path ច្បាស់ៗដើម្បីងាយស្រួលរក
    die("File missing on disk. Checked path: " . $fullPath);
}

// 5. Download
$mime = mime_content_type($fullPath);
header('Content-Description: File Transfer');
header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . basename($attachment['filename']) . '"');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
exit;