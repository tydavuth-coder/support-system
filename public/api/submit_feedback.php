<?php
// --- PHP Logic for Submitting Feedback ---
require_once __DIR__ . '/../../includes/functions.php'; 
require_once __DIR__ . '/../../includes/auth.php'; 
require_once __DIR__ . '/../../includes/csrf.php'; 
require_login(); // Must be logged in

// Check CSRF token
csrf_check();

$u = current_user();
$ticket_id = (int)($_POST['ticket_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

$redirect_url = base_url('ticket_view.php?id=' . $ticket_id); // Redirect back to ticket

try {
    // Validation
    if (!$ticket_id || $rating < 1 || $rating > 5) {
        throw new Exception(__t('invalid_feedback_data'));
    }

    // Check if user is the owner of the ticket
    $stmt = $pdo->prepare("SELECT user_id, status FROM tickets WHERE id = ?");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
         throw new Exception(__t('ticket_not_found'));
    }
    
    if ($ticket['user_id'] != $u['id']) {
         throw new Exception(__t('not_ticket_owner')); // Only owner can rate
    }

    // Check if ticket is closed
    if ($ticket['status'] !== 'completed' && $ticket['status'] !== 'rejected') {
         throw new Exception(__t('ticket_not_closed_yet')); // Can only rate closed tickets
    }

    // Check if feedback already exists
    $stmt = $pdo->prepare("SELECT id FROM feedback WHERE ticket_id = ? AND user_id = ?");
    $stmt->execute([$ticket_id, $u['id']]);
    if ($stmt->fetch()) {
        throw new Exception(__t('feedback_already_submitted'));
    }

    // Insert new feedback
    $pdo->prepare("INSERT INTO feedback (ticket_id, user_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())")
        ->execute([$ticket_id, $u['id'], $rating, $comment]);
    
    // Log this activity
    log_activity($ticket_id, 'feedback_submitted', ['rating' => $rating]);

    // Success
    $_SESSION['flash_message'] = ['text' => __t('feedback_submit_success'), 'type' => 'success'];
    header('Location: ' . $redirect_url);
    exit;

} catch (Exception $e) {
    // Handle error
    $_SESSION['flash_message'] = ['text' => $e->getMessage(), 'type' => 'error'];
    header('Location: ' . $redirect_url);
    exit;
}
?>
