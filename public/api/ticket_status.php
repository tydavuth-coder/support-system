<?php
session_start(); // ត្រូវតែមាន សម្រាប់ Flash Messages
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php'; // ត្រូវតែមាន សម្រាប់ require_login()
require_once __DIR__ . '/../../includes/csrf.php';

try {
    require_login();
    csrf_check();

    $id = (int)($_POST['id'] ?? 0);
    $new_status = trim($_POST['status'] ?? '');
    $solution = trim($_POST['solution'] ?? ''); // <-- 1. ទទួលទិន្នន័យ Solution
    $u = current_user();

    $allowed_statuses = ['received', 'in_progress', 'completed', 'rejected'];
    if (empty($id) || empty($new_status) || !in_array($new_status, $allowed_statuses)) {
        throw new Exception(__t('invalid_input'));
    }

    // 1. Fetch the ticket to check permissions and old status
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
    $stmt->execute([$id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        throw new Exception(__t('ticket_not_found'));
    }

    // 2. Check permissions
    $canEditStatus = false;
    if (is_role('admin') || is_role('coordinator')) {
        $canEditStatus = true;
    } elseif (($u['role'] ?? '') === 'technical' && (int)$ticket['assigned_to'] === (int)$u['id']) {
        $canEditStatus = true;
    }

    if (!$canEditStatus) {
        throw new Exception(__t('forbidden_action'));
    }

    // 3. Update the ticket in database (បន្ថែម Solution)
    $sql = "UPDATE tickets SET status = ?, solution = ?, updated_at = NOW() WHERE id = ?"; // <-- 2. Update Query
    $updateStmt = $pdo->prepare($sql);
    $updateStmt->execute([$new_status, $solution, $id]); // <-- 3. បញ្ចូល $solution ទៅក្នុង Query

    // 4. Log activity
    if ($new_status !== $ticket['status']) {
        log_activity($id, 'status_changed', [
            'from' => $ticket['status'],
            'to' => $new_status
        ]);
    }
    
    // 4b. Log if solution was added or changed (NEW)
    if ($solution !== $ticket['solution']) {
         log_activity($id, 'solution_updated', [
            'notes' => mb_strimwidth($solution, 0, 50, '...')
         ]);
    }

    // 5. Send notification
    if ($ticket['user_id'] != $u['id']) { 
        
        $ownerStmt = $pdo->prepare("SELECT id, email, name, phone FROM users WHERE id = ?");
        $ownerStmt->execute([$ticket['user_id']]);
        $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);

        if ($owner) {
            // In-App Notification
            $message = __t('notif_ticket_status_updated', [
                'id' => $id, 
                'status' => __t($new_status)
            ]);
            notify_inapp($owner['id'], $message, base_url("ticket_view.php?id=$id"));
            
            // Email Notification
            $smtp_user = get_setting('smtp_user', '');
            if (!empty($smtp_user) && !empty($owner['email'])) { 
                $email_subject = __t('email_subject_ticket_updated', ['id' => $id]);
                $email_body = __t('email_body_status_changed', ['status' => __t($new_status)]);
                @notify_email($owner['email'], $owner['name'], $email_subject, "<p>$email_body</p>"); 
            }
            
            // SMS Notification
            $twilio_sid = get_setting('twilio_sid', '');
            if(!empty($owner['phone']) && !empty($twilio_sid)) {
                $sms_message = __t('sms_ticket_status_updated', ['id' => $id, 'status' => __t($new_status)]);
                @notify_sms($owner['phone'], $sms_message); 
            }
        }
    }
    
    // FIX: ប្រើ header() ជំនួស redirect_to()
    header('Location: ' . base_url('ticket_view.php?id=' . $id));
    exit;
    
} catch (Exception $e) {
    error_log($e->getMessage());
    $_SESSION['flash_error'] = $e->getMessage();
    
    // FIX: ប្រើ header() ជំនួស redirect_to()
    header('Location: ' . base_url('ticket_view.php?id=' . ($id ?? 0)));
    exit;
}
?>