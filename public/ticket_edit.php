<?php 
session_start(); 
require_once __DIR__ . '/../includes/functions.php'; 
require_once __DIR__ . '/../includes/auth.php'; 
require_once __DIR__ . '/../includes/csrf.php'; 
require_login(); 

$u = current_user();
$id = (int)($_GET['id'] ?? 0);

if (!$id) { die("Invalid ID"); }

// 1. ទាញយកទិន្នន័យ Ticket
$stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
$stmt->execute([$id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) { die("Ticket not found"); }

// 2. ពិនិត្យសិទ្ធិ (Permission Check)
$canEdit = false;
if (is_role('admin') || is_role('coordinator')) { 
    $canEdit = true; 
} elseif ($u['id'] == $ticket['user_id'] && !in_array($ticket['status'], ['completed', 'rejected'])) { 
    $canEdit = true; 
}

if (!$canEdit) { die("You do not have permission to edit this ticket."); }

$msg = '';
$success = false;

// 3. ដំណើរការ Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    
    $title = trim($_POST['title'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $priority = trim($_POST['priority'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    if ($title) {
        try {
            $pdo->beginTransaction();
            
            // Update Ticket info
            $stmt = $pdo->prepare("UPDATE tickets SET title=?, type=?, priority=?, description=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$title, $type, $priority, $desc, $id]);
            
            log_activity($id, 'updated', ['title' => $title, 'changes' => 'User updated ticket details']);

            // Handle New Attachments (ដូច ticket_new.php)
            if(!empty($_FILES['attachments']['name'][0])){ 
                $__c=0; 
                $up = __DIR__ . '/uploads'; 
                if(!is_dir($up)) { @mkdir($up, 0777, true); }
                
                foreach($_FILES['attachments']['name'] as $i=>$name){ 
                    if($_FILES['attachments']['error'][$i]===UPLOAD_ERR_OK && $_FILES['attachments']['size'][$i] > 0){ 
                        $tmp=$_FILES['attachments']['tmp_name'][$i]; 
                        
                        // Sanitize & Check
                        $safe_name = preg_replace('/[^\p{L}\p{N}_\-\. ]/u', '', basename($name));
                        $safe_name = str_replace(' ', '_', $safe_name);
                        $ext = strtolower(pathinfo($safe_name, PATHINFO_EXTENSION)); 
                        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip', 'rar']; 

                        if(in_array($ext, $allowed_exts) && $_FILES['attachments']['size'][$i] <= 5 * 1024 * 1024) {
                             $fname = uniqid('att_', true) . '_' . $safe_name; 
                             $destination = $up . '/' . $fname;
                             
                             if(move_uploaded_file($tmp, $destination)){
                                 $finfo = finfo_open(FILEINFO_MIME_TYPE);
                                 $mime = finfo_file($finfo, $destination);
                                 finfo_close($finfo);

                                 $dbPath = 'uploads/' . $fname;
                                 $pdo->prepare("INSERT INTO attachments(ticket_id,filename,mime,path,created_at) VALUES(?,?,?,?,NOW())")
                                     ->execute([$id, $safe_name, $mime, $dbPath]); 
                                 $__c++; 
                             }
                        }
                    }
                }
                if($__c > 0) { log_activity($id, 'attachment_added', ['count' => $__c]); }
            }

            $pdo->commit();
            $success = true;
            $msg = __t('update_success');
            
            // Refresh data
            $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
            $stmt->execute([$id]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "Error: " . $e->getMessage();
        }
    } else {
        $msg = __t('title_required');
    }
}

// Fetch Existing Attachments
$att = $pdo->prepare("SELECT * FROM attachments WHERE ticket_id=?");
$att->execute([$id]);
$attachments = $att->fetchAll(PDO::FETCH_ASSOC);

$lang = current_lang();
$appName = $lang === 'km' ? $config['app']['name_km'] : $config['app']['name_en'];
?>

<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Ticket #<?= $id ?></title>
    <link rel="icon" href="<?= base_url('assets/img/logo_32x32.png') ?>"> 
    <script> if(localStorage.theme==='dark'||(!('theme' in localStorage)&&window.matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark')}else{document.documentElement.classList.remove('dark')} </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script> tailwind.config={darkMode:'class'} </script>
    <style> @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;700&display=swap'); body { font-family: 'Kantumruy Pro', sans-serif; } </style>
</head>
<body class="bg-gray-100 text-gray-800 dark:bg-slate-900 dark:text-gray-300 transition-colors duration-300">

    <div class="flex flex-col min-h-screen">
        <header class="bg-white/80 dark:bg-slate-800/80 backdrop-blur-sm shadow-sm border-b border-gray-200 dark:border-transparent py-3">
             <div class="container mx-auto px-4 flex justify-between items-center">
                 <span class="text-xl font-bold text-gray-900 dark:text-white">Edit Ticket #<?= $id ?></span>
                 <a href="ticket_view.php?id=<?= $id ?>" class="text-sm text-blue-600 hover:underline"><?= __t('back_to_ticket') ?></a>
             </div>
        </header>

        <main class="flex-grow container mx-auto px-4 py-8 mt-4">
            <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 md:p-8 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 max-w-3xl mx-auto">
                
                <?php if($msg): ?>
                    <div class="<?= $success ? 'bg-green-100 text-green-700 border-green-400' : 'bg-red-100 text-red-700 border-red-400' ?> border px-4 py-3 rounded-lg mb-6">
                        <?= e($msg) ?>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="space-y-6">
                    <?php csrf_field(); ?>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('title') ?></label>
                        <input type="text" name="title" value="<?= e($ticket['title']) ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('type') ?></label>
                            <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 dark:text-gray-200">
                                <?php foreach(['General', 'Network', 'Account', 'Hardware', 'Software'] as $opt): ?>
                                <option value="<?= e($opt) ?>" <?= $ticket['type'] == $opt ? 'selected' : '' ?>><?= e($opt) ?></option> 
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('priority') ?></label>
                            <select name="priority" class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 dark:text-gray-200">
                                <?php foreach(['Low', 'Normal', 'High', 'Urgent'] as $opt): ?>
                                <option value="<?= e($opt) ?>" <?= $ticket['priority'] == $opt ? 'selected' : '' ?>><?= e($opt) ?></option> 
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('description') ?></label>
                        <textarea name="description" rows="5" class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200"><?= e($ticket['description']) ?></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('existing_attachments') ?></label>
                        <div class="space-y-2 mb-4">
                            <?php if($attachments): ?>
                                <?php foreach($attachments as $a): ?>
                                    <div class="flex items-center text-sm text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-slate-700 px-3 py-2 rounded border border-gray-200 dark:border-slate-600">
                                        <span class="truncate flex-grow"><?= e($a['filename']) ?></span>
                                        <a href="<?= base_url('api/attachment_delete.php?id='.$a['id'].'&ticket_id='.$id.'&_csrf='.csrf_token()) ?>" onclick="return confirm('Are you sure?')" class="text-red-500 hover:text-red-700 ml-4 text-xs">[Delete]</a>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-gray-400 italic text-sm">No attachments</span>
                            <?php endif; ?>
                        </div>

                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('add_more_attachments') ?></label>
                        <input type="file" name="attachments[]" multiple class="w-full text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded-lg">
                    </div>

                    <div class="flex justify-end gap-4 pt-4">
                        <a href="ticket_view.php?id=<?= $id ?>" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-slate-600 dark:text-gray-200 dark:hover:bg-slate-500">
                            <?= __t('cancel') ?>
                        </a>
                        <button type="submit" class="px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700">
                            <?= __t('update') ?>
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>