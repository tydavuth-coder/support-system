<?php 
session_start(); // FIX 1: Added session_start()

// --- PHP Logic (រក្សាទុកដដែល) ---
require_once __DIR__ . '/../../includes/functions.php'; 
require_once __DIR__ . '/../../includes/auth.php'; 
require_once __DIR__ . '/../../includes/csrf.php'; 
require_login(); 
require_role('admin'); // Only admins can access this page
// យើងមិន require header.php ឬ footer.php ទៀតទេ

$message = ''; // To store success/error messages after POST actions
$message_type = 'success'; // 'success' or 'error'

if($_SERVER['REQUEST_METHOD']==='POST'){ 
  csrf_check();
  
  // Delete Action
  if(isset($_POST['delete_id'])){ 
    $delete_id = (int)$_POST['delete_id'];
    // Prevent deleting the main admin (ID 1) or self
    $currentUser = current_user();
    if ($delete_id !== 1 && $delete_id !== $currentUser['id']) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id=? AND role <> 'admin'"); 
        if($stmt->execute([$delete_id])) {
            $message = __t('delete_user_success');
            $message_type = 'success';
        } else {
            $message = __t('delete_user_error');
            $message_type = 'error';
        }
    } elseif ($delete_id === 1) {
         $message = __t('cannot_delete_main_admin');
         $message_type = 'error';
    } else {
         $message = __t('cannot_delete_self');
         $message_type = 'error';
    }
  }
  // ***** START: 2FA RESET LOGIC *****
  elseif(isset($_POST['reset_2fa_id'])) {
      $reset_id = (int)$_POST['reset_2fa_id'];
      
      try {
          // Query 1: Disable 2FA and clear secret
          $stmt1 = $pdo->prepare("UPDATE users SET google2fa_secret = NULL, two_fa_enabled = 0 WHERE id = ?");
          $stmt1->execute([$reset_id]);
          
          // Query 2: Delete all recovery codes for that user
          $stmt2 = $pdo->prepare("DELETE FROM user_recovery_codes WHERE user_id = ?");
          $stmt2->execute([$reset_id]);
          
          $message = __t('reset_2fa_success'); // E.g., "2FA has been successfully reset for the user."
          $message_type = 'success';
          
      } catch (PDOException $e) {
          $message = __t('database_error') . ': ' . $e->getMessage();
          $message_type = 'error';
          error_log("2FA Reset Error: " . $e->getMessage());
      }
  }
  // ***** END: 2FA RESET LOGIC *****
  
  // Add/Edit Action
  else { 
    $id=(int)($_POST['id']??0); 
    $name=trim($_POST['name']??''); 
    $email=trim($_POST['email']??''); 
    $phone=trim($_POST['phone']??''); 
    $role=$_POST['role']??'user'; 
    $pwd=$_POST['password']??'';

    // Basic Validation
    if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = __t('invalid_input_data');
        $message_type = 'error';
    } 
    // Check if email already exists (for ADD or when changing email on EDIT)
    elseif (($id == 0 || ($id > 0 && strtolower($email) !== strtolower($_POST['original_email'] ?? ''))) ) {
         $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
         $stmtCheck->execute([$email]);
         if ($stmtCheck->fetchColumn() > 0) {
             $message = __t('email_already_exists');
             $message_type = 'error';
         }
    }
    // Check password length if provided
    elseif (!empty($pwd) && strlen($pwd) < 6) {
        $message = __t('Password too short');
        $message_type = 'error';
    }
    
    // Proceed if no validation errors
    if ($message_type === 'success') { // Check if message_type is still success
        try {
            if($id){ // Update existing user
                if($pwd){ 
                    $hash=password_hash($pwd,PASSWORD_DEFAULT); 
                    $pdo->prepare("UPDATE users SET name=?, email=?, phone=?, role=?, password_hash=? WHERE id=?")
                        ->execute([$name,$email,$phone,$role,$hash,$id]); 
                } else { 
                    // Prevent changing role of main admin (ID 1) by others
                    if ($id === 1 && current_user()['id'] !== 1) $role = 'admin'; 
                    $pdo->prepare("UPDATE users SET name=?, email=?, phone=?, role=? WHERE id=?")
                        ->execute([$name,$email,$phone,$role,$id]); 
                } 
                $message = __t('update user success');
            } else { // Add new user
                $hash=$pwd ? password_hash($pwd,PASSWORD_DEFAULT) : password_hash('User@123',PASSWORD_DEFAULT); // Default password? Consider requiring one.
                $pdo->prepare("INSERT INTO users(name,email,phone,role,password_hash,created_at) VALUES(?,?,?,?,?,NOW())")
                    ->execute([$name,$email,$phone,$role,$hash]); 
                 $message = __t('create user success');
            }
        } catch (PDOException $e) {
            $message = __t('database_error') . ': ' . $e->getMessage();
            $message_type = 'error';
            error_log("User Save Error: " . $e->getMessage());
        }
    }
  }
  // Redirect back to users page with message after POST action to prevent resubmission
  $_SESSION['flash_message'] = ['text' => $message, 'type' => $message_type];
  header('Location: ' . base_url('admin/users.php'));
  exit;
}

// ***** MODIFIED: Added 'two_fa_enabled' to the query *****
$users=$pdo->query("SELECT id,name,email,phone,role,avatar,created_at,two_fa_enabled FROM users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$lang = current_lang();
$appName = $lang === 'km' ? $config['app']['name_km'] : $config['app']['name_en'];
$currentUser = current_user(); 
$defaultAvatar = base_url('assets/img/logo.png'); // FIX: Consistent avatar

// ***** FIX 2: Added Notification Logic *****
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$notifStmt->execute([$currentUser['id']]);
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
$unreadCountStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadCountStmt->execute([$currentUser['id']]);
$unreadCount = (int)$unreadCountStmt->fetchColumn();
// ***** END Notification Logic *****


// Get flash message from session if exists
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message']['text'];
    $message_type = $_SESSION['flash_message']['type'];
    unset($_SESSION['flash_message']); // Clear message after displaying
}
?>

<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appName) ?> - <?= __t('users') ?></title>
	<link rel="icon" href="<?= base_url('assets/img/logo.png') ?>">
    <script> /* Theme Loader */ if(localStorage.theme==='dark'||(!('theme' in localStorage)&&window.matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark')}else{document.documentElement.classList.remove('dark')} </script>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script> tailwind.config={darkMode:'class'} </script>
    
    <script src="https://unpkg.com/lucide-react@latest/dist/lucide-react.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;700&display=swap');
        body { font-family: 'Kantumruy Pro', sans-serif; }
        #digital-particles { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
        ::-webkit-scrollbar { width: 6px; } ::-webkit-scrollbar-track { background: #f1f5f9; } ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 10px; } ::-webkit-scrollbar-thumb:hover { background: #475569; }
        .dark ::-webkit-scrollbar-track { background: #1e293b; } .dark ::-webkit-scrollbar-thumb { background: #334155; } .dark ::-webkit-scrollbar-thumb:hover { background: #475569; }
        /* Modal Styles */
        .modal-backdrop { background-color: rgba(0, 0, 0, 0.5); }
        .modal-content { max-height: 80vh; overflow-y: auto; }
        /* Responsive Table Styles */
        @media (max-width: 768px) {
            .table-responsive thead { display: none; }
            .table-responsive tr { display: block; margin-bottom: 1rem; border-bottom: 2px solid #e5e7eb; padding-bottom: 0.5rem; }
            .dark .table-responsive tr { border-bottom-color: #374151; }
            .table-responsive td { display: block; text-align: right; padding-left: 50%; position: relative; padding-top: 0.5rem; padding-bottom: 0.5rem; }
            .table-responsive td::before { content: attr(data-label); position: absolute; left: 0.5rem; width: calc(50% - 1rem); padding-right: 0.5rem; white-space: nowrap; text-align: left; font-weight: bold; color: #4b5563; }
            .dark .table-responsive td::before { color: #9ca3af; }
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 dark:bg-slate-900 dark:text-gray-300 transition-colors duration-300">

    <canvas id="digital-particles"></canvas>
    
    <div class="flex flex-col min-h-screen">
    
        <header class="sticky top-0 z-50 bg-white/80 dark:bg-slate-800/80 backdrop-blur-sm shadow-md dark:shadow-lg border-b border-gray-200 dark:border-transparent">
             <nav class="container mx-auto px-4 py-3 flex justify-between items-center">
                 <div class="flex items-center gap-6">
                    <a href="<?= base_url('dashboard.php') ?>" class="flex items-center gap-2">
                        <img src="<?= base_url('assets/img/logo_128x128.png') ?>" alt="Logo" class="w-10 h-10">
                        <span class="text-xl font-bold text-gray-900 dark:text-white hidden sm:block"><?= e($appName) ?></span>
                    </a>
                    <div class="hidden md:flex items-center gap-4">
                        <a href="<?= base_url('dashboard.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('dashboard') ?></a>
                        <a href="<?= base_url('tickets.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('tickets') ?></a>
                        <a href="<?= base_url('kb.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('knowledge_base') ?></a>
                        <a href="<?= base_url('reports.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('reports') ?></a>
                        <?php if ($currentUser['role'] === 'admin'): ?>
                        <a href="<?= base_url('admin/users.php') ?>" class="text-white font-semibold bg-blue-600 px-3 py-1.5 rounded-lg text-sm"><?= __t('users') ?></a>
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
                <a href="<?= base_url('tickets.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('tickets') ?></a>
                <a href="<?= base_url('kb.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('knowledge_base') ?></a>
                <a href="<?= base_url('reports.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('reports') ?></a>
                <?php if ($currentUser['role'] === 'admin'): ?>
                <a href="<?= base_url('admin/users.php') ?>" class="block text-white font-semibold bg-blue-600 px-3 py-2 rounded-lg text-sm"><?= __t('users') ?></a>
                <a href="<?= base_url('admin/settings.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('settings') ?></a>
                <?php endif; ?>
            </div>
        </header>

        <main class="flex-grow container mx-auto px-4 py-8 mt-8">
            
            <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50">
                
                <div class="flex flex-col sm:flex-row justify-between items-center mb-6 gap-4">
                    <h5 class="text-xl font-semibold text-gray-700 dark:text-gray-200"><?= __t('users') ?></h5>
                    <button onclick="openUserModal()" class="w-full sm:w-auto flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <?= __t('create_user') ?>
                    </button>
                </div>

                <?php if($message): ?>
                    <div class="<?= $message_type === 'success' ? 'bg-green-100 border-green-400 text-green-700 dark:bg-green-900/50 dark:border-green-600 dark:text-green-300' : 'bg-red-100 border-red-400 text-red-700 dark:bg-red-900/50 dark:border-red-600 dark:text-red-300' ?> border px-4 py-3 rounded-lg relative mb-6" role="alert">
                        <span class="block sm:inline"><?= e($message) ?></span>
                    </div>
                <?php endif; ?>

                <div class="overflow-x-auto table-responsive">
                    <table class="w-full text-sm text-left text-gray-600 dark:text-gray-400">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-slate-700 dark:text-gray-300">
                            <tr>
                                <th scope="col" class="px-4 py-3">ID</th>
                                <th scope="col" class="px-4 py-3"><?= __t('name') ?></th>
                                <th scope="col" class="px-4 py-3"><?= __t('email') ?></th>
                                <th scope="col" class="px-4 py-3"><?= __t('phone') ?></th>
                                <th scope="col" class="px-4 py-3"><?= __t('role') ?></th>
                                <th scope="col" class="px-4 py-3 text-right"><?= __t('actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr class="border-b dark:border-slate-700">
                                    <td colspan="6" class="px-4 py-4 text-center text-gray-500 dark:text-gray-400">
                                        <?= __t('no_users_found') ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach($users as $u_row): // Use different variable name like $u_row ?>
                            <tr class="bg-white dark:bg-slate-800 border-b dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700">
                                <td data-label="ID" class="px-4 py-3 font-medium text-gray-900 dark:text-white">#<?= (int)$u_row['id'] ?></td>
                                <td data-label="<?= __t('name') ?>" class="px-4 py-3 flex items-center gap-2">
                                     <img class="w-8 h-8 rounded-full object-cover bg-gray-200 dark:bg-slate-700" src="<?= e($u_row['avatar'] ? base_url($u_row['avatar']) : $defaultAvatar) ?>?t=<?= time() ?>" alt="">
                                     <span class="font-medium"><?= e($u_row['name']) ?></span>
                                </td>
                                <td data-label="<?= __t('email') ?>" class="px-4 py-3"><?= e($u_row['email']) ?></td>
                                <td data-label="<?= __t('phone') ?>" class="px-4 py-3"><?= e($u_row['phone'] ?: '-') ?></td>
                                <td data-label="<?= __t('role') ?>" class="px-4 py-3">
                                    <?php 
                                    $roleClass = match($u_row['role']) {
                                        'admin' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
                                        'coordinator' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
                                        'technical' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
                                        default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
                                    };
                                    ?>
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $roleClass ?>"><?= e(ucfirst($u_row['role'])) ?></span>
                                    
                                    <?php // ***** MODIFIED: Show 2FA Status *****
                                    if ($u_row['two_fa_enabled']): ?>
                                        <span class="ml-1 px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300">
                                            2FA
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="<?= __t('actions') ?>" class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button class="flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:underline" 
                                                onclick='openUserModal(<?= json_encode($u_row, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)' 
                                                title="<?= __t('edit') ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                            <span class="hidden sm:inline"><?= __t('edit') ?></span>
                                        </button>
                                        
                                        <?php // ***** START: 2FA RESET BUTTON *****
                                        // Show reset button only if 2FA is enabled
                                        if($u_row['two_fa_enabled']): ?>
                                        <form method="post" class="inline-block reset-2fa-form">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="reset_2fa_id" value="<?= (int)$u_row['id'] ?>">
                                            <button type="button" class="flex items-center gap-1 text-yellow-600 dark:text-yellow-400 hover:underline reset-2fa-button" 
                                                    title="<?= __t('Reset 2FA') // E.g., 'Reset 2FA' ?>"
                                                    data-confirm-message="<?= e(__t('confirm_reset_2fa', ['name' => $u_row['name']])) // E.g., 'Are you sure you want to reset 2FA for...?' ?>">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777z"></path><path d="M15 7h0a3 3 0 0 0-3 3 3 3 0 0 0 3 3 3 3 0 0 0 3-3 3 3 0 0 0-3-3z"></path></svg>
                                                <span class="hidden sm:inline"><?= __t('Reset 2FA') ?></span>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <?php // ***** END: 2FA RESET BUTTON ***** ?>

                                        <?php // Prevent deleting main admin or self
                                        if($u_row['id'] !== 1 && $u_row['id'] !== $currentUser['id']): ?>
                                        <form method="post" class="inline-block delete-user-form">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="delete_id" value="<?= (int)$u_row['id'] ?>">
                                            <button type="button" class="flex items-center gap-1 text-red-600 dark:text-red-400 hover:underline delete-button" 
                                                    title="<?= __t('delete') ?>"
                                                    data-confirm-message="<?= e(__t('confirm_delete_user', ['name' => $u_row['name']])) // Example: "Are you sure you want to delete user Davuth Ty?" ?>">
                                                 <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                                 <span class="hidden sm:inline"><?= __t('delete') ?></span>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php // Add Pagination if needed here ?>
            </div>
        </main>
    
        <footer class="bg-white dark:bg-slate-800/80 mt-12 py-4 border-t border-gray-200 dark:border-transparent">
             <div class="container mx-auto px-4 text-center text-gray-500 dark:text-gray-400 text-sm">
                 © <?= date('Y') ?> - <?= e($appName) ?> / <?= e(__t('Digital System Management Department')) ?>
             </div>
         </footer>
    </div>

    <div id="userModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
        <div class="fixed inset-0 modal-backdrop transition-opacity duration-300" onclick="closeUserModal()"></div>
        
        <div class="bg-white dark:bg-slate-800 rounded-lg shadow-xl transform transition-all sm:max-w-lg w-full z-10 modal-content">
            <form method="post" id="userForm" class="p-6 space-y-4">
                 <?php csrf_field(); ?>
                 <input type="hidden" name="id" id="f_id">
                 <input type="hidden" name="original_email" id="f_original_email"> <h5 class="text-lg font-semibold text-gray-800 dark:text-white" id="modalTitle"><?= __t('create_user') ?></h5>

                 <div>
                    <label for="f_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('name') ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="f_name" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200">
                 </div>
                 <div>
                    <label for="f_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('email') ?> <span class="text-red-500">*</span></label>
                    <input type="email" name="email" id="f_email" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200">
                 </div>
                  <div>
                    <label for="f_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('phone') ?></label>
                    <input type="tel" name="phone" id="f_phone" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200">
                 </div>
                  <div>
                    <label for="f_role" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('role') ?></label>
                    <select name="role" id="f_role" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200">
                        <option value="user"><?= __t('user') ?></option>
                        <option value="technical"><?= __t('technical') ?></option>
                        <option value="coordinator"><?= __t('coordinator') ?></option>
                        <option value="admin"><?= __t('admin') ?></option>
                    </select>
                 </div>
                 <div>
                    <label for="f_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('password') ?></label>
                    <input type="password" name="password" id="f_password" 
                           placeholder="<?= __t('Password') // Example: (Leave blank to keep current) ?>" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200">
                     <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" id="password_hint">
                        <?= __t('password_edit_hint') // E.g., Leave blank to keep current password. ?>
                     </p>
                 </div>
                 
                 <div class="flex justify-end gap-4 pt-4 border-t border-gray-200 dark:border-slate-700">
                    <button type="button" onclick="closeUserModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg shadow-sm hover:bg-gray-200 dark:bg-slate-600 dark:text-gray-200 dark:hover:bg-slate-500 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                        <?= __t('cancel') ?>
                    </button>
                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                         <?= __t('save') ?>
                    </button>
                 </div>
            </form>
        </div>
    </div>

     <div id="confirmModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4 hidden">
        <div class="fixed inset-0 modal-backdrop transition-opacity duration-300"></div>
        <div class="bg-white dark:bg-slate-800 rounded-lg shadow-xl transform transition-all sm:max-w-md w-full z-10">
            <div class="p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2" id="confirmTitle"><?= __t('confirm_action') ?></h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4" id="confirmMessage"></p>
                <div class="flex justify-end gap-3">
                    <button type="button" id="confirmCancel" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg shadow-sm hover:bg-gray-200 dark:bg-slate-600 dark:text-gray-200 dark:hover:bg-slate-500 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                        <?= __t('cancel') ?>
                    </button>
                    <button type="button" id="confirmOk" class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg shadow-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                        <?= __t('delete') ?>
                    </button>
                </div>
            </div>
        </div>
    </div>


    <script> /* Digital Particle Script */ 
        const canvas = document.getElementById('digital-particles'); const ctx = canvas.getContext('2d'); canvas.width = window.innerWidth; canvas.height = window.innerHeight; let particlesArray = []; const numberOfParticles = (canvas.width * canvas.height) / 9000; const particleColorDark = 'rgba(255, 255, 255, 0.4)'; const particleColorLight = 'rgba(0, 0, 0, 0.2)'; const lineColorDark = 'rgba(100, 180, 255, 0.15)'; const lineColorLight = 'rgba(0, 100, 255, 0.15)'; function getParticleColors() { const isDarkMode = document.documentElement.classList.contains('dark'); return { particle: isDarkMode ? particleColorDark : particleColorLight, line: isDarkMode ? lineColorDark : lineColorLight }; } class Particle { constructor(x, y, dX, dY, s) { this.x=x;this.y=y;this.directionX=dX;this.directionY=dY;this.size=s;} draw() { ctx.beginPath(); ctx.arc(this.x,this.y,this.size,0,Math.PI*2,false); ctx.fillStyle=getParticleColors().particle; ctx.fill(); } update() { if(this.x>canvas.width||this.x<0)this.directionX=-this.directionX; if(this.y>canvas.height||this.y<0)this.directionY=-this.directionY; this.x+=this.directionX; this.y+=this.directionY; this.draw();}} function init() { particlesArray=[]; for(let i=0;i<numberOfParticles;i++) { let s=Math.random()*2+1,x=Math.random()*canvas.width,y=Math.random()*canvas.height,dX=(Math.random()*0.4)-0.2,dY=(Math.random()*0.4)-0.2; particlesArray.push(new Particle(x,y,dX,dY,s));}} function connect() { let opVal=1; const curLineColor=getParticleColors().line; for(let a=0;a<particlesArray.length;a++) { for(let b=a+1;b<particlesArray.length;b++) { let dist=Math.sqrt((particlesArray[a].x-particlesArray[b].x)**2 + (particlesArray[a].y-particlesArray[b].y)**2); if(dist<120) { opVal=1-(dist/120); ctx.strokeStyle=curLineColor.replace('0.15',opVal); ctx.lineWidth=0.5; ctx.beginPath(); ctx.moveTo(particlesArray[a].x,particlesArray[a].y); ctx.lineTo(particlesArray[b].x,particlesArray[b].y); ctx.stroke();}}}} function animate() { ctx.clearRect(0,0,canvas.width,canvas.height); particlesArray.forEach(p => p.update()); connect(); requestAnimationFrame(animate);} window.addEventListener('resize', () => { canvas.width=window.innerWidth; canvas.height=window.innerHeight; init();}); init(); animate(); </script>
    <script> /* Dropdown & Theme Toggle Script */ 
        function toggleDropdown(id) { document.getElementById(id).classList.toggle('hidden'); } 
        window.addEventListener('click', function(e) { 
            const pM=document.getElementById('profile-dropdown-menu'); 
            if(pM&&!pM.contains(e.target))document.getElementById('profile-dropdown').classList.add('hidden'); 

            // FIX 6: Added Notification click-outside logic
            const notifMenu = document.getElementById('notification-dropdown-menu');
            if (notifMenu && !notifMenu.contains(e.target)) {
                document.getElementById('notification-dropdown').classList.add('hidden');
            }
            
            const mN=document.getElementById('mobile-menu'), mB=document.querySelector('button[onclick*="mobile-menu"]'); 
            if(mN&&!mN.contains(e.target)&&!mB.contains(e.target))mN.classList.add('hidden');
        }); 
        const themeToggle=document.getElementById('theme-toggle'), sunIcon=document.getElementById('theme-icon-sun'), moonIcon=document.getElementById('theme-icon-moon'); 
        function updateThemeIcon() { if(document.documentElement.classList.contains('dark')) { sunIcon.classList.remove('hidden'); moonIcon.classList.add('hidden'); } else { sunIcon.classList.add('hidden'); moonIcon.classList.remove('hidden');}} updateThemeIcon(); 
        themeToggle.addEventListener('click', () => { document.documentElement.classList.toggle('dark'); if(document.documentElement.classList.contains('dark')) localStorage.theme='dark'; else localStorage.theme='light'; updateThemeIcon(); }); 
    </script>
    
    <script>
        // ***** NEW: Notification Mark as Read Logic *****
        let notificationsMarked = false; 
        function markNotificationsAsRead() {
            const badge = document.getElementById('notification-badge');
            if (badge && !notificationsMarked) {
                notificationsMarked = true; 
                // Use jQuery (added to head)
                $.post('<?= base_url('api/mark_notifications_read.php') ?>', {
                    _csrf: '<?= e(csrf_token()) ?>'
                }, function(response) {
                    if (response.success) {
                        badge.classList.add('animate-ping', 'opacity-0');
                        setTimeout(() => { badge.remove(); }, 500); 
                    } else {
                        console.error('Failed to mark notifications as read.');
                        notificationsMarked = false; 
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                     console.error('AJAX error marking notifications as read:', textStatus, errorThrown, jqXHR.responseText);
                     notificationsMarked = false; 
                });
            }
        }
    </script>
    
    <script>
        const userModal = document.getElementById('userModal');
        const modalTitle = document.getElementById('modalTitle');
        const userForm = document.getElementById('userForm');
        const formId = document.getElementById('f_id');
        const formName = document.getElementById('f_name');
        const formEmail = document.getElementById('f_email');
        const formOriginalEmail = document.getElementById('f_original_email');
        const formPhone = document.getElementById('f_phone');
        const formRole = document.getElementById('f_role');
        const formPassword = document.getElementById('f_password');
        const passwordHint = document.getElementById('password_hint');

        const confirmModal = document.getElementById('confirmModal');
        const confirmMessage = document.getElementById('confirmMessage');
        const confirmOk = document.getElementById('confirmOk');
        const confirmCancel = document.getElementById('confirmCancel');
        let formToSubmit = null; // To store the form to submit after confirmation

        function openUserModal(user = null) {
            userForm.reset(); // Clear previous data
            if (user) {
                // Edit mode
                modalTitle.textContent = '<?= e(__t('edit_user')) ?>';
                formId.value = user.id || '';
                formName.value = user.name || '';
                formEmail.value = user.email || '';
                formOriginalEmail.value = user.email || ''; // Store original email
                formPhone.value = user.phone || '';
                formRole.value = user.role || 'user';
                formPassword.placeholder = '<?= e(__t('Password')) ?>'; 
                passwordHint.textContent = '<?= e(__t('password_edit_hint')) ?>';
                // Disable role change for main admin (ID 1) unless it's the admin themselves
                if (user.id === 1 && <?= $currentUser['id'] ?> !== 1) {
                    formRole.disabled = true;
                } else {
                    formRole.disabled = false;
                }
            } else {
                // Add mode
                modalTitle.textContent = '<?= e(__t('create_user')) ?>';
                formId.value = '';
                formOriginalEmail.value = '';
                formPassword.placeholder = '<?= e(__t('password_placeholder_add')) // E.g., (Default: User@123 if blank) ?>';
                passwordHint.textContent = '<?= e(__t('password_add_hint')) // E.g., Min 6 characters. Default is User@123 if left blank. ?>';
                formRole.disabled = false;
            }
            userModal.classList.remove('hidden');
        }

        function closeUserModal() {
            userModal.classList.add('hidden');
        }

        // ***** START: MODIFIED CONFIRMATION LOGIC *****

        // Custom Confirmation Logic for Delete
        document.querySelectorAll('.delete-button').forEach(button => {
            button.addEventListener('click', (event) => {
                event.preventDefault(); // Prevent default form submission
                formToSubmit = button.closest('form'); // Get the form
                const message = button.getAttribute('data-confirm-message');
                confirmMessage.textContent = message || '<?= e(__t('confirm_generic')) ?>'; // Default message
                
                // Style button for DELETE
                confirmOk.className = "px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg shadow-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800";
                confirmOk.textContent = '<?= e(__t('delete')) ?>';

                confirmModal.classList.remove('hidden');
            });
        });

        // Custom Confirmation Logic for Reset 2FA
        document.querySelectorAll('.reset-2fa-button').forEach(button => {
            button.addEventListener('click', (event) => {
                event.preventDefault(); 
                formToSubmit = button.closest('form');
                const message = button.getAttribute('data-confirm-message');
                confirmMessage.textContent = message || '<?= e(__t('confirm_generic')) ?>';
                
                // Style button for RESET (yellow/orange)
                confirmOk.className = "px-4 py-2 text-sm font-medium text-white bg-yellow-600 rounded-lg shadow-md hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800";
                confirmOk.textContent = '<?= e(__t('reset_2fa')) ?>';

                confirmModal.classList.remove('hidden');
            });
        });
        // ***** END: MODIFIED CONFIRMATION LOGIC *****


        confirmCancel.addEventListener('click', () => {
            confirmModal.classList.add('hidden');
            formToSubmit = null;
        });

        confirmOk.addEventListener('click', () => {
            if (formToSubmit) {
                formToSubmit.submit(); // Submit the stored form
            }
            confirmModal.classList.add('hidden');
            formToSubmit = null;
        });

         // Close modal if backdrop is clicked
        confirmModal.addEventListener('click', (event) => {
            if (event.target === confirmModal) {
                 confirmModal.classList.add('hidden');
                 formToSubmit = null;
            }
        });
         userModal.addEventListener('click', (event) => {
            if (event.target === userModal) {
                 closeUserModal();
            }
        });


    </script>

</body>
</html>