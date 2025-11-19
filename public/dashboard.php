<?php 
session_start(); // FIX: Must be at the top
// --- PHP Logic (Updated with Role Card, Status Chart, and Activity Feed & Notifications) ---
require_once __DIR__ . '/../includes/functions.php'; 
require_once __DIR__ . '/../includes/auth.php'; 
require_once __DIR__ . '/../includes/csrf.php'; 
require_login(); 

$u = current_user(); // Get current user info

// 1. Stat Cards (Original 3)
$totalNew = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status='received'")->fetchColumn();
$totalOpen = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('received','in_progress')")->fetchColumn();
$totalClosed = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status='completed'")->fetchColumn();

// 2. ***** NEW: Role-Based Card Logic *****
$role_card_count = 0;
$role_card_title = __t('my_open_tickets'); // Default for User
$role_card_icon = 'folder-open'; // Default for User
$role_card_link = 'tickets.php?status=open&staff=' . $u['id']; // Default link for User

if (is_role('coordinator')) { // Admin or Coordinator
    $role_card_title = __t('unassigned_tickets');
    $role_card_icon = 'help-circle'; // Icon for unassigned
    $role_card_count = $pdo->query("SELECT COUNT(*) FROM tickets WHERE assigned_to IS NULL AND status IN ('received', 'in_progress')")->fetchColumn();
    $role_card_link = 'tickets.php?status=open&assigned=none'; // Link to filter unassigned
} elseif ($u['role'] === 'technical') {
    $role_card_title = __t('My assigned tickets');
    $role_card_icon = 'user-check'; // Icon for assigned
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE assigned_to = ? AND status IN ('received', 'in_progress')");
    $stmt->execute([$u['id']]);
    $role_card_count = $stmt->fetchColumn();
    $role_card_link = 'tickets.php?status=open&staff=' . $u['id'];
} else { // Regular User
    // Use default title/icon
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE user_id = ? AND status IN ('received', 'in_progress')");
    $stmt->execute([$u['id']]);
    $role_card_count = $stmt->fetchColumn();
    // Link is already correct
}
// ***** END NEW Role-Based Card Logic *****


// 3. Line Chart Data (Tickets / Day)
$days = []; 
$dataCounts = []; 
for($i = 13; $i >= 0; $i--) { 
    $d = (new DateTime())->modify("-$i day")->format('Y-m-d'); 
    $days[] = (new DateTime())->modify("-$i day")->format('M d'); 
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE DATE(created_at)=?"); 
    $stmt->execute([$d]); 
    $dataCounts[] = (int)$stmt->fetchColumn(); 
}

// 4. ***** NEW: Doughnut Chart Data (Tickets by Status) *****
$statusDist = $pdo->query("SELECT status, COUNT(*) as count 
                           FROM tickets 
                           GROUP BY status 
                           ORDER BY FIELD(status, 'received', 'in_progress', 'completed', 'rejected')")
                   ->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = array_map(function($item) { return __t($item['status']); }, $statusDist);
$statusData = array_column($statusDist, 'count');
// Define consistent colors for status
$statusColors = [
    'received' => 'rgba(59, 130, 246, 0.7)',  // blue-500
    'in_progress' => 'rgba(245, 158, 11, 0.7)', // yellow-500
    'completed' => 'rgba(34, 197, 94, 0.7)', // green-500
    'rejected' => 'rgba(239, 68, 68, 0.7)'   // red-500
];
$statusChartColors = array_map(function($item) use ($statusColors) {
    return $statusColors[$item['status']] ?? '#9ca3af'; // Default gray
}, $statusDist);
// ***** END NEW Doughnut Chart Data *****

// 5. ***** FIX: Optimized Activity Query (Avoids N+1) *****
$activityStmt = $pdo->query("
    SELECT 
        a.*, 
        u.name AS actor_name, 
        u.avatar AS actor_avatar, 
        t.title AS ticket_title, 
        t.id AS ticket_id_num,
        assignee.name AS assignee_name 
    FROM ticket_activity a 
    LEFT JOIN users u ON u.id = a.created_by 
    LEFT JOIN tickets t ON t.id = a.ticket_id 
    LEFT JOIN users assignee ON assignee.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.meta, '$.assigned_to')) AS UNSIGNED)
    ORDER BY a.created_at DESC 
    LIMIT 7
"); 
$recent_activity = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
// ***** END FIX *****


// 6. ***** NEW: Notification Logic *****
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$notifStmt->execute([$u['id']]);
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

$unreadCountStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadCountStmt->execute([$u['id']]);
$unreadCount = (int)$unreadCountStmt->fetchColumn();
// ***** END Notification Logic *****

$lang = current_lang();
$appName = $lang === 'km' ? $config['app']['name_km'] : $config['app']['name_en'];
$currentUser = current_user(); 
$defaultAvatar = base_url('assets/img/logo.png');
?>

<!DOCTYPE html>
<html lang="<?= e($lang) ?>"> 
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appName) ?> - <?= __t('dashboard') ?></title>
	
    <link rel="icon" href="<?= base_url('assets/img/logo_32x32.png') ?>">
    
    <script>
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
          darkMode: 'class',
        }
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script src="https://unpkg.com/lucide-react@latest/dist/lucide-react.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;700&display=swap');
        body { 
            font-family: 'Kantumruy Pro', 'Helvetica Neue', Arial, sans-serif;
        }
        #digital-particles {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; 
        }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; } 
        ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }
        .dark ::-webkit-scrollbar-track { background: #1e293b; }
        .dark ::-webkit-scrollbar-thumb { background: #334155; }
        .dark ::-webkit-scrollbar-thumb:hover { background: #475569; }
        .fade-in-up { animation: fadeInUp 0.5s ease-out forwards; opacity: 0; }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        /* Timeline Style */
        .timeline { position: relative; padding-left: 1rem; } 
        .timeline::before { content: ''; position: absolute; left: 14px; top: 0; bottom: 0; width: 2px; background-color: #cbd5e1; }
        .dark .timeline::before { background-color: #475569; }
        .timeline-item { position: relative; margin-bottom: 1.25rem; }
        .timeline-item .timeline-avatar { position: absolute; left: 0; top: 0; width: 28px; height: 28px; border-radius: 50%; object-fit: cover; background-color: #e2e8f0; border: 2px solid #fff; }
        .dark .timeline-item .timeline-avatar { background-color: #475569; border-color: #1e293b; }
        .timeline-item-content { margin-left: 44px; }
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
                        <a href="<?= base_url('dashboard.php') ?>" class="text-white font-semibold bg-blue-600 px-3 py-1.5 rounded-lg text-sm"><?= __t('dashboard') ?></a>
                        <a href="<?= base_url('tickets.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('tickets') ?></a>
                        <a href="<?= base_url('kb.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('knowledge_base') ?></a>
                        <a href="<?= base_url('reports.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('reports') ?></a>
                        <?php if (is_role('admin')): // Check using is_role() for flexibility ?>
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
                        <svg id="theme-icon-sun" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                        <svg id="theme-icon-moon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
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
                            <svg class="w-4 h-4 text-gray-400 hidden md:inline" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div id="profile-dropdown" class="hidden absolute right-0 mt-2 w-48 bg-white dark:bg-slate-700 dark:text-gray-200 rounded-lg shadow-xl py-1 z-50">
                            <a href="<?= base_url('profile.php') ?>" class="block px-4 py-2 text-sm hover:bg-gray-100 dark:hover:bg-slate-600"><?= __t('profile') ?></a>
                            <a href="<?= base_url('logout.php') ?>" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100 dark:hover:bg-slate-600"><?= __t('logout') ?></a>
                        </div>
                    </div>
                </div>
                <button class="md:hidden text-gray-800 dark:text-white" onclick="toggleDropdown('mobile-menu')">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path></svg>
                </button>
            </nav>
            <div id="mobile-menu" class="hidden md:hidden bg-white dark:bg-slate-700 px-4 pt-2 pb-4 space-y-2">
                <a href="<?= base_url('dashboard.php') ?>" class="block text-white font-semibold bg-blue-600 px-3 py-2 rounded-lg text-sm"><?= __t('dashboard') ?></a>
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
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 fade-in-up" style="animation-delay: 100ms;">
                    <div class="flex items-center justify-between">
                        <h5 class="text-lg font-semibold text-gray-600 dark:text-gray-300"><?= __t('tickets') ?> - <?= __t('received') ?></h5>
                        <div class="p-2 bg-blue-600/20 rounded-lg">
                            <svg class="w-6 h-6 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.144-6.126A1.76 1.76 0 015.86 9.7a1.76 1.76 0 011.956-.434l2.144 1.286A1.76 1.76 0 0011 12.171V5.882z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12.153 8.243l4.898-2.938a1.76 1.76 0 012.522 1.616v10.169a1.76 1.76 0 01-2.522 1.616l-4.898-2.938a1.76 1.76 0 010-2.938z"></path></svg>
                        </div>
                    </div>
                    <div class="text-5xl font-bold text-gray-900 dark:text-white mt-4 stat-counter" data-target="<?= (int)$totalNew ?>">0</div>
                </div>
                
                <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 fade-in-up" style="animation-delay: 200ms;">
                    <div class="flex items-center justify-between">
                        <h5 class="text-lg font-semibold text-gray-600 dark:text-gray-300"><?= __t('tickets') ?> - <?= __t('in_progress') ?></h5>
                        <div class="p-2 bg-yellow-600/20 rounded-lg">
                            <svg class="w-6 h-6 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                    </div>
                    <div class="text-5xl font-bold text-gray-900 dark:text-white mt-4 stat-counter" data-target="<?= (int)$totalOpen ?>">0</div>
                </div>

                <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 fade-in-up" style="animation-delay: 300ms;">
                    <div class="flex items-center justify-between">
                        <h5 class="text-lg font-semibold text-gray-600 dark:text-gray-300"><?= __t('tickets') ?> - <?= __t('completed') ?></h5>
                        <div class="p-2 bg-green-600/20 rounded-lg">
                            <svg class="w-6 h-6 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                    </div>
                    <div class="text-5xl font-bold text-gray-900 dark:text-white mt-4 stat-counter" data-target="<?= (int)$totalClosed ?>">0</div>
                </div>
                
                <a href="<?= e(base_url($role_card_link)) ?>" class="block bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 fade-in-up hover:border-blue-400 dark:hover:border-blue-600 transition-colors" style="animation-delay: 400ms;">
                    <div class="flex items-center justify-between">
                        <h5 class="text-lg font-semibold text-gray-600 dark:text-gray-300"><?= e(__t($role_card_title)) ?></h5>
                        <div class="p-2 bg-purple-600/20 rounded-lg">
                            <svg class="w-6 h-6 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <?php if ($role_card_icon === 'help-circle'): ?>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                <?php elseif ($role_card_icon === 'user-check'): ?>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path><polyline points="19 11 22 14 19 17"></polyline><polyline points="15 11 18 14 15 17"></polyline>
                                <?php else: // folder-open ?>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"></path>
                                <?php endif; ?>
                            </svg>
                        </div>
                    </div>
                    <div class="text-5xl font-bold text-gray-900 dark:text-white mt-4 stat-counter" data-target="<?= (int)$role_card_count ?>">0</div>
                </a>
            </div>


            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mt-8">
                <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 fade-in-up" style="animation-delay: 500ms;">
                    <h5 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-4"><?= __t('reports') ?>: Tickets / Day (14 days)</h5>
                    <div style="height: 300px;">
                        <canvas id="chartTickets"></canvas>
                    </div>
                </div>
                
                <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 fade-in-up" style="animation-delay: 600ms;">
                    <h5 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-4"><?= __t('tickets_by_status') ?></h5>
                    <div style="height: 300px;" class="flex justify-center items-center">
                        <canvas id="chartStatus"></canvas>
                    </div>
                </div>
            </div>


            <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg mt-8 border border-gray-200 dark:border-slate-700/50 fade-in-up" style="animation-delay: 700ms;">
                <h5 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-4 border-b border-gray-200 dark:border-slate-700 pb-3"><?= __t('recent_activity') ?></h5>
                
                <div class="timeline space-y-4">
                    <?php if (empty($recent_activity)): ?>
                        <p class="text-gray-500 dark:text-gray-400 text-sm"><?= __t('no_recent_activity') ?></p>
                    <?php endif; ?>

                    <?php foreach($recent_activity as $a): 
                        $actor_avatar_url = $a['actor_avatar'] ? base_url($a['actor_avatar']) : $defaultAvatar;
                        // Build activity message
                        $activity_message = '';
                        $meta = json_decode($a['meta'], true) ?? [];
                        
                        switch ($a['action']) {
                            case 'created':
                                $activity_message = __t('activity_created_ticket', [
                                    'user' => '<strong>' . e($a['actor_name'] ?: 'System') . '</strong>',
                                    'ticket_id' => '<strong>#' . (int)$a['ticket_id_num'] . '</strong>',
                                    'title' => e(mb_strimwidth($a['ticket_title'] ?? 'N/A', 0, 50, '...'))
                                ]);
                                break;
                            case 'status_changed':
                                $activity_message = __t('activity_status_changed', [
                                    'user' => '<strong>' . e($a['actor_name'] ?: 'System') . '</strong>',
                                    'ticket_id' => '<strong>#' . (int)$a['ticket_id_num'] . '</strong>',
                                    'from' => '<em>' . e(__t($meta['from'] ?? 'N/A')) . '</em>',
                                    'to' => '<em>' . e(__t($meta['to'] ?? 'N/A')) . '</em>'
                                ]);
                                break;
                            case 'assigned':
                                // ***** FIX: Use assignee_name from optimized query (Solves N+1) *****
                                $assigneeName = $a['assignee_name'] ?? 'N/A'; 
                                $activity_message = __t('activity_assigned_ticket', [
                                    'user' => '<strong>' . e($a['actor_name'] ?: 'System') . '</strong>',
                                    'ticket_id' => '<strong>#' . (int)$a['ticket_id_num'] . '</strong>',
                                    'assignee' => '<strong>' . e($assigneeName) . '</strong>'
                                ]);
                                break;
                                // ***** END FIX *****
                            case 'commented':
                                $activity_message = __t('activity_commented_on', [
                                    'user' => '<strong>' . e($a['actor_name'] ?: 'System') . '</strong>',
                                    'ticket_id' => '<strong>#' . (int)$a['ticket_id_num'] . '</strong>'
                                ]);
                                break;
                             case 'feedback_submitted':
                                $activity_message = __t('activity_feedback_submitted', [
                                    'user' => '<strong>' . e($a['actor_name'] ?: 'System') . '</strong>',
                                    'ticket_id' => '<strong>#' . (int)$a['ticket_id_num'] . '</strong>',
                                    'rating' => (int)($meta['rating'] ?? 0)
                                ]);
                                break;
                            default:
                                $activity_message = e($a['actor_name'] ?: 'System') . ' ' . e($a['action']) . ' on ticket #' . (int)$a['ticket_id_num'];
                        }
                    ?>
                    <div class="timeline-item">
                         <img src="<?= e($actor_avatar_url) ?>?t=<?= time() ?>" 
                             onerror="this.onerror=null; this.src='<?= e($defaultAvatar) ?>';"
                             alt="<?= e($a['actor_name'] ?: 'System') ?>" 
                             class="timeline-avatar">
                        <div class="timeline-item-content">
                            <p class="text-sm text-gray-800 dark:text-gray-200">
                                <a href="<?= e(base_url('ticket_view.php?id='.(int)$a['ticket_id_num'])) ?>" class="hover:underline">
                                    <?= $activity_message ?>
                                </a>
                            </p>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                <?= time_ago($a['created_at']) ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div>

        </main>
    
        <footer class="bg-white dark:bg-slate-800/80 mt-12 py-4 border-t border-gray-200 dark:border-transparent">
            <div class="container mx-auto px-4 text-center text-gray-500 dark:text-gray-400 text-sm">
                © <?= date('Y') ?> - <?= e($appName) ?> / <?= e(__t('Digital System Management Department')) ?>
            </div>
        </footer>
    </div>

    <script>
        const canvas = document.getElementById('digital-particles');
        const ctx = canvas.getContext('2d');
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;

        let particlesArray = [];
        const numberOfParticles = (canvas.width * canvas.height) / 9000;
        const particleColorDark = 'rgba(255, 255, 255, 0.4)';
        const particleColorLight = 'rgba(0, 0, 0, 0.2)';
        const lineColorDark = 'rgba(100, 180, 255, 0.15)';
        const lineColorLight = 'rgba(0, 100, 255, 0.15)';

        function getParticleColors() {
            const isDarkMode = document.documentElement.classList.contains('dark');
            return {
                particle: isDarkMode ? particleColorDark : particleColorLight,
                line: isDarkMode ? lineColorDark : lineColorLight
            };
        }
        class Particle {
            constructor(x, y, directionX, directionY, size) {
                this.x = x; this.y = y; this.directionX = directionX; this.directionY = directionY; this.size = size;
            }
            draw() {
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2, false);
                ctx.fillStyle = getParticleColors().particle;
                ctx.fill();
            }
            update() {
                if (this.x > canvas.width || this.x < 0) { this.directionX = -this.directionX; }
                if (this.y > canvas.height || this.y < 0) { this.directionY = -this.directionY; }
                this.x += this.directionX; this.y += this.directionY;
                this.draw();
            }
        }
        function init() {
            particlesArray = [];
            for (let i = 0; i < numberOfParticles; i++) {
                let size = Math.random() * 2 + 1; let x = Math.random() * canvas.width; let y = Math.random() * canvas.height;
                let directionX = (Math.random() * 0.4) - 0.2; let directionY = (Math.random() * 0.4) - 0.2;
                particlesArray.push(new Particle(x, y, directionX, directionY, size));
            }
        }
        function connect() {
            let opacityValue = 1;
            const currentLineColor = getParticleColors().line; 
            for (let a = 0; a < particlesArray.length; a++) {
                for (let b = a + 1; b < particlesArray.length; b++) {
                    let distance = Math.sqrt((particlesArray[a].x - particlesArray[b].x) ** 2 + (particlesArray[a].y - particlesArray[b].y) ** 2);
                    if (distance < 120) {
                        opacityValue = 1 - (distance / 120);
                        ctx.strokeStyle = currentLineColor.replace('0.15', opacityValue); 
                        ctx.lineWidth = 0.5;
                        ctx.beginPath(); ctx.moveTo(particlesArray[a].x, particlesArray[a].y); ctx.lineTo(particlesArray[b].x, particlesArray[b].y); ctx.stroke();
                    }
                }
            }
        }
        function animate() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            for (let particle of particlesArray) { particle.update(); }
            connect(); requestAnimationFrame(animate);
        }
        window.addEventListener('resize', () => {
            canvas.width = window.innerWidth; canvas.height = window.innerHeight; init();
        });
        init();
        animate();
    </script>
    
    <script>
        let lineChartInstance; 
        let doughnutChartInstance; // Added instance for new chart

        function getChartThemeColors(isDark) {
             return {
                 tickColor: isDark ? '#94a3b8' : '#334155',       
                 gridColor: isDark ? '#334155' : '#e2e8f0',       
                 legendColor: isDark ? '#e2e8f0' : '#1e293b',     
                 tooltipBg: isDark ? '#0f172a' : '#fff',           
                 tooltipTitle: isDark ? '#e2e8f0' : '#1e293b',    
                 tooltipBody: isDark ? '#cbd5e1' : '#334155',    
                 tooltipBorder: isDark ? '#334155' : '#e2e8f0',
                 doughnutBorder: isDark ? '#1e293b' : '#fff' // Color for doughnut border
             };
        }

        // Function to update *all* charts
        function updateAllChartsTheme(isDark) {
            const colors = getChartThemeColors(isDark);
            
            // Update Line Chart
            if (lineChartInstance) {
                lineChartInstance.options.scales.y.ticks.color = colors.tickColor;
                lineChartInstance.options.scales.y.grid.color = colors.gridColor;
                lineChartInstance.options.scales.x.ticks.color = colors.tickColor;
                lineChartInstance.options.plugins.legend.labels.color = colors.legendColor;
                lineChartInstance.options.plugins.tooltip.backgroundColor = colors.tooltipBg;
                lineChartInstance.options.plugins.tooltip.titleColor = colors.tooltipTitle;
                lineChartInstance.options.plugins.tooltip.bodyColor = colors.tooltipBody;
                lineChartInstance.options.plugins.tooltip.borderColor = colors.tooltipBorder;
                lineChartInstance.update();
            }
            
            // Update Doughnut Chart
             if (doughnutChartInstance) {
                doughnutChartInstance.options.plugins.legend.labels.color = colors.legendColor;
                doughnutChartInstance.options.plugins.tooltip.backgroundColor = colors.tooltipBg;
                doughnutChartInstance.options.plugins.tooltip.titleColor = colors.tooltipTitle;
                doughnutChartInstance.options.plugins.tooltip.bodyColor = colors.tooltipBody;
                doughnutChartInstance.options.plugins.tooltip.borderColor = colors.tooltipBorder;
                doughnutChartInstance.data.datasets[0].borderColor = colors.doughnutBorder;
                doughnutChartInstance.update();
            }
        }

        // Function to create Line Chart
        function createLineChart(initialColors) {
            const ctxChart = document.getElementById('chartTickets').getContext('2d');
            lineChartInstance = new Chart(ctxChart, {
                type: 'line',
                data: {
                    labels: <?= json_encode($days) ?>,
                    datasets: [{
                        label: '<?= __t('tickets') ?>',
                        data: <?= json_encode($dataCounts) ?>,
                        tension: 0.3, 
                        fill: true,   
                        backgroundColor: 'rgba(59, 130, 246, 0.2)', 
                        borderColor: 'rgba(59, 130, 246, 1)',     
                        pointBackgroundColor: 'rgba(59, 130, 246, 1)',
                        pointBorderColor: '#fff',
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false, // Allow height control
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: initialColors.tickColor, stepSize: 1 },
                            grid: { color: initialColors.gridColor } 
                        },
                        x: {
                            ticks: { color: initialColors.tickColor },
                            grid: { color: 'transparent' }
                        }
                    },
                    plugins: {
                        legend: { labels: { color: initialColors.legendColor } },
                        tooltip: {
                            backgroundColor: initialColors.tooltipBg,
                            titleColor: initialColors.tooltipTitle,
                            bodyColor: initialColors.tooltipBody,
                            borderColor: initialColors.tooltipBorder,
                            borderWidth: 1
                        }
                    }
                }
            });
        }
        
        // ***** NEW: Function to create Doughnut Chart *****
        function createDoughnutChart(initialColors) {
            const ctxStatus = document.getElementById('chartStatus')?.getContext('2d');
            if (ctxStatus && <?= count($statusData) ?> > 0) {
                 doughnutChartInstance = new Chart(ctxStatus, {
                    type: 'doughnut',
                    data: {
                        labels: <?= json_encode($statusLabels) ?>,
                        datasets: [{
                            label: '<?= __t('status') ?>',
                            data: <?= json_encode($statusData) ?>,
                            backgroundColor: <?= json_encode($statusChartColors) ?>,
                            borderColor: initialColors.doughnutBorder,
                            borderWidth: 2,
                            hoverOffset: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right', 
                                labels: { color: initialColors.legendColor, boxWidth: 12, padding: 15 }
                            },
                            tooltip: {
                                 backgroundColor: initialColors.tooltipBg,
                                 titleColor: initialColors.tooltipTitle,
                                 bodyColor: initialColors.tooltipBody,
                                 borderColor: initialColors.tooltipBorder,
                                 borderWidth: 1
                            }
                        }
                    }
                });
            } else if (ctxStatus) {
                 // Show message if no data
                 const ctx = ctxStatus.canvas.getContext("2d");
                 ctx.font = "14px 'Kantumruy Pro'";
                 ctx.fillStyle = initialColors.tickColor;
                 ctx.textAlign = "center";
                 ctx.fillText("<?= __t('no_data_yet') ?>", ctxStatus.canvas.width / 2, ctxStatus.canvas.height / 2);
            }
        }
        
        // Initial setup on DOM Load
        document.addEventListener('DOMContentLoaded', () => {
            const initialColors = getChartThemeColors(document.documentElement.classList.contains('dark'));
            createLineChart(initialColors);
            createDoughnutChart(initialColors); // Create the new chart
        });
    </script>

    <script>
        function toggleDropdown(id) {
            document.getElementById(id).classList.toggle('hidden');
        }

        // ***** NEW: Notification Mark as Read Logic *****
        let notificationsMarked = false; // Flag to prevent multiple AJAX calls
        function markNotificationsAsRead() {
            const badge = document.getElementById('notification-badge');
            if (badge && !notificationsMarked) {
                notificationsMarked = true; // Set flag
                // Use jQuery POST to send CSRF token easily
                $.post('<?= base_url('api/mark_notifications_read.php') ?>', {
                    _csrf: '<?= e(csrf_token()) ?>' // Pass CSRF token
                }, function(response) {
                    if (response.success) {
                        badge.classList.add('animate-ping', 'opacity-0');
                        setTimeout(() => { badge.remove(); }, 500); // Remove after animation
                    } else {
                        console.error('Failed to mark notifications as read.');
                        notificationsMarked = false; // Allow retry if failed
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                     // This console.error is what shows the 500 error in the console
                     console.error('AJAX error marking notifications as read:', textStatus, errorThrown, jqXHR.responseText);
                     notificationsMarked = false; // Allow retry if failed
                });
            }
        }

        window.addEventListener('click', function(e) {
            const profileMenu = document.getElementById('profile-dropdown-menu');
            if (profileMenu && !profileMenu.contains(e.target)) {
                document.getElementById('profile-dropdown').classList.add('hidden');
            }
            
            // ***** NEW: Close notification dropdown on outside click *****
            const notifMenu = document.getElementById('notification-dropdown-menu');
            if (notifMenu && !notifMenu.contains(e.target)) {
                document.getElementById('notification-dropdown').classList.add('hidden');
            }
            
            const mobileMenu = document.getElementById('mobile-menu');
            const mobileButton = document.querySelector('button[onclick*="mobile-menu"]');
            if (mobileMenu && !mobileMenu.contains(e.target) && !mobileButton.contains(e.target)) {
                mobileMenu.classList.add('hidden');
            }
        });
        
        const themeToggle = document.getElementById('theme-toggle');
        const sunIcon = document.getElementById('theme-icon-sun');
        const moonIcon = document.getElementById('theme-icon-moon');

        function updateThemeIcon() {
            const theme = localStorage.theme || 'system';
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
        updateThemeIcon();

        themeToggle.addEventListener('click', () => { 
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.theme = isDark ? 'dark' : 'light'; 
            updateThemeIcon(); 
            // Update *all* charts when theme changes
            updateAllChartsTheme(isDark);
        }); 
        
         window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', event => {
            if (localStorage.theme === 'system') {
                updateThemeIcon();
                updateAllChartsTheme(event.matches);
            }
        });
    </script>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const counters = document.querySelectorAll('.stat-counter');
            const speed = 200; 

            counters.forEach(counter => {
                const target = +counter.getAttribute('data-target');
                
                const animateCount = () => {
                    const value = +counter.innerText;
                    const increment = Math.ceil(target / speed);

                    if (value < target) {
                        counter.innerText = Math.min(value + increment, target);
                        setTimeout(animateCount, 10);
                    } else {
                        counter.innerText = target;
                    }
                };
                animateCount();
            });
        });
    </script>

</body>
</html>
