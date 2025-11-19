<?php
// Set content type to HTML because the JavaScript expects HTML fragments
header('Content-Type: text/html; charset=utf-8'); // Use text/html

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // Include auth for current_user()
require_once __DIR__ . '/../../includes/csrf.php'; // Include csrf just in case (good practice)
require_login(); // Ensure user is logged in

$ticket_id = (int)($_GET['id'] ?? 0);
$since_id = (int)($_GET['since'] ?? 0); // Get 'since' parameter (expected to be the last message ID)

// Basic validation
if (!$ticket_id) {
    // Return empty string if no ticket ID, as JavaScript expects HTML
    echo ''; 
    exit;
}

// Get current user info to determine message alignment (sent/received)
$currentUser = current_user();
$defaultAvatar = base_url('assets/img/logo_32x32.png'); // Use the same default avatar as ticket_view.php

// --- Fixed SQL Query ---
// Fetch messages with ID greater than $since_id
$sql = "SELECT m.id, m.body, m.created_at, m.sender_id, u.name, u.avatar
        FROM messages m
        LEFT JOIN users u ON m.sender_id = u.id
        WHERE m.ticket_id = ?";

$params = [$ticket_id];

// Add condition for 'since_id' ONLY if it's greater than 0
if ($since_id > 0) {
    $sql .= " AND m.id > ?";
    $params[] = $since_id;
}

$sql .= " ORDER BY m.created_at ASC"; // Keep order ascending

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $new_messages = $stmt->fetchAll(PDO::FETCH_ASSOC); // Use FETCH_ASSOC
} catch (PDOException $e) {
    // Log error and return empty string to avoid breaking the chat UI
    error_log("Chat Poll Error: " . $e->getMessage());
    echo '';
    exit;
}

// --- Generate HTML Output ---
$html_output = '';
$lastSenderId = null; // Track sender changes *within this polled batch*

// *** Important Consideration for $lastSenderId ***
// To perfectly replicate the avatar logic from ticket_view.php,
// we would ideally need to know the sender ID of the *very last message displayed* before this poll.
// Passing this info via JS is complex. For simplicity, this version bases $showAvatar
// only on sender changes *within the newly fetched messages*. This might occasionally
// show the avatar again if the sender is the same as the last message already displayed.

foreach ($new_messages as $m) {
    $isSent = $m['sender_id'] === $currentUser['id'];
    // Show avatar if sender is different from the previous message *in this batch*
    $showAvatar = $m['sender_id'] !== $lastSenderId; 
    $avatarUrl = $m['avatar'] ? base_url($m['avatar']) : $defaultAvatar;

    // Use the same HTML structure and Tailwind classes as in ticket_view.php
    $html_output .= '<div class="chat-message ' . ($isSent ? 'sent' : 'received') . '" data-message-id="' . (int)$m['id'] . '">'; // Add data-message-id
    
    // Avatar column
    if ($showAvatar) {
        $html_output .= '<img src="' . e($avatarUrl) . '" alt="' . e($m['name'] ?: 'User') . '" class="avatar">';
    } else {
        // Invisible placeholder for alignment if avatar isn't shown
        $html_output .= '<div class="avatar invisible"></div>'; 
    }
    
    // Message content column
    $html_output .= '<div>';
    // Show sender name and time only if avatar is shown (sender changed)
    if ($showAvatar) {
        $html_output .= '<div class="text-xs mb-1 ' . ($isSent ? 'text-right' : 'text-left') . '">';
        $html_output .= '<strong class="text-gray-700 dark:text-gray-300">' . e($m['name'] ?: 'User') . '</strong> ';
        // Use time_ago function
        $html_output .= '<span class="text-gray-400 dark:text-gray-500">' . time_ago($m['created_at'], '') . '</span>'; 
        $html_output .= '</div>';
    }
    // The message bubble
    $html_output .= '<div class="chat-bubble">' . nl2br(e($m['body'])) . '</div>';
    $html_output .= '</div>'; // End message content column
    
    $html_output .= '</div>'; // End chat-message div

    $lastSenderId = $m['sender_id']; // Update last sender for the next iteration in this batch
}

// Output the generated HTML (or empty string if no new messages)
echo $html_output;

?>
