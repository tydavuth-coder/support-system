<?php 
session_start(); 
require_once __DIR__ . '/../includes/functions.php'; 
require_once __DIR__ . '/../includes/auth.php'; 
require_once __DIR__ . '/../includes/csrf.php'; 
require_login(); 

// ***** Notification Logic *****
$u = isset($u) ? $u : (isset($currentUser) ? $currentUser : current_user());
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$notifStmt->execute([$u['id']]);
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
$unreadCountStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadCountStmt->execute([$u['id']]);
$unreadCount = (int)$unreadCountStmt->fetchColumn();
// ***** END Notification Logic *****

$msg='';
$success = false; 
$ticketId = null; 

if($_SERVER['REQUEST_METHOD']==='POST'){ 
  csrf_check();
  $title=trim($_POST['title']??''); 
  $type=trim($_POST['type']??'General'); 
  $priority=trim($_POST['priority']??'Normal'); 
  $desc=trim($_POST['description']??'');
  
  if($title){
    try {
        $pdo->beginTransaction(); 

        // 1. បង្កើត Ticket
        $stmt = $pdo->prepare("INSERT INTO tickets(user_id,title,type,priority,description,status,created_at,updated_at) VALUES(?,?,?,?,?,'received',NOW(),NOW())");
        $stmt->execute([current_user()['id'],$title,$type,$priority,$desc]);
        $ticketId=$pdo->lastInsertId(); 
        
        log_activity($ticketId,'created',['title'=>$title,'type'=>$type,'priority'=>$priority]);
        
        // 2. ដំណើរការ Upload ឯកសារ
        if(!empty($_FILES['attachments']['name'][0])){ 
          $__c=0; 
          
          // <<< កែប្រែទីតាំង៖ ដាក់ចូល public/uploads >>>
          $up = __DIR__ . '/uploads'; 
          
          if(!is_dir($up)) {
              if (!@mkdir($up, 0777, true)) {
                  throw new Exception("Failed to create upload directory: " . $up);
              }
          }
          
          foreach($_FILES['attachments']['name'] as $i=>$name){ 
            if($_FILES['attachments']['error'][$i]===UPLOAD_ERR_OK && $_FILES['attachments']['size'][$i] > 0){ 
              $tmp=$_FILES['attachments']['tmp_name'][$i]; 
              
              // <<< កែប្រែឈ្មោះ៖ អនុញ្ញាតឱ្យប្រើភាសាខ្មែរ (Unicode) >>>
              // \p{L} = អក្សរគ្រប់ភាសា, \p{N} = លេខ
              $safe_name = preg_replace('/[^\p{L}\p{N}_\-\. ]/u', '', basename($name));
              // ប្តូរដកឃ្លាទៅជា _ ដើម្បីកុំឱ្យមានបញ្ហា Link
              $safe_name = str_replace(' ', '_', $safe_name);
              
              // បើឈ្មោះខ្លីពេក ឬទទេ ដាក់ឈ្មោះថ្មីឱ្យ
              if(empty($safe_name)) {
                  $safe_name = 'file_' . uniqid();
              }

              $ext = strtolower(pathinfo($safe_name, PATHINFO_EXTENSION)); 
              $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip', 'rar', 'csv']; 
              
              if(in_array($ext, $allowed_exts)) {
                  // Check file size (Max 5MB)
                  if ($_FILES['attachments']['size'][$i] <= 5 * 1024 * 1024) { 
                      
                      // MIME Check (សុវត្ថិភាព)
                      $finfo = finfo_open(FILEINFO_MIME_TYPE);
                      $true_mime = finfo_file($finfo, $tmp);
                      finfo_close($finfo);
                      
                      $allowed_mimes = [
                          'image/jpeg', 'image/png', 'image/gif', 
                          'application/pdf', 'application/zip', 'text/plain', 
                          'application/msword', 
                          'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 
                          'application/vnd.ms-excel', 
                          'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 
                          'application/x-rar-compressed', 'text/csv'
                      ];
                      
                      if (!in_array($true_mime, $allowed_mimes)) {
                           throw new Exception("Invalid file content (" . $true_mime . ") for: " . $safe_name);
                      }

                      // បង្កើតឈ្មោះ Unique ដើម្បីកុំឱ្យជាន់គ្នា
                      $fname = uniqid('att_', true) . '_' . $safe_name; 
                      $destination = $up . '/' . $fname;

                      if(move_uploaded_file($tmp, $destination)){
                         // Save Path ចូល DB: 'uploads/filename.ext'
                         $dbPath = 'uploads/' . $fname;
                         
                         // Save ឈ្មោះដើម ($safe_name) ចូល DB ដើម្បីឱ្យ user ឃើញឈ្មោះដែលគាត់ស្គាល់
                         $pdo->prepare("INSERT INTO attachments(ticket_id,filename,mime,path,created_at) VALUES(?,?,?,?,NOW())")
                             ->execute([$ticketId, $safe_name, $true_mime, $dbPath]); 
                         $__c++; 
                      } else {
                         throw new Exception("Failed to move uploaded file.");
                      }
                  } else {
                     throw new Exception("File size exceeds 5MB limit for: " . $safe_name);
                  }
              } else {
                 throw new Exception("Invalid file extension: " . $ext);
              }
            } elseif ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                throw new Exception("Upload error code: " . $_FILES['attachments']['error'][$i]);
            }
          }
          if($__c>0){ 
            log_activity($ticketId,'attachment_added',['count'=>$__c]); 
          }
        }
        // --- End Upload Logic ---
        
        // 3. Notification
        $admins=$pdo->query("SELECT id,email,name,phone FROM users WHERE role IN ('admin','coordinator')")->fetchAll();
        foreach($admins as $a){ 
          notify_inapp($a['id'],"New ticket #$ticketId: ".$title, base_url("ticket_view.php?id=$ticketId")); 
          @notify_email($a['email'],$a['name'],"New ticket #$ticketId","<p>New ticket created by ".e(current_user()['name']).": ".e($title)."</p><p>View: ".base_url("ticket_view.php?id=$ticketId")."</p>"); 
        }
        
        $pdo->commit(); 
        $success = true; 
        $msg = __t('create_ticket_success'); 
        
    } catch (Exception $e) {
        $pdo->rollBack(); 
        $msg = __t('create_ticket_error') . ': ' . $e->getMessage();
        error_log("Ticket Creation Error: " . $e->getMessage()); 
        $success = false;
    }
    
  } else {
      $msg = __t('title_required'); 
      $success = false;
  }
}

$lang = current_lang();
$appName = $lang === 'km' ? $config['app']['name_km'] : $config['app']['name_en'];
$currentUser = current_user(); 
$defaultAvatar = base_url('assets/img/logo_32x32.png'); 
?>

<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appName) ?> - <?= __t('new_ticket') ?></title>
	<link rel="icon" href="<?= base_url('assets/img/logo_32x32.png') ?>"> 
    <script> if(localStorage.theme==='dark'||(!('theme' in localStorage)&&window.matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark')}else{document.documentElement.classList.remove('dark')} </script>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script> tailwind.config={darkMode:'class'} </script>
    
    <script src="https://unpkg.com/lucide-react@latest/dist/lucide-react.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;700&display=swap');
        body { font-family: 'Kantumruy Pro', sans-serif; }
        #digital-particles { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
        ::-webkit-scrollbar { width: 6px; } ::-webkit-scrollbar-track { background: #f1f5f9; } ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 10px; }
        .dark ::-webkit-scrollbar-track { background: #1e293b; } .dark ::-webkit-scrollbar-thumb { background: #334155; }
        input[type="file"]::file-selector-button { margin-right: 1rem; padding: 0.5rem 1rem; border-radius: 0.5rem; border: 0; font-size: 0.875rem; font-weight: 600; background-color: #e0f2fe; color: #0369a1; cursor: pointer; transition: background-color 0.2s ease-in-out; }
        input[type="file"]:hover::file-selector-button { background-color: #bae6fd; }
        .dark input[type="file"]::file-selector-button { background-color: #334155; color: #7dd3fc; }
        .dark input[type="file"]:hover::file-selector-button { background-color: #475569; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 dark:bg-slate-900 dark:text-gray-300 transition-colors duration-300">

    <canvas id="digital-particles"></canvas>
    
    <div class="flex flex-col min-h-screen">
    
        <header class="sticky top-0 z-50 bg-white/80 dark:bg-slate-800/80 backdrop-blur-sm shadow-md dark:shadow-lg border-b border-gray-200 dark:border-transparent">
            <nav class="container mx-auto px-4 py-3 flex justify-between items-center">
                 <div class="flex items-center gap-6">
                    <a href="<?= base_url('dashboard.php') ?>" class="flex items-center gap-2">
                        <img src="<?= base_url(get_setting('site_logo', 'assets/img/logo_128x128.png')) ?>" alt="Logo" class="w-10 h-10">
                        <span class="text-xl font-bold text-gray-900 dark:text-white hidden sm:block"><?= e($appName) ?></span>
                    </a>
                    <div class="hidden md:flex items-center gap-4">
                        <a href="<?= base_url('dashboard.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('dashboard') ?></a>
                        <a href="<?= base_url('tickets.php') ?>" class="text-white font-semibold bg-blue-600 px-3 py-1.5 rounded-lg text-sm"><?= __t('tickets') ?></a>
                        <a href="<?= base_url('kb.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('knowledge_base') ?></a>
                        <a href="<?= base_url('reports.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('reports') ?></a>
                        <?php if ($currentUser['role'] === 'admin'): ?>
                        <a href="<?= base_url('admin/users.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('users') ?></a>
                        <a href="<?= base_url('admin/settings.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('settings') ?></a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <a href="?setlang=km" title="ភាសាខ្មែរ" class="rounded-full transition-all duration-300 <?= $lang === 'km' ? 'ring-2 ring-blue-500 ring-offset-2 ring-offset-white dark:ring-offset-slate-800' : 'opacity-60 hover:opacity-100' ?>">
                        <img src="<?= base_url('assets/img/flag-km.png') ?>" alt="ភាសាខ្មែរ" class="w-6 h-6 rounded-full object-cover"> 
                    </a>
                    <a href="?setlang=en" title="English" class="rounded-full transition-all duration-300 <?= $lang === 'en' ? 'ring-2 ring-blue-500 ring-offset-2 ring-offset-white dark:ring-offset-slate-800' : 'opacity-60 hover:opacity-100' ?>">
                        <img src="<?= base_url('assets/img/flag-en.png') ?>" alt="English" class="w-6 h-6 rounded-full object-cover">
                    </a>
                    <button id="theme-toggle" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white">
                        <svg id="theme-icon-sun" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                        <svg id="theme-icon-moon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                    </button>
                    <div class="relative" id="notification-dropdown-menu">
                        <button onclick="toggleDropdown('notification-dropdown'); markNotificationsAsRead();" class="relative text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341A6.002 6.002 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                            <?php if ($unreadCount > 0): ?>
                                <span id="notification-badge" class="absolute -top-2 -right-2 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-xs font-bold text-white"><?= (int)$unreadCount ?></span>
                            <?php endif; ?>
                        </button>
                        <div id="notification-dropdown" class="hidden absolute right-0 mt-2 w-80 max-h-96 overflow-y-auto bg-white dark:bg-slate-700 dark:text-gray-200 rounded-lg shadow-xl z-50">
                            <div class="p-3 border-b border-gray-200 dark:border-slate-600">
                                <h6 class="font-semibold text-gray-800 dark:text-white"><?= __t('Notifications') ?></h6>
                            </div>
                            <div class="divide-y divide-gray-100 dark:divide-slate-600">
                                <?php if (empty($notifications)): ?>
                                    <p class="text-center text-gray-500 dark:text-gray-400 text-sm p-4"><?= __t('no_notifications_yet') ?></p>
                                <?php endif; ?>
                                <?php foreach($notifications as $notif): ?>
									<a href="<?= e($notif['link'] ? $notif['link'] : '#') ?>" class="block px-4 py-3 hover:bg-gray-100 dark:hover:bg-slate-600 <?= $notif['is_read'] ? 'opacity-70' : 'font-bold' ?>">
                                        <p class="text-sm text-gray-800 dark:text-gray-200 truncate"><?= e($notif['message']) ?></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400"><?= time_ago($notif['created_at']) ?></p>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="relative" id="profile-dropdown-menu">
                        <button onclick="toggleDropdown('profile-dropdown')" class="flex items-center gap-2 text-gray-900 dark:text-white text-sm">
                            <img src="<?= e($currentUser['avatar'] ? base_url($currentUser['avatar']) : $defaultAvatar) ?>?t=<?= time() ?>" alt="Profile" class="w-8 h-8 rounded-full object-cover bg-gray-200 dark:bg-slate-700">
                            <span class="hidden md:inline"><?= e($currentUser['name']) ?></span>
                            <svg class="w-4 h-4 text-gray-400 hidden md:inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div id="profile-dropdown" class="hidden absolute right-0 mt-2 w-48 bg-white dark:bg-slate-700 dark:text-gray-200 rounded-lg shadow-xl py-1 z-50">
                            <a href="<?= base_url('profile.php') ?>" class="block px-4 py-2 text-sm hover:bg-gray-100 dark:hover:bg-slate-600"><?= __t('profile') ?></a>
                            <a href="<?= base_url('logout.php') ?>" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100 dark:hover:bg-slate-600"><?= __t('logout') ?></a>
                        </div>
                    </div>
                </div>
                 <button class="md:hidden text-gray-800 dark:text-white" onclick="toggleDropdown('mobile-menu')">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path></svg>
                </button>
            </nav>
            <div id="mobile-menu" class="hidden md:hidden bg-white dark:bg-slate-700 px-4 pt-2 pb-4 space-y-2">
                 <a href="<?= base_url('dashboard.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('dashboard') ?></a>
                <a href="<?= base_url('tickets.php') ?>" class="block text-white font-semibold bg-blue-600 px-3 py-2 rounded-lg text-sm"><?= __t('tickets') ?></a>
                <a href="<?= base_url('kb.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('knowledge_base') ?></a>
                <a href="<?= base_url('reports.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('reports') ?></a>
                <?php if ($currentUser['role'] === 'admin'): ?>
                <a href="<?= base_url('admin/users.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('users') ?></a>
                <a href="<?= base_url('admin/settings.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('settings') ?></a>
                <?php endif; ?>
            </div>
        </header>

        <main class="flex-grow container mx-auto px-4 py-8 mt-8">
            
            <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 md:p-8 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 max-w-3xl mx-auto">
                
                <h5 class="text-xl font-semibold text-gray-700 dark:text-gray-200 mb-6"><?= __t('new_ticket') ?></h5>

                <?php if($msg): ?>
                    <div class="<?= $success ? 'bg-green-100 border-green-400 text-green-700 dark:bg-green-900/50 dark:border-green-600 dark:text-green-300' : 'bg-red-100 border-red-400 text-red-700 dark:bg-red-900/50 dark:border-red-600 dark:text-red-300' ?> border px-4 py-3 rounded-lg relative mb-6" role="alert">
                        <span class="block sm:inline"><?= e($msg) ?></span>
                        <?php if($success && $ticketId): ?>
                            <a href="ticket_view.php?id=<?= (int)$ticketId ?>" class="ml-4 font-bold text-green-800 dark:text-green-400 hover:underline"><?= __t('view_ticket') ?> (#<?= (int)$ticketId ?>)</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if(!$success): ?> 
                <form method="post" enctype="multipart/form-data" class="space-y-6">
                    <?php csrf_field(); ?>
                    
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('title') ?> <span class="text-red-500">*</span></label>
                        <input type="text" name="title" id="title" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200"
                               value="<?= e($_POST['title'] ?? '') ?>"> 
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('type') ?></label>
                            <select name="type" id="type" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200">
                                <?php $types = ['General', 'Network', 'Account', 'Hardware', 'Software']; ?>
                                <?php foreach($types as $opt): ?>
                                <option value="<?= e($opt) ?>" <?= (@$_POST['type'] ?? 'General') == $opt ? 'selected' : '' ?>><?= e(__t(strtolower($opt))) ?></option> 
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="priority" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('priority') ?></label>
                            <select name="priority" id="priority" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200">
                                <?php $priorities = ['Low', 'Normal', 'High', 'Urgent']; ?>
                                <?php foreach($priorities as $opt): ?>
                                <option value="<?= e($opt) ?>" <?= (@$_POST['priority'] ?? 'Normal') == $opt ? 'selected' : '' ?>><?= e(__t(strtolower($opt))) ?></option> 
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('description') ?></label>
                        <textarea name="description" id="description" rows="5" 
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200"><?= e($_POST['description'] ?? '') ?></textarea>
                    </div>

                    <div>
                        <label for="attachments" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('attachments') ?></label>
                        <input type="file" name="attachments[]" id="attachments" multiple 
                               class="w-full text-sm text-gray-500 dark:text-gray-400 cursor-pointer
                                      bg-gray-50 dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            <?= __t('max_file_size_note', ['size' => '5MB']) ?> 
                            <?= __t('allowed_file_types', ['types' => 'jpg, png, pdf, zip...']) ?>
                        </p> 
                    </div>

                    <div class="flex justify-end gap-4 pt-4">
                        <a href="tickets.php" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg shadow-sm hover:bg-gray-200 dark:bg-slate-600 dark:text-gray-200 dark:hover:bg-slate-500 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                            <?= __t('cancel') ?>
                        </a>
                        <button type="submit" class="flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            <?= __t('create') ?>
                        </button>
                    </div>
                </form>
                <?php endif; ?> 
            </div>
        </main>
    
        <footer class="bg-white dark:bg-slate-800/80 mt-12 py-4 border-t border-gray-200 dark:border-transparent">
            <div class="container mx-auto px-4 text-center text-gray-500 dark:text-gray-400 text-sm">
                © <?= date('Y') ?> - <?= e($appName) ?> / <?= e(__t('Digital System Management Department')) ?>
            </div>
        </footer>
    </div>

    <script> /* Digital Particle Script */ 
        const canvas = document.getElementById('digital-particles'); const ctx = canvas.getContext('2d'); canvas.width = window.innerWidth; canvas.height = window.innerHeight; let particlesArray = []; const numberOfParticles = (canvas.width * canvas.height) / 9000; const particleColorDark = 'rgba(255, 255, 255, 0.4)'; const particleColorLight = 'rgba(0, 0, 0, 0.2)'; const lineColorDark = 'rgba(100, 180, 255, 0.15)'; const lineColorLight = 'rgba(0, 100, 255, 0.15)'; function getParticleColors() { const isDarkMode = document.documentElement.classList.contains('dark'); return { particle: isDarkMode ? particleColorDark : particleColorLight, line: isDarkMode ? lineColorDark : lineColorLight }; } class Particle { constructor(x, y, dX, dY, s) { this.x=x;this.y=y;this.directionX=dX;this.directionY=dY;this.size=s;} draw() { ctx.beginPath(); ctx.arc(this.x,this.y,this.size,0,Math.PI*2,false); ctx.fillStyle=getParticleColors().particle; ctx.fill(); } update() { if(this.x>canvas.width||this.x<0)this.directionX=-this.directionX; if(this.y>canvas.height||this.y<0)this.directionY=-this.directionY; this.x+=this.directionX; this.y+=this.directionY; this.draw();}} function init() { particlesArray=[]; for(let i=0;i<numberOfParticles;i++) { let s=Math.random()*2+1,x=Math.random()*canvas.width,y=Math.random()*canvas.height,dX=(Math.random()*0.4)-0.2,dY=(Math.random()*0.4)-0.2; particlesArray.push(new Particle(x,y,dX,dY,s));}} function connect() { let opVal=1; const curLineColor=getParticleColors().line; for(let a=0;a<particlesArray.length;a++) { for(let b=a+1;b<particlesArray.length;b++) { let dist=Math.sqrt((particlesArray[a].x-particlesArray[b].x)**2 + (particlesArray[a].y-particlesArray[b].y)**2); if(dist<120) { opVal=1-(dist/120); ctx.strokeStyle=curLineColor.replace('0.15',opVal); ctx.lineWidth=0.5; ctx.beginPath(); ctx.moveTo(particlesArray[a].x,particlesArray[a].y); ctx.lineTo(particlesArray[b].x,particlesArray[b].y); ctx.stroke();}}}} function animate() { ctx.clearRect(0,0,canvas.width,canvas.height); particlesArray.forEach(p => p.update()); connect(); requestAnimationFrame(animate);} window.addEventListener('resize', () => { canvas.width=window.innerWidth; canvas.height=window.innerHeight; init();}); init(); animate(); </script>
    
    <script> 
        function toggleDropdown(id) { document.getElementById(id).classList.toggle('hidden'); } 
        window.addEventListener('click', function(e) { 
            const pM=document.getElementById('profile-dropdown-menu'); 
            if(pM&&!pM.contains(e.target))document.getElementById('profile-dropdown').classList.add('hidden'); 

            // Added Notification click-outside logic
            const notifMenu = document.getElementById('notification-dropdown-menu');
            if (notifMenu && !notifMenu.contains(e.target)) {
                document.getElementById('notification-dropdown').classList.add('hidden');
            }
            
            const mN=document.getElementById('mobile-menu'), mB=document.querySelector('button[onclick*="mobile-menu"]'); 
            if(mN&&!mN.contains(e.target)&&!mB.contains(e.target))mN.classList.add('hidden');
        }); 
        const themeToggle=document.getElementById('theme-toggle'), sunIcon=document.getElementById('theme-icon-sun'), moonIcon=document.getElementById('theme-icon-moon'); 
        function updateThemeIcon() { 
            const theme = localStorage.theme || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            if (theme === 'dark') {
                document.documentElement.classList.add('dark');
                sunIcon.classList.remove('hidden'); 
                moonIcon.classList.add('hidden');
            } else {
                document.documentElement.classList.remove('dark');
                sunIcon.classList.add('hidden'); 
                moonIcon.classList.remove('hidden');
            }
        } 
        updateThemeIcon(); 
        themeToggle.addEventListener('click', () => { 
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.theme = isDark ? 'dark' : 'light'; 
            updateThemeIcon(); 
        }); 
    </script>
    

<script>
// ***** Notification Mark as Read Logic (Already present in your file) *****
let notificationsMarked = false; // prevent duplicate calls
function markNotificationsAsRead() {
    const badge = document.getElementById('notification-badge');
    if (badge && !notificationsMarked) {
        notificationsMarked = true;
        if (window.$ && $.post) { // Check if jQuery ($) exists
            $.post('<?= base_url('api/mark_notifications_read.php') ?>', {
                _csrf: '<?= e(csrf_token()) ?>'
            }, function(response) {
                try { if (response.success) { badge.classList.add('animate-ping', 'opacity-0'); setTimeout(() => badge.remove(), 500); } } catch(e){}
            }).fail(function(){ 
                console.error('Failed to mark notifications as read.'); 
                notificationsMarked = false; // Allow retry
            });
        } else {
            // Fallback to fetch (if jQuery failed to load)
            console.error('jQuery not loaded. Using fallback fetch for notifications.');
            notificationsMarked = false; // Allow retry
            fetch('<?= base_url('api/mark_notifications_read.php') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: '_csrf=<?= e(csrf_token()) ?>'
            }).then(r => r.json()).then(function(response){
                try { if (response.success) { badge.classList.add('animate-ping', 'opacity-0'); setTimeout(() => badge.remove(), 500); } } catch(e){}
            }).catch(function(){ 
                console.error('Failed to mark notifications as read.'); 
            });
        }
    }
}
// ***** END Notification Logic *****
</script>
</body>
</html>