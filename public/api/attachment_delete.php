<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

session_start();
require_login();

// 1. ពិនិត្យសិទ្ធិ និង Token (សុវត្ថិភាព)
// យើងប្រើ GET request សម្រាប់លុប ដូច្នេះត្រូវប្រាកដថាមាន _csrf token
if (!isset($_GET['_csrf']) || $_GET['_csrf'] !== csrf_token()) {
    die("Invalid CSRF Token");
}

$attId = (int)($_GET['id'] ?? 0);
$ticketId = (int)($_GET['ticket_id'] ?? 0);
$u = current_user();

if (!$attId || !$ticketId) {
    die("Invalid Request");
}

// 2. ទាញយកព័ត៌មាន Attachment និង Ticket
$stmt = $pdo->prepare("SELECT a.*, t.user_id, t.status 
                       FROM attachments a 
                       JOIN tickets t ON t.id = a.ticket_id 
                       WHERE a.id = ? AND a.ticket_id = ?");
$stmt->execute([$attId, $ticketId]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    die("Attachment not found");
}

// 3. ពិនិត្យសិទ្ធិអ្នកលុប (ម្ចាស់ Ticket ឬ Admin/Coordinator)
$canDelete = false;
if (is_role('admin') || is_role('coordinator')) {
    $canDelete = true;
} elseif ($u['id'] == $data['user_id'] && !in_array($data['status'], ['completed', 'rejected'])) {
    $canDelete = true;
}

if (!$canDelete) {
    die("Permission Denied");
}

// 4. លុបឯកសារចេញពី Server (Folder uploads)
$filePath = __DIR__ . '/../../public/' . $data['path'];
if (file_exists($filePath)) {
    @unlink($filePath); // @ ដើម្បីកុំឱ្យ error បើឯកសារបាត់ស្រាប់
}

// 5. លុប Record ចេញពី Database
$pdo->prepare("DELETE FROM attachments WHERE id = ?")->execute([$attId]);

// 6. កត់ត្រា Activity Log
log_activity($ticketId, 'updated', ['changes' => 'Deleted attachment: ' . $data['filename']]);

// 7. ត្រឡប់ទៅទំព័រ Edit វិញ
header('Location: ' . base_url('ticket_edit.php?id=' . $ticketId));
exit;