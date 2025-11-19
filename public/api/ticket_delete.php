<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

session_start();
require_login();

// 1. ពិនិត្យសិទ្ធិ (តែ Admin និង Coordinator ទេដែលអាចលុបបាន)
if (!is_role('admin') && !is_role('coordinator')) {
    $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Access Denied: You do not have permission to delete tickets.'];
    header('Location: ' . base_url('tickets.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    
    $id = (int)($_POST['id'] ?? 0);
    
    if ($id) {
        try {
            $pdo->beginTransaction();

            // ១. លុបឯកសារជាក់ស្តែងចេញពី Folder (Attachments)
            $stmtAtt = $pdo->prepare("SELECT path FROM attachments WHERE ticket_id = ?");
            $stmtAtt->execute([$id]);
            $attachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($attachments as $att) {
                $filePath = __DIR__ . '/../../public/' . $att['path'];
                // ពិនិត្យមើលថាមាន File ឬអត់ រួចលុបចោល
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }

            // ២. លុបទិន្នន័យចេញពី Database (តាមលំដាប់លំដោយ)
            $pdo->prepare("DELETE FROM attachments WHERE ticket_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM messages WHERE ticket_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM ticket_activity WHERE ticket_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM feedback WHERE ticket_id = ?")->execute([$id]);
            
            // ៣. លុប Ticket ផ្ទាល់
            $pdo->prepare("DELETE FROM tickets WHERE id = ?")->execute([$id]);

            $pdo->commit();
            
            // Save Log (Optional)
            // error_log("Ticket #$id deleted by User ID " . current_user()['id']);

            $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Ticket deleted successfully.'];

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Error deleting ticket: ' . $e->getMessage()];
        }
    } else {
        $_SESSION['flash_message'] = ['type' => 'error', 'text' => 'Invalid Ticket ID.'];
    }
}

header('Location: ' . base_url('tickets.php'));
exit;