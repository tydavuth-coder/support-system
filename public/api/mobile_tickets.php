<?php
// public/api/mobile_tickets.php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';

$user_id = (int)($_GET['user_id'] ?? 0);

if ($user_id === 0) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit;
}

// Fetch tickets
$sql = "SELECT t.*, u.name AS user_name 
        FROM tickets t 
        LEFT JOIN users u ON u.id = t.user_id 
        WHERE t.user_id = ? OR t.assigned_to = ? 
        ORDER BY t.updated_at DESC LIMIT 50";

$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id, $user_id]);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'count' => count($tickets),
    'tickets' => $tickets
]);
?>