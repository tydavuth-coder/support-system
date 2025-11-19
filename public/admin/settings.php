<?php
session_start(); // FIX 1: Added session_start()
// --- PHP Logic (Mostly Unchanged) ---
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_login();
require_role('admin'); // Only admins

$notice = '';
$notice_type = 'success'; // To control notice styling

// --- Function to handle settings saving ---
function save_settings() {
    global $pdo, $notice, $notice_type; // Use global variables

    $settings_updated = false;

    try {
        // Handle uploads first (only if file input exists in the submitted form)
        if (!empty($_FILES)) {
            $projectRoot = dirname(__DIR__, 2);
            $brandDir = $projectRoot . '/uploads/branding';

            if (!is_dir($brandDir)) {
                if (!@mkdir($brandDir, 0777, true)) {
                    throw new Exception("Error: Cannot create branding directory. Check permissions: " . $brandDir);
                }
            } elseif (!is_writable($brandDir)) {
                throw new Exception("Error: Branding directory is not writable. Check permissions: " . $brandDir);
            }

            // Site Logo Upload
            if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
                if ($_FILES['site_logo']['size'] <= 2 * 1024 * 1024) { // Max 2MB check
                    $ext = strtolower(pathinfo($_FILES['site_logo']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'])) {
                        $fname = random_filename($ext);
                        $destination = $brandDir . '/' . $fname;

                        if (move_uploaded_file($_FILES['site_logo']['tmp_name'], $destination)) {
                            $relativePath = 'uploads/branding/' . $fname;
                            $old_logo_path = get_setting('site_logo', '');
                            if ($old_logo_path && $relativePath !== $old_logo_path && file_exists($projectRoot . '/' . $old_logo_path)) {
                                @unlink($projectRoot . '/' . $old_logo_path);
                            }
                            set_setting('site_logo', $relativePath);
                            $settings_updated = true;
                        } else {
                            $uploadError = $_FILES['site_logo']['error'];
                            throw new Exception(__t('error_uploading_logo') . " (Failed to move file. Code: $uploadError)");
                        }
                    } else {
                        throw new Exception(__t('invalid_logo_file_type') . " (.{$ext})");
                    }
                } else {
                    throw new Exception(__t('logo_size_exceeded') . " (Max 2MB)");
                }
            } elseif (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                throw new Exception(__t('error_uploading_logo') . ' (Code: ' . $_FILES['site_logo']['error'] . ')');
            }
            // Add Font upload logic here if needed
        }

        // Save other text/select settings (only if they exist in the submitted form)
        $keys = ['site_name_en', 'site_name_km', 'default_lang', 'default_theme',
                 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_encryption',
                 'twilio_sid', 'twilio_token', 'twilio_from',
                 'sla_low', 'sla_normal', 'sla_high', 'sla_urgent'];

        foreach ($keys as $k) {
            if (isset($_POST[$k])) { // Check if the key exists in POST data for *this* form submission
                $newValue = trim($_POST[$k]);
                $isPasswordField = ($k === 'smtp_pass' || $k === 'twilio_token');
                if ($isPasswordField && empty($newValue)) {
                    continue; // Skip saving empty password fields
                }
                if (get_setting($k, null) !== $newValue) {
                    set_setting($k, $newValue);
                    $settings_updated = true;
                }
            }
        }

        if ($settings_updated) {
            $notice = __t('update_success');
            $notice_type = 'success';
        } else {
            $notice = __t('no_changes_detected');
            $notice_type = 'info';
        }

    } catch (Exception $e) {
        $notice = $e->getMessage();
        $notice_type = 'error';
        error_log("Settings Save Error: " . $e->getMessage());
    }

    $_SESSION['flash_message'] = ['text' => $notice, 'type' => $notice_type];
    header('Location: ' . base_url('admin/settings.php'));
    exit;
}


// --- POST request handling ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    save_settings(); // Call the refactored save function
}

// --- Load settings (remains the same) ---
$settings=[
  'site_name_en'=>get_setting('site_name_en','Support System'),
  'site_name_km'=>get_setting('site_name_km','ប្រព័ន្ធជំនួយគាំទ្រ'),
  'default_lang'=>get_setting('default_lang','km'),
  'default_theme'=>get_setting('default_theme','system'),
  'smtp_host'=>get_setting('smtp_host',''),
  'smtp_port'=>get_setting('smtp_port','587'),
  'smtp_user'=>get_setting('smtp_user',''),
  'smtp_pass'=>'', // Never display saved password
  'smtp_encryption'=>get_setting('smtp_encryption','tls'),
  'twilio_sid'=>get_setting('twilio_sid',''),
  'twilio_token'=>'', // Never display saved token
  'twilio_from'=>get_setting('twilio_from',''),
  'sla_low'=>get_setting('sla_low','24'),
  'sla_normal'=>get_setting('sla_normal','8'),
  'sla_high'=>get_setting('sla_high','4'),
  'sla_urgent'=>get_setting('sla_urgent','2'),
  'site_logo'=>get_setting('site_logo',''),
];

$lang = current_lang();
$appName = $lang === 'km' ? $settings['site_name_km'] : $settings['site_name_en'];
$currentUser = current_user();

// ***** FIX 2: Added Notification Logic *****
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$notifStmt->execute([$currentUser['id']]);
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
$unreadCountStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadCountStmt->execute([$currentUser['id']]);
$unreadCount = (int)$unreadCountStmt->fetchColumn();
$defaultAvatar = base_url('assets/img/logo_32x32.png'); // <-- FIX 3: Added Default Avatar
// ***** END Notification Logic *****

// Get flash message from session if exists
if (isset($_SESSION['flash_message'])) {
    $notice = $_SESSION['flash_message']['text'];
    $notice_type = $_SESSION['flash_message']['type'];
    unset($_SESSION['flash_message']); // Clear message after displaying
}
?>

<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appName) ?> - <?= __t('settings') ?></title>
	<link rel="icon" href="<?= base_url('assets/img/logo_32x32.png') ?>"> <script> /* Theme Loader */
        const preferredTheme = localStorage.theme || '<?= e($settings['default_theme']) ?>';
        if (preferredTheme === 'dark' || (preferredTheme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    
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
        input[type="file"]::file-selector-button { margin-right: 1rem; padding: 0.5rem 1rem; border-radius: 0.5rem; border: 0; font-size: 0.875rem; font-weight: 600; background-color: #e0f2fe; color: #0369a1; cursor: pointer; transition: background-color 0.2s ease-in-out; }
        input[type="file"]:hover::file-selector-button { background-color: #bae6fd; }
        .dark input[type="file"]::file-selector-button { background-color: #334155; color: #7dd3fc; }
        .dark input[type="file"]:hover::file-selector-button { background-color: #475569; }
        #toast-notification { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 100; opacity: 0; transition: opacity 0.5s ease-in-out, transform 0.5s ease-in-out; transform: translateY(100%); }
        #toast-notification.show { opacity: 1; transform: translateY(0); }
        .input-style { /* Applied via JS */ }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 dark:bg-slate-900 dark:text-gray-300 transition-colors duration-300">

    <canvas id="digital-particles"></canvas>

    <div class="flex flex-col min-h-screen">

        <header class="sticky top-0 z-50 bg-white/80 dark:bg-slate-800/80 backdrop-blur-sm shadow-md dark:shadow-lg border-b border-gray-200 dark:border-transparent">
             <nav class="container mx-auto px-4 py-3 flex justify-between items-center">
                 <div class="flex items-center gap-6">
                    <a href="<?= base_url('dashboard.php') ?>" class="flex items-center gap-2">
                         <img src="<?= e($settings['site_logo'] ? base_url($settings['site_logo']) : base_url('assets/img/logo_128x128.png')) ?>" alt="Logo" class="h-10 w-auto">
                        <span class="text-xl font-bold text-gray-900 dark:text-white hidden sm:block"><?= e($appName) ?></span>
                    </a>
                    <div class="hidden md:flex items-center gap-4">
                        <a href="<?= base_url('dashboard.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('dashboard') ?></a>
                        <a href="<?= base_url('tickets.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('tickets') ?></a>
                        <a href="<?= base_url('kb.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('knowledge_base') ?></a>
                        <a href="<?= base_url('reports.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('reports') ?></a>
                        <?php if ($currentUser['role'] === 'admin'): ?>
                        <a href="<?= base_url('admin/users.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('users') ?></a>
                        <a href="<?= base_url('admin/settings.php') ?>" class="text-white font-semibold bg-blue-600 px-3 py-1.5 rounded-lg text-sm"><?= __t('settings') ?></a>
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
                <a href="<?= base_url('admin/users.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('users') ?></a>
                <a href="<?= base_url('admin/settings.php') ?>" class="block text-white font-semibold bg-blue-600 px-3 py-2 rounded-lg text-sm"><?= __t('settings') ?></a>
                <?php endif; ?>
            </div>
        </header>

        <main class="flex-grow container mx-auto px-4 py-8 mt-8">

            <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 md:p-8 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 max-w-4xl mx-auto">

                 <h1 class="text-2xl font-semibold text-gray-800 dark:text-white mb-6 border-b border-gray-200 dark:border-slate-700 pb-4"><?= __t('settings') ?></h1>

                <?php /* Flash message is now handled by Toast JS */ ?>

                 <form method="post" enctype="multipart/form-data" class="space-y-8 mb-8" id="brandingForm">
                     <?php csrf_field(); ?>
                     <section>
                        <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-4">Branding</h2>
                         <div class="space-y-4">
                            <div>
                                <label for="site_logo" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('Site logo') ?></label>
                                <?php if(!empty($settings['site_logo'])): ?>
                                <div class="flex items-center gap-4 mb-2 p-2 bg-gray-50 dark:bg-slate-700 rounded border border-gray-200 dark:border-slate-600">
                                    <img src="<?= e(base_url($settings['site_logo'])) ?>?t=<?= time() // Add timestamp to prevent caching ?>" alt="Current Logo" class="h-12 w-auto max-w-[100px] object-contain bg-white dark:bg-gray-200 p-1 rounded">
                                    <span class="text-xs text-gray-500 dark:text-gray-400 break-all"><?= e($settings['site_logo']) ?></span>
                                </div>
                                <?php endif; ?>
                                <input type="file" id="site_logo" name="site_logo" accept=".png,.jpg,.jpeg,.gif,.webp,.svg"
                                       class="w-full text-sm text-gray-500 dark:text-gray-400 cursor-pointer bg-gray-50 dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-1 focus:ring-blue-500">
                                 <p class="mt-1 text-xs text-gray-500 dark:text-gray-400"><?= __t('logo_upload_note') ?></p>
                            </div>
                         </div>
                         <div class="flex justify-end pt-6 border-t border-gray-200 dark:border-slate-700 mt-6">
                            <button type="submit" name="save_branding" value="1" class="px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                                <?= __t('save branding') // Add translation for "Save Branding" ?>
                            </button>
                        </div>
                    </section>
                </form>
                 <form method="post" class="space-y-8" id="mainSettingsForm">
                    <?php csrf_field(); ?>

                    <section>
                        <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-4"><?= __t('general_settings') ?></h2>
                        <div class="space-y-4">
                            <div>
                                <label for="site_name_en" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Site Title (English)</label>
                                <input type="text" id="site_name_en" name="site_name_en" value="<?= e($settings['site_name_en']) ?>" class="w-full input-style">
                            </div>
                             <div>
                                <label for="site_name_km" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Site Title (Khmer)</label>
                                <input type="text" id="site_name_km" name="site_name_km" value="<?= e($settings['site_name_km']) ?>" class="w-full input-style">
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="default_lang" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('default_language') ?></label>
                                    <select id="default_lang" name="default_lang" class="w-full input-style">
                                        <option value="km" <?= $settings['default_lang']==='km'?'selected':'' ?>><?= __t('khmer') ?></option>
                                        <option value="en" <?= $settings['default_lang']==='en'?'selected':'' ?>><?= __t('english') ?></option>
                                    </select>
                                </div>
                                <div>
                                    <label for="default_theme" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('default_theme') ?></label>
                                    <select id="default_theme" name="default_theme" class="w-full input-style">
                                        <option value="system" <?= $settings['default_theme']==='system'?'selected':'' ?>><?= __t('system_preference') ?></option>
                                        <option value="light" <?= $settings['default_theme']==='light'?'selected':'' ?>><?= __t('light') ?></option>
                                        <option value="dark" <?= $settings['default_theme']==='dark'?'selected':'' ?>><?= __t('dark') ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="border-t border-gray-200 dark:border-slate-700 pt-6">
                        <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-4"><?= __t('smtp_settings') ?></h2>
                        <div class="space-y-4">
                             <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="smtp_host" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('smtp host') ?></label>
                                    <input type="text" id="smtp_host" name="smtp_host" value="<?= e($settings['smtp_host']) ?>" placeholder="e.g., smtp.gmail.com" class="w-full input-style">
                                </div>
                                <div>
                                    <label for="smtp_port" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('smtp port') ?></label>
                                    <input type="number" id="smtp_port" name="smtp_port" value="<?= e($settings['smtp_port']) ?>" placeholder="e.g., 587" class="w-full input-style">
                                </div>
                             </div>
                             <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="smtp_user" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('smtp user') ?></label>
                                    <input type="text" id="smtp_user" name="smtp_user" value="<?= e($settings['smtp_user']) ?>" placeholder="<?= __t('email address') ?>" class="w-full input-style">
                                </div>
                                <div>
                                    <label for="smtp_pass" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('smtp_pass') ?></label>
                                    <input type="password" id="smtp_pass" name="smtp_pass" value="" placeholder="<?= __t('leave blank to keep') ?>" class="w-full input-style">
                                </div>
                             </div>
                            <div>
                                <label for="smtp_encryption" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('smtp encryption') ?></label>
                                <select id="smtp_encryption" name="smtp_encryption" class="w-full input-style">
                                    <option value="tls" <?= $settings['smtp_encryption']==='tls'?'selected':'' ?>>TLS</option>
                                    <option value="ssl" <?= $settings['smtp_encryption']==='ssl'?'selected':'' ?>>SSL</option>
                                    <option value="" <?= $settings['smtp_encryption']===''?'selected':'' ?>><?= __t('none') ?></option>
                                </select>
                            </div>
                        </div>
                    </section>

                    <section class="border-t border-gray-200 dark:border-slate-700 pt-6">
                        <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-4"><?= __t('sms settings') ?> (Twilio)</h2>
                         <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                             <div>
                                <label for="twilio_sid" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('twilio sid') ?></label>
                                <input type="text" id="twilio_sid" name="twilio_sid" value="<?= e($settings['twilio_sid']) ?>" class="w-full input-style">
                            </div>
                            <div>
                                <label for="twilio_token" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('twilio token') ?></label>
                                <input type="password" id="twilio_token" name="twilio_token" value="" placeholder="<?= __t('leave blank to keep') ?>" class="w-full input-style">
                            </div>
                            <div>
                                <label for="twilio_from" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('twilio from') ?></label>
                                <input type="text" id="twilio_from" name="twilio_from" value="<?= e($settings['twilio_from']) ?>" placeholder="<?= __t('twilio phone number') ?>" class="w-full input-style">
                            </div>
                         </div>
                    </section>

                    <section class="border-t border-gray-200 dark:border-slate-700 pt-6">
                        <h2 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-4"><?= __t('SLA Policy Hours') ?></h2>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <label for="sla_low" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('low') ?></label>
                                <input type="number" id="sla_low" name="sla_low" value="<?= e($settings['sla_low']) ?>" min="1" class="w-full input-style">
                            </div>
                             <div>
                                <label for="sla_normal" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('normal') ?></label>
                                <input type="number" id="sla_normal" name="sla_normal" value="<?= e($settings['sla_normal']) ?>" min="1" class="w-full input-style">
                            </div>
                             <div>
                                <label for="sla_high" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('high') ?></label>
                                <input type="number" id="sla_high" name="sla_high" value="<?= e($settings['sla_high']) ?>" min="1" class="w-full input-style">
                            </div>
                             <div>
                                <label for="sla_urgent" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('urgent') ?></label>
                                <input type="number" id="sla_urgent" name="sla_urgent" value="<?= e($settings['sla_urgent']) ?>" min="1" class="w-full input-style">
                            </div>
                        </div>
                    </section>

                    <div class="flex justify-end pt-6 border-t border-gray-200 dark:border-slate-700">
                        <button type="submit" name="save_settings" value="1" class="px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                            <?= __t('save settings') ?>
                        </button>
                    </div>
                </form>
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


    <script> /* Digital Particle Script */
        const canvas = document.getElementById('digital-particles'); const ctx = canvas.getContext('2d'); canvas.width = window.innerWidth; canvas.height = window.innerHeight; let particlesArray = []; const numberOfParticles = (canvas.width * canvas.height) / 9000; const particleColorDark = 'rgba(255, 255, 255, 0.4)'; const particleColorLight = 'rgba(0, 0, 0, 0.2)'; const lineColorDark = 'rgba(100, 180, 255, 0.15)'; const lineColorLight = 'rgba(0, 100, 255, 0.15)'; function getParticleColors() { const isDarkMode = document.documentElement.classList.contains('dark'); return { particle: isDarkMode ? particleColorDark : particleColorLight, line: isDarkMode ? lineColorDark : lineColorLight }; } class Particle { constructor(x, y, dX, dY, s) { this.x=x;this.y=y;this.directionX=dX;this.directionY=dY;this.size=s;} draw() { ctx.beginPath(); ctx.arc(this.x,this.y,this.size,0,Math.PI*2,false); ctx.fillStyle=getParticleColors().particle; ctx.fill(); } update() { if(this.x>canvas.width||this.x<0)this.directionX=-this.directionX; if(this.y>canvas.height||this.y<0)this.directionY=-this.directionY; this.x+=this.directionX; this.y+=this.directionY; this.draw();}} function init() { particlesArray=[]; for(let i=0;i<numberOfParticles;i++) { let s=Math.random()*2+1,x=Math.random()*canvas.width,y=Math.random()*canvas.height,dX=(Math.random()*0.4)-0.2,dY=(Math.random()*0.4)-0.2; particlesArray.push(new Particle(x,y,dX,dY,s));}} function connect() { let opVal=1; const curLineColor=getParticleColors().line; for(let a=0;a<particlesArray.length;a++) { for(let b=a+1;b<particlesArray.length;b++) { let dist=Math.sqrt((particlesArray[a].x-particlesArray[b].x)**2 + (particlesArray[a].y-particlesArray[b].y)**2); if(dist<120) { opVal=1-(dist/120); ctx.strokeStyle=curLineColor.replace('0.15',opVal); ctx.lineWidth=0.5; ctx.beginPath(); ctx.moveTo(particlesArray[a].x,particlesArray[a].y); ctx.lineTo(particlesArray[b].x,particlesArray[b].y); ctx.stroke();}}}} function animate() { ctx.clearRect(0,0,canvas.width,canvas.height); particlesArray.forEach(p => p.update()); connect(); requestAnimationFrame(animate);} window.addEventListener('resize', () => { canvas.width=window.innerWidth; canvas.height=window.innerHeight; init();}); init(); animate(); </script>
    <script> /* Dropdown & Theme Toggle Script */
        function toggleDropdown(id) { document.getElementById(id).classList.toggle('hidden'); }
        window.addEventListener('click', function(e) { 
            const pM=document.getElementById('profile-dropdown-menu'); 
            if(pM&&!pM.contains(e.target))document.getElementById('profile-dropdown').classList.add('hidden'); 
            
            // ***** FIX 7: Added Notification click-outside logic *****
            const notifMenu = document.getElementById('notification-dropdown-menu');
            if (notifMenu && !notifMenu.contains(e.target)) {
                document.getElementById('notification-dropdown').classList.add('hidden');
            }
            
            const mN=document.getElementById('mobile-menu'), mB=document.querySelector('button[onclick*="mobile-menu"]'); 
            if(mN&&!mN.contains(e.target)&&!mB.contains(e.target))mN.classList.add('hidden');
        });
        const themeToggle=document.getElementById('theme-toggle'), sunIcon=document.getElementById('theme-icon-sun'), moonIcon=document.getElementById('theme-icon-moon');
        function updateThemeIcon() {
            const isDark = document.documentElement.classList.contains('dark');
            if(isDark) { sunIcon.classList.remove('hidden'); moonIcon.classList.add('hidden'); }
            else { sunIcon.classList.add('hidden'); moonIcon.classList.remove('hidden'); }
        } updateThemeIcon();
        themeToggle.addEventListener('click', () => {
            document.documentElement.classList.toggle('dark');
            const isDark = document.documentElement.classList.contains('dark');
            const currentThemeSetting = document.getElementById('default_theme')?.value || 'system';
             if (currentThemeSetting !== 'system') { localStorage.theme = isDark ? 'dark' : 'light'; }
             else { localStorage.theme = isDark ? 'dark' : 'light'; if (document.getElementById('default_theme')) { document.getElementById('default_theme').value = isDark ? 'dark' : 'light'; } }
            updateThemeIcon();
        });
        const themeSelect = document.getElementById('default_theme');
        if (themeSelect) {
            themeSelect.addEventListener('change', (event) => {
                 const selectedTheme = event.target.value;
                 localStorage.theme = selectedTheme;
                 if (selectedTheme === 'dark' || (selectedTheme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) { document.documentElement.classList.add('dark'); }
                 else { document.documentElement.classList.remove('dark'); }
                 updateThemeIcon();
            });
        }

        // Toast Notification Script
        const toastElement = document.getElementById('toast-notification'); const toastMessage = document.getElementById('toast-message'); const toastIcon = document.getElementById('toast-icon'); const toastCloseButton = toastElement?.querySelector('[data-dismiss-target]'); let toastTimeout;
        function showToast(message, type = 'success') { if (!toastElement || !toastMessage || !toastIcon) return; clearTimeout(toastTimeout); toastMessage.textContent = message; toastIcon.className = 'inline-flex items-center justify-center flex-shrink-0 w-8 h-8 rounded-lg'; if (type === 'success') { toastIcon.classList.add('text-green-500', 'bg-green-100', 'dark:bg-green-800', 'dark:text-green-200'); toastIcon.innerHTML = '<svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20"><path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 8.207-4 4a1 1 0 0 1-1.414 0l-2-2a1 1 0 0 1 1.414-1.414L9 10.586l3.293-3.293a1 1 0 0 1 1.414 1.414Z"/></svg>'; } else if (type === 'error') { toastIcon.classList.add('text-red-500', 'bg-red-100', 'dark:bg-red-800', 'dark:text-red-200'); toastIcon.innerHTML = '<svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20"><path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 11.793a1 1 0 1 1-1.414 1.414L10 11.414l-2.293 2.293a1 1 0 0 1-1.414-1.414L8.586 10 6.293 7.707a1 1 0 0 1 1.414-1.414L10 8.586l2.293-2.293a1 1 0 0 1 1.414 1.414L11.414 10l2.293 2.293Z"/></svg>'; } else { toastIcon.classList.add('text-blue-500', 'bg-blue-100', 'dark:bg-blue-800', 'dark:text-blue-200'); toastIcon.innerHTML = '<svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20"><path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5ZM9.5 4a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM12 15H8a1 1 0 0 1 0-2h1v-3H8a1 1 0 0 1 0-2h2a1 1 0 0 1 1 1v4h1a1 1 0 0 1 0 2Z"/></svg>'; } toastElement.classList.remove('hidden'); toastElement.offsetHeight; toastElement.classList.add('show'); toastTimeout = setTimeout(() => { hideToast(); }, 5000); }
        function hideToast() { if (!toastElement) return; toastElement.classList.remove('show'); clearTimeout(toastTimeout); setTimeout(() => { toastElement.classList.add('hidden'); }, 500); }
        if (toastCloseButton) { toastCloseButton.addEventListener('click', hideToast); }
        <?php if($notice): ?> showToast('<?= e($notice) ?>', '<?= e($notice_type) ?>'); <?php endif; ?>
         document.addEventListener('DOMContentLoaded', () => { document.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"], input[type="number"], input[type="password"], select, textarea').forEach(el => { el.classList.add('input-style', 'px-3', 'py-2', 'border', 'border-gray-300', 'rounded-lg', 'dark:bg-slate-700', 'dark:border-slate-600', 'focus:outline-none', 'focus:ring-2', 'focus:ring-blue-500', 'dark:text-gray-200', 'text-sm'); }); document.querySelectorAll('label').forEach(el => { if (!el.classList.contains('sr-only')) { el.classList.add('block', 'text-sm', 'font-medium', 'text-gray-700', 'dark:text-gray-300', 'mb-1'); } }); });
    </script>
    
    <script>
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
                    notificationsMarked = false; // Allow retry on failure
                });
            } else {
                console.error('jQuery not loaded. Cannot mark notifications as read.');
                notificationsMarked = false; // Allow retry
            }
        }
    }
    </script>

</body>
</html>