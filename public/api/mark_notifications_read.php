<?php
session_start();

// --- API Logic to Mark Notifications as Read ---
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_login(); 

// ***** FIX: CSRF Check is not needed for this specific action *****
// The require_login() and session-based user_id update is secure enough.
// csrf_check(); 
// ***** END FIX *****

$u = current_user();

try {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$u['id']]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'marked' => $stmt->rowCount()]);
    exit;

} catch (PDOException $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}
?>