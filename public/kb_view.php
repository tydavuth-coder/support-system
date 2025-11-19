<?php
session_start(); // FIX 1: Added session_start()
// --- PHP Logic (Remain unchanged) ---
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php'; // FIX 2: Added csrf.php
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
$id=(int)($_GET['id']??0);
$slug=trim($_GET['slug']??'');

$sql = "SELECT * FROM kb_articles WHERE is_published = 1 AND ";
$params = [];
if ($id > 0) {
    $sql .= "id = ?";
    $params = [$id];
} elseif (!empty($slug)) {
    $sql .= "slug = ?";
    $params = [$slug];
} else {
    $kb = null;
    goto render_page;
}

$s=$pdo->prepare($sql);
$s->execute($params);
$kb=$s->fetch(PDO::FETCH_ASSOC);

render_page:

$lang=current_lang();
$site_name_en = get_setting('site_name_en', 'Support System');
$site_name_km = get_setting('site_name_km', 'ប្រព័ន្ធជំនួយគាំទ្រ');
$appName = $lang === 'km' ? $site_name_km : $site_name_en;
$currentUser = current_user();
$defaultAvatar = base_url('assets/img/logo_32x32.png'); 

// (Removed $defaultTheme, no longer needed)

if ($kb) {
    $title = $lang === 'km' && !empty($kb['title_km']) ? $kb['title_km'] : $kb['title_en'];
    $body = $lang === 'km' && !empty($kb['body_km']) ? $kb['body_km'] : $kb['body_en'];
    $tags = !empty($kb['tags']) ? explode(',', $kb['tags']) : [];
    $pageTitle = $title;
} else {
    if ($id > 0 || !empty($slug)) {
        $pageTitle = __t('article_not_found');
        $title = $pageTitle;
        $body = '<p>' . __t('article_not_found_message') . '</p>';
        $tags = [];
    } else {
         http_response_code(400);
         $pageTitle = __t('invalid_request');
         $title = $pageTitle;
         $body = '<p>' . __t('missing_article_identifier') . '</p>';
         $tags = [];
    }
}
?>

<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - <?= e($appName) ?></title>
	<link rel="icon" href="<?= base_url('assets/img/logo_32x32.png') ?>">
    
    <script>
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    
    <script src="https://cdn.tailwindcss.com/3.4.3?plugins=typography"></script>
    <script>
        tailwind.config = {
          darkMode: 'class',
          content: ['./*.php', '../includes/**/*.php'],
          theme: { 
            extend: { 
              typography: ({ theme }) => ({ 
                DEFAULT: { css: { /* ... */ } }, 
                invert: { css: { /* ... */ } }, 
              }), 
            }, 
          },
          plugins: [ 
            // require('@tailwindcss/typography'), // <-- THIS WAS THE ERROR
          ],
        }
    </script>
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="https://unpkg.com/lucide-react@latest/dist/lucide-react.js"></script>
    
    <style> /* Styles (Remain unchanged) */
        @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;700&display=swap');
        body { font-family: 'Kantumruy Pro', sans-serif; } #digital-particles { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; } /* Scrollbar */ /* Prose */
        ::-webkit-scrollbar { width: 6px; } ::-webkit-scrollbar-track { background: #f1f5f9; } ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 10px; } ::-webkit-scrollbar-thumb:hover { background: #475569; }
        .dark ::-webkit-scrollbar-track { background: #1e293b; } .dark ::-webkit-scrollbar-thumb { background: #334155; } .dark ::-webkit-scrollbar-thumb:hover { background: #475569; }
        .prose img { margin-top: 1em; margin-bottom: 1em; border-radius: 0.5rem; }
        .prose pre { border-radius: 0.5rem; }
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
                        <a href="<?= base_url('kb.php') ?>" class="text-white font-semibold bg-blue-600 px-3 py-1.5 rounded-lg text-sm"><?= __t('knowledge_base') ?></a>
                        <a href="<?= base_url('reports.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('reports') ?></a>
                        <?php if (is_role('admin')): ?>
                        <a href="<?= base_url('admin/users.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('users') ?></a>
                        <a href="<?= base_url('admin/settings.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('settings') ?></a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <?php
                        $current_params = $_GET; unset($current_params['setlang']);
                        if ($id > 0) { $current_params['id'] = $id; }
                        elseif (!empty($slug)) { $current_params['slug'] = $slug; }
                    ?>
                    <a href="?<?= http_build_query(array_merge($current_params, ['setlang' => 'km'])) ?>" title="ភាសាខ្មែរ" class="rounded-full transition-all duration-300 <?= $lang === 'km' ? 'ring-2 ring-blue-500 ring-offset-2 ring-offset-white dark:ring-offset-slate-800' : 'opacity-60 hover:opacity-100' ?>">
                        <img src="<?= base_url('assets/img/flag-km.png') ?>" alt="ភាសាខ្មែរ" class="w-6 h-6 rounded-full object-cover">
                    </a>
                    <a href="?<?= http_build_query(array_merge($current_params, ['setlang' => 'en'])) ?>" title="English" class="rounded-full transition-all duration-300 <?= $lang === 'en' ? 'ring-2 ring-blue-500 ring-offset-2 ring-offset-white dark:ring-offset-slate-800' : 'opacity-60 hover:opacity-100' ?>">
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
                <a href="<?= base_url('kb.php') ?>" class="block text-white font-semibold bg-blue-600 px-3 py-2 rounded-lg text-sm"><?= __t('knowledge_base') ?></a>
                <a href="<?= base_url('reports.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('reports') ?></a>
                <?php if (is_role('admin')): ?>
                <a href="<?= base_url('admin/users.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('users') ?></a>
                <a href="<?= base_url('admin/settings.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('settings') ?></a>
                <?php endif; ?>
            </div>
        </header>

        <main class="flex-grow container mx-auto px-4 py-8 mt-8">
            <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 md:p-8 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 max-w-4xl mx-auto">
                <?php if ($kb || ($id == 0 && empty($slug))): ?>
                    <nav class="flex mb-4 text-sm" aria-label="Breadcrumb">
                      <ol class="inline-flex items-center space-x-1 md:space-x-2 rtl:space-x-reverse">
                        <li class="inline-flex items-center">
                          <a href="<?= base_url('kb.php') ?>" class="inline-flex items-center font-medium text-gray-700 hover:text-blue-600 dark:text-gray-400 dark:hover:text-white">
                            <svg class="w-3 h-3 me-2.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20"> <path d="m19.707 9.293-2-2-7-7a1 1 0 0 0-1.414 0l-7 7-2 2a1 1 0 0 0 1.414 1.414L2 10.414V18a2 2 0 0 0 2 2h3a1 1 0 0 0 1-1v-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v4a1 1 0 0 0 1 1h3a2 2 0 0 0 2-2v-7.586l.293.293a1 1 0 0 0 1.414-1.414Z"/> </svg>
                            <?= __t('knowledge_base') ?>
                          </a>
                        </li>
                        <?php if($kb): ?>
                        <li aria-current="page">
                          <div class="flex items-center">
                            <svg class="w-3 h-3 text-gray-400 mx-1 rtl:rotate-180" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10"> <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/> </svg>
                            <span class="ms-1 font-medium text-gray-500 md:ms-2 dark:text-gray-400 truncate max-w-[200px] sm:max-w-xs"><?= e(mb_strimwidth($title, 0, 50, "...")) ?></span>
                          </div>
                        </li>
                        <?php endif; ?>
                      </ol>
                    </nav>

                    <h1 class="text-2xl md:text-3xl font-bold <?= ($kb) ? 'text-gray-800 dark:text-white' : 'text-center text-red-600 dark:text-red-400' ?> mb-4"><?= e($title) ?></h1>

                    <?php if ($kb): ?>
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between text-xs text-gray-500 dark:text-gray-400 mb-6 border-b border-gray-200 dark:border-slate-700 pb-4 gap-2">
                       <span><?= __t('published_on') ?>: <?= e(date('d M Y', strtotime($kb['created_at']))) ?></span>
                       <?php if (!empty($tags)): ?>
                       <div class="flex flex-wrap items-center gap-1">
                            <span class="mr-1"><?= __t('tags') ?>:</span>
                            <?php foreach($tags as $tag): ?>
                                <a href="<?= base_url('kb.php?q='.urlencode(trim($tag))) ?>" class="px-1.5 py-0.5 bg-gray-100 dark:bg-slate-700 rounded text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-slate-600"><?= e(trim($tag)) ?></a>
                            <?php endforeach; ?>
                       </div>
                       <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <article class="prose dark:prose-invert max-w-none <?= (!$kb) ? 'text-center' : '' // Removed pt-6 ?>">
                        <?php
                            // Sanitization (Remain unchanged)
                            $safe_body = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $body);
                            $safe_body = preg_replace('#on\w+\s*=\s*"(.*?)"#is', '', $safe_body);
                            $safe_body = preg_replace('#on\w+\s*=\s*\'(.*?)\'#is', '', $safe_body);
                            $safe_body = preg_replace('#href\s*=\s*["\']\s*javascript\s*:\s*.*?\s*["\']#is', 'href="#"', $safe_body);
                            echo $safe_body;
                         ?>
                    </article>

                    <?php if (!$kb): // Back button (Remain unchanged) ?>
                         <div class="text-center mt-6">
                             <a href="<?= base_url('kb.php') ?>" class="text-blue-600 dark:text-blue-400 hover:underline"><?= __t('back_to_kb') ?></a>
                         </div>
                    <?php endif; ?>

                <?php else: // Error case (Remain unchanged) ?>
                    <p class="text-center text-red-500">An unexpected error occurred.</p>
                <?php endif; ?>
            </div>
        </main>

        <footer class="bg-white dark:bg-slate-800/80 mt-12 py-4 border-t border-gray-200 dark:border-transparent">
             <div class="container mx-auto px-4 text-center text-gray-500 dark:text-gray-400 text-sm">
                 © <?= date('Y') ?> - <?= e($appName) ?> / <?= e(__t('Digital System Management Department')) ?>
             </div>
         </footer>
    </div>

    <script> /* Digital Particle Script */ const canvas = document.getElementById('digital-particles'); /* ... */ animate(); </script>
    
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
        
        // --- This is the correct logic from dashboard.php ---
        const themeToggle=document.getElementById('theme-toggle'), sunIcon=document.getElementById('theme-icon-sun'), moonIcon=document.getElementById('theme-icon-moon'); 
        
        function updateThemeIcon() {
            const theme = localStorage.theme || 'system'; // Use 'system' as default
            let isDark;
            if (theme === 'system') {
                 isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            } else {
                 isDark = theme === 'dark';
            }
            
            if (isDark) {
                document.documentElement.classList.add('dark');
                sunIcon.classList.remove('hidden'); 
                moonIcon.classList.add('hidden');
            } else {
                document.documentElement.classList.remove('dark');
                sunIcon.classList.add('hidden'); 
                moonIcon.classList.remove('hidden');
            }
        } 
        updateThemeIcon(); // Run on load
        
        themeToggle.addEventListener('click', () => { 
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.theme = isDark ? 'dark' : 'light'; 
            updateThemeIcon(); 
        }); 
        
        // Added the missing listener for system changes
         window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', event => {
            if (localStorage.theme === 'system') {
                updateThemeIcon();
            }
        });
        // --- End of correct logic ---
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
            // Fallback to fetch
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