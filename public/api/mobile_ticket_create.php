<?php
// public/api/mobile_ticket_create.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Helper
function sendJson($success, $message) {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(false, 'Method Not Allowed');
}

// 1. ទទួលទិន្នន័យ Text
$user_id = $_POST['user_id'] ?? 0;
$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$priority = $_POST['priority'] ?? 'Normal';
$type = $_POST['type'] ?? 'General';

if (empty($title) || empty($user_id)) {
    sendJson(false, 'Title and User ID are required');
}

try {
    $pdo->beginTransaction();

    // 2. Insert Ticket
    $stmt = $pdo->prepare("INSERT INTO tickets (user_id, title, type, priority, description, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'received', NOW(), NOW())");
    $stmt->execute([$user_id, $title, $type, $priority, $description]);
    $ticketId = $pdo->lastInsertId();
    
    // Log Activity
    // log_activity($ticketId, 'created', ['source' => 'mobile app']);

    // 3. Handle File Upload
    if (!empty($_FILES['attachments']['name'])) {
        
        $uploadDir = __DIR__ . '/../../public/uploads';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $fileNames = is_array($_FILES['attachments']['name']) ? $_FILES['attachments']['name'] : [$_FILES['attachments']['name']];
        $fileTmpNames = is_array($_FILES['attachments']['tmp_name']) ? $_FILES['attachments']['tmp_name'] : [$_FILES['attachments']['tmp_name']];
        $fileErrors = is_array($_FILES['attachments']['error']) ? $_FILES['attachments']['error'] : [$_FILES['attachments']['error']];
        $fileSizes = is_array($_FILES['attachments']['size']) ? $_FILES['attachments']['size'] : [$_FILES['attachments']['size']];

        for ($i = 0; $i < count($fileNames); $i++) {
            if ($fileErrors[$i] === UPLOAD_ERR_OK && $fileSizes[$i] > 0) {
                
                $originalName = basename($fileNames[$i]);
                $safeName = preg_replace('/[^\p{L}\p{N}_\-\. ]/u', '', $originalName);
                $safeName = str_replace(' ', '_', $safeName);
                if(empty($safeName)) $safeName = 'file_' . uniqid();

                $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
                $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

                if (in_array($ext, $allowedExts)) {
                    $newName = uniqid('att_', true) . '_' . $safeName;
                    $destination = $uploadDir . '/' . $newName;

                    if (move_uploaded_file($fileTmpNames[$i], $destination)) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = finfo_file($finfo, $destination);
                        finfo_close($finfo);

                        $dbPath = 'uploads/' . $newName;
                        $stmtAtt = $pdo->prepare("INSERT INTO attachments (ticket_id, filename, mime, path, created_at) VALUES (?, ?, ?, ?, NOW())");
                        $stmtAtt->execute([$ticketId, $safeName, $mime, $dbPath]);
                    }
                }
            }
        }
    }

    $pdo->commit();
    sendJson(true, 'Ticket created successfully');

} catch (Exception $e) {
    $pdo->rollBack();
    sendJson(false, 'Database error: ' . $e->getMessage());
}
?>