<?php
session_start();
// --- PHP Logic (Updated for 2FA) ---
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PragmaRX\Google2FA\Google2FA; 

require_login(); 

// ***** Notification Logic (copied from dashboard.php) *****
$u = isset($u) ? $u : (isset($currentUser) ? $currentUser : current_user());
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$notifStmt->execute([$u['id']]);
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
$unreadCountStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadCountStmt->execute([$u['id']]);
$unreadCount = (int)$unreadCountStmt->fetchColumn();
// ***** END Notification Logic *****

$u = current_user(); // Get current user's session data
$msg = '';
$msg_type = 'success'; // To control message styling

// --- 2FA Setup ---
$google2fa = new Google2FA();
$appName = $config['app']['name_en'] ?? 'Support System';
$action = $_POST['action'] ?? $_GET['action'] ?? ''; // Modified to accept GET
$showQr = false;
$qrCodeUrl = ''; 
$recoveryCodes = []; // For displaying after success

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    try {
        // --- Profile Update Action ---
        if ($action === 'profile') {
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $avatarPath = null; // Initialize avatar path
            $avatarUpdated = false;

            // Basic Validation
            if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                 throw new Exception(__t('invalid_input_data'));
            } 
            
            // Handle Avatar Upload
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                 $projectRoot = dirname(__DIR__, 1); // Project root (one level up from includes)
                 $avatarDir = $projectRoot . '/public/uploads/avatars'; // Store avatars in public/uploads/avatars
                 $relativePathPrefix = 'uploads/avatars/';

                 if (!is_dir($avatarDir)) @mkdir($avatarDir, 0777, true);
                 if (!is_writable($avatarDir)) throw new Exception("Error: Avatar directory is not writable: " . $avatarDir);

                 if ($_FILES['avatar']['size'] <= 1 * 1024 * 1024) { // Max 1MB for avatar
                     $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
                     if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                         $fname = 'user_' . $u['id'] . '_' . time() . '.' . $ext; // More descriptive filename
                         $destination = $avatarDir . '/' . $fname;

                         if (move_uploaded_file($_FILES['avatar']['tmp_name'], $destination)) {
                             $avatarPath = $relativePathPrefix . $fname; // Relative path for DB/URL
                             $avatarUpdated = true;

                             // Delete old avatar file if it exists and is different
                             $old_avatar_path = $u['avatar'] ?? null;
                             if ($old_avatar_path && $avatarPath !== $old_avatar_path && file_exists($projectRoot . '/public/' . $old_avatar_path)) {
                                 @unlink($projectRoot . '/public/' . $old_avatar_path);
                             }
                         } else { throw new Exception(__t('error_uploading_avatar')); }
                     } else { throw new Exception(__t('invalid_avatar_file_type')); }
                 } else { throw new Exception(__t('avatar_size_exceeded')); }
            } elseif (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
                  throw new Exception(__t('error_uploading_avatar') . ' (Code: ' . $_FILES['avatar']['error'] . ')');
            }

            // Update Database
            if ($avatarUpdated) {
                $pdo->prepare("UPDATE users SET name=?, phone=?, email=?, avatar=? WHERE id=?")
                    ->execute([$name, $phone, $email, $avatarPath, $u['id']]);
                $_SESSION['user']['avatar'] = $avatarPath; // Update session immediately
            } else {
                $pdo->prepare("UPDATE users SET name=?, phone=?, email=? WHERE id=?")
                    ->execute([$name, $phone, $email, $u['id']]);
            }
            
            // Update other session data
            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['email'] = $email;
            $_SESSION['user']['phone'] = $phone;
            
            $msg = __t('update_profile_success');
            $msg_type = 'success';
        } 
        // --- Password Change Action ---
        elseif ($action === 'password') {
            $cur = $_POST['current'] ?? '';
            $new = $_POST['new'] ?? '';
            $confirm = $_POST['confirm'] ?? '';

            $s = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
            $s->execute([$u['id']]);
            $hash = $s->fetchColumn();
            
            $ok = false;
            if ($hash) { 
                if (password_verify($cur, $hash)) { $ok = true; }
            }

            if (!$ok) {
                throw new Exception(__t('current_password_incorrect'));
            } elseif ($new !== $confirm) {
                throw new Exception(__t('passwords_do_not_match'));
            } elseif (strlen($new) < 6) {
                throw new Exception(__t('password_too_short'));
            } else {
                $newHash = password_hash($new, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
                    ->execute([$newHash, $u['id']]);
                $msg = __t('update_password_success');
                $msg_type = 'success';
            }
        }
        // --- 2FA Verify Action ---
        elseif ($action === 'verify') {
            if (empty($_SESSION['2fa_temp_secret'])) { throw new Exception("Session expired. Please try again."); }
            
            $secretKey = $_SESSION['2fa_temp_secret'];
            $code = $_POST['code'] ?? '';

            $isValid = $google2fa->verifyKey($secretKey, $code);

            if ($isValid) {
                $encryptedSecret = encrypt_2fa_secret($secretKey); // Uses function from functions.php
                $pdo->prepare("UPDATE users SET google2fa_secret = ?, two_fa_enabled = 1 WHERE id = ?")->execute([$encryptedSecret, $u['id']]);
                $pdo->prepare("DELETE FROM user_recovery_codes WHERE user_id = ?")->execute([$u['id']]);
                
                $stmt = $pdo->prepare("INSERT INTO user_recovery_codes (user_id, code) VALUES (?, ?)");
                for ($i = 0; $i < 10; $i++) {
                    $r_code = strtoupper(bin2hex(random_bytes(5)));
                    $hashedCode = password_hash($r_code, PASSWORD_DEFAULT);
                    $stmt->execute([$u['id'], $hashedCode]);
                    $recoveryCodes[] = $r_code;
                }
                
                unset($_SESSION['2fa_temp_secret']);
                $msg = __t('2fa_enabled_success');
                $msg_type = 'success';
                
                // Store recovery codes in session flash to show after redirect
                $_SESSION['flash_message'] = ['text' => $msg, 'type' => $msg_type, 'recovery_codes' => $recoveryCodes];
                header('Location: ' . base_url('profile.php'));
                exit;

            } else {
                throw new Exception(__t('2fa_invalid_code'));
            }
        }
        // --- 2FA Disable Action ---
        elseif ($action === 'disable') {

            // Fix: Check password before disabling
            $s = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
            $s->execute([$u['id']]);
            $hash = $s->fetchColumn();

            if (!$hash || !password_verify($_POST['password'], $hash)) {
                 throw new Exception(__t('invalid_password'));
            }

            $pdo->prepare("UPDATE users SET google2fa_secret = NULL, two_fa_enabled = 0 WHERE id = ?")->execute([$u['id']]);
            $pdo->prepare("DELETE FROM user_recovery_codes WHERE user_id = ?")->execute([$u['id']]);
            $msg = __t('2fa_disabled_success');
            $msg_type = 'success';
        }

    } catch (Exception $e) {
         $msg = $e->getMessage();
         $msg_type = 'error';
         error_log("Profile Update Error: " . $e->getMessage());
         // If verify failed, redirect back to the setup page
         if ($action === 'verify') {
             $_SESSION['flash_message'] = ['text' => $msg, 'type' => $msg_type];
             header('Location: ' . base_url('profile.php?action=setup')); 
             exit;
         }
    }

     // Use session flash message after POST action
     $_SESSION['flash_message'] = ['text' => $msg, 'type' => $msg_type];
     header('Location: ' . base_url('profile.php'));
     exit;
}

// --- Handle GET action for 'setup' ---
try {
    if ($action === 'setup' && !$u['two_fa_enabled']) {
        $secretKey = $google2fa->generateSecretKey();
        $_SESSION['2fa_temp_secret'] = $secretKey; 
        
        $userEmail = $u['email'] ?? 'user@example.com';
        $appNameForQR = $config['app']['name_en'] ?? 'Support System';
        
        // បង្កើត String សម្រាប់ QR Code (JavaScript នឹងយកទៅប្រើ)
        $qrCodeUrl = "otpauth://totp/" . rawurlencode($appNameForQR) . ":" . rawurlencode($userEmail) . "?secret=" . $secretKey . "&issuer=" . rawurlencode($appNameForQR);

        $showQr = true; 
    }
} catch (Exception $e) {
    $msg = $e->getMessage();
    $msg_type = 'error';
}


// Fetch the latest user data for display
$s=$pdo->prepare("SELECT * FROM users WHERE id=?"); 
$s->execute([$u['id']]); 
$user=$s->fetch(PDO::FETCH_ASSOC); 
// Update session with potentially changed data
$_SESSION['user'] = array_merge($_SESSION['user'], $user); 
$is2FAEnabled = $user['two_fa_enabled']; // Get the fresh 2FA status

$lang = current_lang();
$appName = $lang === 'km' ? $config['app']['name_km'] : $config['app']['name_en'];
$currentUser = current_user(); 

// Get flash message from session if exists
if (isset($_SESSION['flash_message'])) {
    $msg = $_SESSION['flash_message']['text'];
    $msg_type = $_SESSION['flash_message']['type'];
    if (isset($_SESSION['flash_message']['recovery_codes'])) {
        $recoveryCodes = $_SESSION['flash_message']['recovery_codes'];
    }
    unset($_SESSION['flash_message']); 
}
$defaultAvatar = base_url('assets/img/logo_32x32.png'); 

?>

<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appName) ?> - <?= __t('profile') ?></title>
	<link rel="icon" href="<?= base_url('assets/img/logo_32x32.png') ?>"> <script> /* Theme Loader */ if(localStorage.theme==='dark'||(!('theme' in localStorage)&&window.matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark')}else{document.documentElement.classList.remove('dark')} </script>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script> tailwind.config={darkMode:'class'} </script>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <script src="https://unpkg.com/lucide-react@latest/dist/lucide-react.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;700&display=swap');
        body { font-family: 'Kantumruy Pro', sans-serif; }
        #digital-particles { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
        ::-webkit-scrollbar { width: 6px; } ::-webkit-scrollbar-track { background: #f1f5f9; } ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 10px; } ::-webkit-scrollbar-thumb:hover { background: #475569; }
        .dark ::-webkit-scrollbar-track { background: #1e293b; } .dark ::-webkit-scrollbar-thumb { background: #334155; } .dark ::-webkit-scrollbar-thumb:hover { background: #475569; }
        #toast-notification { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 100; opacity: 0; transition: opacity 0.5s ease-in-out, transform 0.5s ease-in-out; transform: translateY(100%); }
        #toast-notification.show { opacity: 1; transform: translateY(0); }
        /* Style for file input button - Keep */
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
                         <img src="<?= base_url(get_setting('site_logo', 'assets/img/logo_128x128.png')) ?>" alt="Logo" class="h-10 w-auto">
                        <span class="text-xl font-bold text-gray-900 dark:text-white hidden sm:block"><?= e($appName) ?></span>
                    </a>
                    <div class="hidden md:flex items-center gap-4">
                        <a href="<?= base_url('dashboard.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('dashboard') ?></a>
                        <a href="<?= base_url('tickets.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('tickets') ?></a>
                        <a href="<?= base_url('kb.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('knowledge_base') ?></a>
                        <a href="<?= base_url('reports.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('reports') ?></a>
                        <?php if (is_role('admin')): ?>
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
                        <button onclick="toggleDropdown('profile-dropdown')" class="flex items-center gap-2 text-gray-900 dark:text-white text-sm ring-2 ring-blue-500 ring-offset-2 ring-offset-white dark:ring-offset-slate-800 rounded-full p-0.5"> 
                            <img src="<?= e($currentUser['avatar'] ? base_url($currentUser['avatar']) : $defaultAvatar) ?>?t=<?= time() ?>" alt="Profile" class="w-8 h-8 rounded-full object-cover bg-gray-200 dark:bg-slate-700">
                            <span class="hidden md:inline"><?= e($currentUser['name']) ?></span>
                            <svg class="w-4 h-4 text-gray-400 hidden md:inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div id="profile-dropdown" class="hidden absolute right-0 mt-2 w-48 bg-white dark:bg-slate-700 dark:text-gray-200 rounded-lg shadow-xl py-1 z-50">
                            <a href="<?= base_url('profile.php') ?>" class="block px-4 py-2 text-sm font-semibold text-blue-600 bg-blue-50 dark:bg-slate-600 dark:text-blue-300"><?= __t('profile') ?></a> 
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
                <?php if (is_role('admin')): ?>
                <a href="<?= base_url('admin/users.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('users') ?></a>
                <a href="<?= base_url('admin/settings.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('settings') ?></a>
                <?php endif; ?>
            </div>
        </header>

        <main class="flex-grow container mx-auto px-4 py-8 mt-8">

             <h1 class="text-2xl font-semibold text-gray-800 dark:text-white mb-6"><?= __t('My profile') ?></h1>

             <?php if($msg && empty($recoveryCodes)): // Hide default message if showing recovery codes ?>
                <div class="<?= $msg_type === 'success' ? 'bg-green-100 border-green-400 text-green-700 dark:bg-green-900/50 dark:border-green-600 dark:text-green-300' : 'bg-red-100 border-red-400 text-red-700 dark:bg-red-900/50 dark:border-red-600 dark:text-red-300' ?> border px-4 py-3 rounded-lg relative mb-6" role="alert">
                    <span class="block sm:inline"><?= e($msg) ?></span>
                </div>
             <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <div class="lg:col-span-2 space-y-8"> <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 md:p-8 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50">
                        <h5 class="text-xl font-semibold text-gray-700 dark:text-gray-200 mb-6 border-b border-gray-200 dark:border-slate-700 pb-3"><?= __t('profile_details') ?></h5>
                        <form method="post" enctype="multipart/form-data" class="space-y-6">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="profile">

                            <div class="flex flex-col sm:flex-row items-center gap-6">
                                <div class="flex-shrink-0">
                                    <img id="avatarPreview" class="h-24 w-24 rounded-full object-cover bg-gray-200 dark:bg-slate-700 border-2 border-gray-300 dark:border-slate-600 p-1" 
                                         src="<?= e($user['avatar'] ? base_url($user['avatar']) : $defaultAvatar) ?>?t=<?= time() ?>" 
                                         onerror="this.onerror=null; this.src='<?= e($defaultAvatar) ?>';"
                                         alt="Current Avatar">
                                </div>
                                <div class="flex-grow w-full">
                                    <label for="avatar" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('change_avatar') ?></label>
                                    <input type="file" name="avatar" id="avatar" accept="image/jpeg, image/png, image/gif, image/webp"
                                           onchange="previewAvatar(event)"
                                           class="w-full text-sm text-gray-500 dark:text-gray-400 cursor-pointer bg-gray-50 dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-1 focus:ring-blue-500">
                                     <p class="mt-1 text-xs text-gray-500 dark:text-gray-400"><?= __t('avatar_upload_note') ?></p>
                                </div>
                            </div>

                            <hr class="border-gray-200 dark:border-slate-700">

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('name') ?> <span class="text-red-500">*</span></label>
                                    <input type="text" id="name" name="name" value="<?= e($user['name']) ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                                </div>
                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('email') ?> <span class="text-red-500">*</span></label>
                                    <input type="email" id="email" name="email" value="<?= e($user['email']) ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                                </div>
                            </div>
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('phone') ?></label>
                                <input type="tel" id="phone" name="phone" value="<?= e($user['phone']) ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                            </div>

                            <div class="flex justify-end pt-4">
                                <button type="submit" class="px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                                    <?= __t('Save profile') ?>
                                </button>
                            </div>
                        </form>
                    </div>

                    <?php if (!empty($recoveryCodes)): ?>
                    <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 md:p-8 rounded-2xl shadow-lg border border-green-500 dark:border-green-600">
                        <h5 class="text-xl font-semibold text-green-700 dark:text-green-400 mb-6 border-b border-green-300 dark:border-green-700 pb-3"><?= __t('2fa_enabled_success') ?></h5>
                        
                        <div class="bg-green-100 border border-green-400 text-green-800 dark:bg-green-900/50 dark:border-green-600 dark:text-green-300 px-4 py-3 rounded-lg relative mb-6">
                            <strong class="font-bold"><?= __t('2fa_recovery_codes_warning') ?></strong>
                            <p class="mt-2"><?= __t('2fa_recovery_codes_info') ?></p>
                            
                            <div class="grid grid-cols-2 gap-2 bg-white dark:bg-slate-800 text-gray-900 dark:text-gray-200 font-mono p-4 rounded mt-4">
                                <?php foreach ($recoveryCodes as $code): ?>
                                    <span><?= e($code) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="flex justify-end pt-4">
                            <a href="<?= base_url('profile.php') ?>" class="px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700"><?= __t('done') ?></a>
                        </div>
                    </div>
                    <?php endif; ?>
                    </div>

                <div class="lg:col-span-1 space-y-8"> <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 md:p-8 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50">
                         <h5 class="text-xl font-semibold text-gray-700 dark:text-gray-200 mb-6 border-b border-gray-200 dark:border-slate-700 pb-3"><?= __t('Change password') ?></h5>
                         <form method="post" class="space-y-4">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="password">

                            <div>
                                <label for="current" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('Current password') ?></label>
                                <input type="password" id="current" name="current" required class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                            </div>
                            <div>
                                <label for="new" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('New password') ?></label>
                                <input type="password" id="new" name="new" required class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                                 <p class="mt-1 text-xs text-gray-500 dark:text-gray-400"><?= __t('Password min length') ?></p>
                            </div>
                            <div>
                                <label for="confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('Confirm password') ?></label>
                                <input type="password" id="confirm" name="confirm" required class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                            </div>

                            <div class="flex justify-end pt-4">
                                <button type="submit" class="px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                                    <?= __t('Update password') ?>
                                </button>
                            </div>
                        </form>
                    </div>

                    <?php if (empty($recoveryCodes)): ?>
                    <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 md:p-8 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50">
                        <h5 class="text-xl font-semibold text-gray-700 dark:text-gray-200 mb-6 border-b border-gray-200 dark:border-slate-700 pb-3"><?= __t('multi_factor_auth') ?></h5>

                        <?php // ----- 2. Show QR Code & Verify Form ----- ?>
                        <?php if ($showQr): ?>
                            <p class="text-gray-600 dark:text-gray-300 mb-4"><?= __t('2fa_scan_qr_prompt') ?></p>
                            
                            <div class="flex justify-center my-4">
                                <div id="qrcode" class="bg-white p-2 rounded-lg shadow-sm"></div>
                            </div>
                            
                            <script type="text/javascript">
                                new QRCode(document.getElementById("qrcode"), {
                                    text: "<?= $qrCodeUrl ?>",
                                    width: 200,
                                    height: 200,
                                    colorDark : "#000000",
                                    colorLight : "#ffffff",
                                    correctLevel : QRCode.CorrectLevel.H
                                });
                            </script>
                            <p class="text-center text-sm text-gray-500 dark:text-gray-400 mb-2"><?= __t('or_enter_key_manually') ?>:</p>
                            <p class="text-center font-mono text-lg text-gray-800 dark:text-gray-200 mb-6"><?= e($_SESSION['2fa_temp_secret']) ?></p>

                            <form method="post" action="profile.php">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="verify">
                                <div>
                                    <label for="code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('verification_code') ?></label>
                                    <input type="text" name="code" id="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 text-lg text-center font-mono tracking-widest">
                                </div>
                                <div class="flex justify-end gap-4 mt-6">
                                    <a href="profile.php" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-slate-600 dark:text-gray-200 dark:hover:bg-slate-500"><?= __t('cancel') ?></a>
                                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700"><?= __t('verify_and_activate') ?></button>
                                </div>
                            </form>

                        <?php // ----- 3. Show "Disable" Form ----- ?>
                        <?php elseif ($is2FAEnabled): ?>
                            <p class="text-green-600 dark:text-green-400 mb-4"><?= __t('2fa_is_enabled_message') ?></p>
                            <p class="text-gray-600 dark:text-gray-300 mb-4"><?= __t('2fa_disable_warning') ?></p>
                            <form method="post" action="profile.php">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="disable">
                                <div>
                                    <label for="password_2fa" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('current_password_to_confirm') ?></label>
                                    <input type="password" name="password" id="password_2fa" required
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div class="flex justify-end mt-6">
                                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg shadow-md hover:bg-red-700"><?= __t('disable_2fa') ?></button>
                                </div>
                            </form>
                            
                        <?php // ----- 1. Show "Enable" Button ----- ?>
                        <?php else: ?>
                            <p class="text-gray-600 dark:text-gray-300 mb-4"><?= __t('2fa_is_disabled_message') ?></p>
                            <div class="flex justify-end mt-6">
                                <a href="?action=setup" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700"><?= __t('enable_2fa') ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    </div>

            </div>
        </main>

        <footer class="bg-white dark:bg-slate-800/80 mt-12 py-4 border-t border-gray-200 dark:border-transparent">
             <div class="container mx-auto px-4 text-center text-gray-500 dark:text-gray-400 text-sm">
                 © <?= date('Y') ?> - <?= e($appName) ?> / <?= e(__t('Digital System Management Department')) ?>
             </div>
         </footer>
    </div>

     <div id="toast-notification" class="hidden max-w-xs p-4 text-gray-500 bg-white rounded-lg shadow dark:text-gray-400 dark:bg-gray-800" role="alert">
         <div class="flex items-center">
            <div id="toast-icon" class="inline-flex items-center justify-center flex-shrink-0 w-8 h-8 text-green-500 bg-green-100 rounded-lg dark:bg-green-800 dark:text-green-200">
                <svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20"><path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 8.207-4 4a1 1 0 0 1-1.414 0l-2-2a1 1 0 0 1 1.414-1.414L9 10.586l3.293-3.293a1 1 0 0 1 1.414 1.414Z"/></svg>
                <span class="sr-only">Check icon</span>
            </div>
            <div class="ms-3 text-sm font-normal" id="toast-message"></div>
            <button type="button" class="ms-auto -mx-1.5 -my-1.5 bg-white text-gray-400 hover:text-gray-900 rounded-lg focus:ring-2 focus:ring-gray-300 p-1.5 hover:bg-gray-100 inline-flex items-center justify-center h-8 w-8 dark:text-gray-500 dark:hover:text-white dark:bg-gray-800 dark:hover:bg-gray-700" data-dismiss-target="#toast-notification" aria-label="Close">
                <span class="sr-only">Close</span>
                <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/></svg>
            </button>
        </div>
     </div>


    <script> /* Digital Particle Script */ const canvas = document.getElementById('digital-particles'); /* ... */ // animate(); </script>
    
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
    
    <script> /* Toast Notification Script */
        const toastElement = document.getElementById('toast-notification'); 
        const toastMessage = document.getElementById('toast-message'); 
        const toastIcon = document.getElementById('toast-icon'); 
        const toastCloseButton = toastElement?.querySelector('[data-dismiss-target]'); 
        let toastTimeout;
        function showToast(message, type = 'success') { if (!toastElement || !toastMessage || !toastIcon) return; clearTimeout(toastTimeout); toastMessage.textContent = message; toastIcon.className = 'inline-flex items-center justify-center flex-shrink-0 w-8 h-8 rounded-lg'; if (type === 'success') { toastIcon.classList.add('text-green-500', 'bg-green-100', 'dark:bg-green-800', 'dark:text-green-200'); toastIcon.innerHTML = '<svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20"><path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 8.207-4 4a1 1 0 0 1-1.414 0l-2-2a1 1 0 0 1 1.414-1.414L9 10.586l3.293-3.293a1 1 0 0 1 1.414 1.414Z"/></svg>'; } else if (type === 'error') { toastIcon.classList.add('text-red-500', 'bg-red-100', 'dark:bg-red-800', 'dark:text-red-200'); toastIcon.innerHTML = '<svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20"><path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 11.793a1 1 0 1 1-1.414 1.414L10 11.414l-2.293 2.293a1 1 0 0 1-1.414-1.414L8.586 10 6.293 7.707a1 1 0 0 1 1.414-1.414L10 8.586l2.293-2.293a1 1 0 0 1 1.414 1.414L11.414 10l2.293 2.293Z"/></svg>'; } else { toastIcon.classList.add('text-blue-500', 'bg-blue-100', 'dark:bg-blue-800', 'dark:text-blue-200'); toastIcon.innerHTML = '<svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20"><path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5ZM9.5 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM12 15H8a1 1 0 0 1 0-2h1v-3H8a1 1 0 0 1 0-2h2a1 1 0 0 1 1 1v4h1a1 1 0 0 1 0 2Z"/></svg>'; } toastElement.classList.remove('hidden'); toastElement.offsetHeight; toastElement.classList.add('show'); toastTimeout = setTimeout(() => { hideToast(); }, 5000); }
        function hideToast() { if (!toastElement) return; toastElement.classList.remove('show'); clearTimeout(toastTimeout); setTimeout(() => { toastElement.classList.add('hidden'); }, 500); }
        if (toastCloseButton) { toastCloseButton.addEventListener('click', hideToast); }
        
        <?php if($msg && empty($recoveryCodes)): // Show toast, but NOT if showing recovery codes ?> 
            showToast('<?= e($msg) ?>', '<?= e($msg_type) ?>'); 
        <?php endif; ?>
         
    </script>
    <script>
        function previewAvatar(event) {
            const reader = new FileReader();
            reader.onload = function(){
                const output = document.getElementById('avatarPreview');
                output.src = reader.result;
            };
            if (event.target.files[0]) {
                reader.readAsDataURL(event.target.files[0]);
            } else {
                 output.src = '<?= e($user['avatar'] ? base_url($user['avatar']) : $defaultAvatar) ?>?t=' + new Date().getTime(); 
            }
        }
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