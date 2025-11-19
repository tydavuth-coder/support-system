<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';

$ticket_id = (int)($_GET['id'] ?? 0);

if ($ticket_id === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

// Fetch Ticket Info
$sql = "SELECT t.*, u.name AS user_name, a.name AS assignee_name 
        FROM tickets t 
        LEFT JOIN users u ON u.id = t.user_id 
        LEFT JOIN users a ON a.id = t.assigned_to 
        WHERE t.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    echo json_encode(['success' => false, 'message' => 'Ticket not found']);
    exit;
}

echo json_encode(['success' => true, 'ticket' => $ticket]);
?>