<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';

$user_id = (int)($_GET['user_id'] ?? 0);
if (!$user_id) { echo json_encode(['success'=>false]); exit; }

// Get User Role
$stmt = $pdo->prepare("SELECT role FROM users WHERE id=?");
$stmt->execute([$user_id]);
$userRole = $stmt->fetchColumn();

// 1. Stats Cards
$totalNew = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status='received'")->fetchColumn();
$totalOpen = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('received','in_progress')")->fetchColumn();
$totalClosed = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status='completed'")->fetchColumn();

// Role Based Card
$roleCount = 0;
if ($userRole === 'coordinator' || $userRole === 'admin') {
    $roleCount = $pdo->query("SELECT COUNT(*) FROM tickets WHERE assigned_to IS NULL AND status IN ('received', 'in_progress')")->fetchColumn();
} elseif ($userRole === 'technical') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE assigned_to = ? AND status IN ('received', 'in_progress')");
    $stmt->execute([$user_id]);
    $roleCount = $stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE user_id = ? AND status IN ('received', 'in_progress')");
    $stmt->execute([$user_id]);
    $roleCount = $stmt->fetchColumn();
}

// 2. Recent Activity
$actStmt = $pdo->query("
    SELECT a.*, u.name AS actor_name, t.title AS ticket_title 
    FROM ticket_activity a 
    LEFT JOIN users u ON u.id = a.created_by 
    LEFT JOIN tickets t ON t.id = a.ticket_id 
    ORDER BY a.created_at DESC LIMIT 5
");
$activities = $actStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Status Distribution (For Charts)
$statusData = $pdo->query("SELECT status, COUNT(*) as count FROM tickets GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'stats' => [
        'new' => $totalNew,
        'open' => $totalOpen,
        'closed' => $totalClosed,
        'role_count' => $roleCount,
        'role' => $userRole
    ],
    'activities' => $activities,
    'chart_data' => $statusData
]);
?>