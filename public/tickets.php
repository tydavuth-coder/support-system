<?php 
session_start(); 
require_once __DIR__ . '/../config.php'; 
require_once __DIR__ . '/../includes/functions.php'; 
require_once __DIR__ . '/../includes/auth.php'; 
require_once __DIR__ . '/../includes/csrf.php'; 
require_login(); 

$u=current_user(); 
$isAdminOrCoord=is_role('coordinator') || is_role('admin'); 
$where="1=1"; 
$params=[]; 

// --- Filtering Logic ---
if(!empty($_GET['date_from'])){ $where.=" AND DATE(t.created_at)>=?"; $params[]=$_GET['date_from']; } 
if(!empty($_GET['date_to'])){ $where.=" AND DATE(t.created_at)<=?"; $params[]=$_GET['date_to']; } 
if(!empty($_GET['status'])){ $where.=" AND t.status=?"; $params[]=$_GET['status']; } 
if(!empty($_GET['staff'])){ $where.=" AND (t.assigned_to=? OR t.user_id=?)"; $params[]=$_GET['staff']; $params[]=$_GET['staff']; } 

if(!$isAdminOrCoord){ 
    if($u['role'] === 'technical') {
        $where .= " AND (t.user_id=? OR t.assigned_to=?)";
        $params[] = $u['id'];
        $params[] = $u['id'];
    } else {
        $where .= " AND t.user_id=?"; 
        $params[] = $u['id']; 
    }
}
if(!empty($_GET['q'])){ $where.=" AND (t.title LIKE ? OR t.description LIKE ?)"; $params[]='%'.$_GET['q'].'%'; $params[]='%'.$_GET['q'].'%'; }

// --- Pagination ---
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20; 
$offset = ($page - 1) * $limit;

// Count Query
$countSql = "SELECT COUNT(*) FROM tickets t WHERE $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params); 
$totalTickets = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalTickets / $limit);

// Main Query
$sql="SELECT t.*, u.name AS user_name, a.name AS assignee_name 
      FROM tickets t 
      LEFT JOIN users u ON u.id=t.user_id 
      LEFT JOIN users a ON a.id=t.assigned_to 
      WHERE $where 
      ORDER BY t.updated_at DESC 
      LIMIT ? OFFSET ?"; 
      
$stmt=$pdo->prepare($sql); 
$tickets = []; 

try {
    $param_index = 1;
    foreach ($params as $value) {
        $param_type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($param_index, $value, $param_type);
        $param_index++;
    }
    $stmt->bindValue($param_index, (int)$limit, PDO::PARAM_INT);
    $param_index++;
    $stmt->bindValue($param_index, (int)$offset, PDO::PARAM_INT);

    $stmt->execute(); 
    $tickets=$stmt->fetchAll(PDO::FETCH_ASSOC); 
} catch (PDOException $e) {
     error_log("Error executing ticket query: " . $e->getMessage());
}

// Fetch Staff List
$staffList=$pdo->query("SELECT id,name FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); 
$techs=[]; 
if($isAdminOrCoord){ 
    $techs=$pdo->query("SELECT id,name FROM users WHERE role IN ('technical','coordinator','admin') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); 
}

// ***** Notification Logic *****
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$notifStmt->execute([$u['id']]);
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

$unreadCountStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadCountStmt->execute([$u['id']]);
$unreadCount = (int)$unreadCountStmt->fetchColumn();
// ***** END Notification Logic *****

// Flash Messages
$flashMsg = '';
$flashType = '';
if (isset($_SESSION['flash_message'])) {
    $flashMsg = $_SESSION['flash_message']['text'];
    $flashType = $_SESSION['flash_message']['type'];
    unset($_SESSION['flash_message']);
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
    <title><?= e($appName) ?> - <?= __t('tickets') ?></title>
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
        @media (max-width: 768px) { 
            .table-responsive thead { display: none; } 
            .table-responsive tr { display: block; margin-bottom: 1rem; border-bottom: 2px solid #e5e7eb; padding-bottom: 0.5rem;} 
            .dark .table-responsive tr { border-bottom-color: #374151; } 
            .table-responsive td { display: block; text-align: right; padding-left: 50%; position: relative; padding-top: 0.5rem; padding-bottom: 0.5rem;} 
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
                         <img src="<?= base_url(get_setting('site_logo', 'assets/img/logo_128x128.png')) ?>" alt="Logo" class="h-10 w-auto">
                        <span class="text-xl font-bold text-gray-900 dark:text-white hidden sm:block"><?= e($appName) ?></span>
                    </a>
                    <div class="hidden md:flex items-center gap-4">
                        <a href="<?= base_url('dashboard.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('dashboard') ?></a>
                        <a href="<?= base_url('tickets.php') ?>" class="text-white font-semibold bg-blue-600 px-3 py-1.5 rounded-lg text-sm"><?= __t('tickets') ?></a>
                        <a href="<?= base_url('kb.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('knowledge_base') ?></a>
                        <a href="<?= base_url('reports.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('reports') ?></a>
                        <?php if (is_role('admin')): ?>
                        <a href="<?= base_url('admin/users.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('users') ?></a>
                        <a href="<?= base_url('admin/settings.php') ?>" class="text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white transition-colors duration-200 text-sm"><?= __t('settings') ?></a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <a href="?<?= http_build_query(array_merge($_GET, ['setlang' => 'km'])) ?>" class="rounded-full transition-all duration-300 <?= $lang === 'km' ? 'ring-2 ring-blue-500 ring-offset-2 ring-offset-white dark:ring-offset-slate-800' : 'opacity-60 hover:opacity-100' ?>">
                        <img src="<?= base_url('assets/img/flag-km.png') ?>" alt="ភាសាខ្មែរ" class="w-6 h-6 rounded-full object-cover">
                    </a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['setlang' => 'en'])) ?>" class="rounded-full transition-all duration-300 <?= $lang === 'en' ? 'ring-2 ring-blue-500 ring-offset-2 ring-offset-white dark:ring-offset-slate-800' : 'opacity-60 hover:opacity-100' ?>">
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
                <?php if (is_role('admin')): ?>
                <a href="<?= base_url('admin/users.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('users') ?></a>
                <a href="<?= base_url('admin/settings.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('settings') ?></a>
                <?php endif; ?>
            </div>
        </header>

        <main class="flex-grow container mx-auto px-4 py-8 mt-8">
            <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50">
                
                <?php if ($flashMsg): ?>
                    <div class="<?= $flashType === 'success' ? 'bg-green-100 text-green-700 border-green-400' : 'bg-red-100 text-red-700 border-red-400' ?> border px-4 py-3 rounded-lg mb-6 relative" role="alert">
                        <span class="block sm:inline"><?= e($flashMsg) ?></span>
                    </div>
                <?php endif; ?>

                <div class="flex flex-col sm:flex-row justify-between items-center mb-6 gap-4">
                    <h5 class="text-xl font-semibold text-gray-700 dark:text-gray-200"><?= __t('tickets') ?></h5>
                    <a class="w-full sm:w-auto flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800" href="ticket_new.php">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <?= __t('new_ticket') ?>
                    </a>
                </div>
                <form method="get" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                    <div class="lg:col-span-2">
                        <label for="q" class="sr-only"><?= __t('search') ?></label>
                        <input type="text" name="q" id="q" placeholder="<?= __t('search') ?>..." value="<?= e($_GET['q'] ?? '') ?>" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                    </div>
                    <div>
                        <label for="date_from" class="sr-only"><?= __t('date_from') ?></label>
                        <input type="date" name="date_from" id="date_from" value="<?= e($_GET['date_from'] ?? '') ?>" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                    </div>
                    <div>
                        <label for="date_to" class="sr-only"><?= __t('date_to') ?></label>
                        <input type="date" name="date_to" id="date_to" value="<?= e($_GET['date_to'] ?? '') ?>" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                    </div>
                    <div>
                        <label for="status" class="sr-only"><?= __t('status') ?></label>
                        <select name="status" id="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                            <option value=""><?= __t('all_status') ?></option>
                            <option value="received" <?= (@$_GET['status']=='received')?'selected':'' ?>><?= __t('received') ?></option>
                            <option value="in_progress" <?= (@$_GET['status']=='in_progress')?'selected':'' ?>><?= __t('in_progress') ?></option>
                            <option value="completed" <?= (@$_GET['status']=='completed')?'selected':'' ?>><?= __t('completed') ?></option>
                            <option value="rejected" <?= (@$_GET['status']=='rejected')?'selected':'' ?>><?= __t('rejected') ?></option>
                        </select>
                    </div>
                    <?php if($isAdminOrCoord): ?>
                    <div>
                        <label for="staff" class="sr-only"><?= __t('staff') ?></label>
                        <select name="staff" id="staff" class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                            <option value=""><?= __t('all_staff') ?></option>
                            <?php foreach($staffList as $s): ?>
                                <option value="<?= (int)$s['id'] ?>" <?= (@$_GET['staff']==$s['id'])?'selected':'' ?>><?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <?php if(!empty($_GET['staff'])): ?> <input type="hidden" name="staff" value="<?= e($_GET['staff']) ?>"> <?php endif; ?>
                        <div class="hidden lg:block"></div>
                    <?php endif; ?>
                    <button type="submit" class="w-full sm:w-auto px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800 lg:col-start-6">
                        <?= __t('search') ?>
                    </button>
                </form>
                <div class="overflow-x-auto table-responsive">
                    <table class="w-full text-sm text-left text-gray-600 dark:text-gray-400">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-slate-700 dark:text-gray-300">
                            <tr>
                                <th scope="col" class="px-4 py-3">ID</th>
                                <th scope="col" class="px-4 py-3"><?= __t('title') ?></th>
                                <th scope="col" class="px-4 py-3"><?= __t('type') ?></th>
                                <th scope="col" class="px-4 py-3"><?= __t('priority') ?></th>
                                <th scope="col" class="px-4 py-3"><?= __t('status') ?></th>
                                <th scope="col" class="px-4 py-3"><?= __t('assigned_to') ?></th>
                                <th scope="col" class="px-4 py-3"><?= __t('updated_at') ?></th>
                                <th scope="col" class="px-4 py-3 text-right"><?= __t('actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tickets)): ?>
                                <tr class="border-b dark:border-slate-700">
                                    <td colspan="8" class="px-4 py-4 text-center text-gray-500 dark:text-gray-400"><?= __t('no_tickets_found') ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach($tickets as $t): 
                                $statusCls = match($t['status']) { 'received' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300', 'in_progress' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300', 'completed' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300', 'rejected' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300', default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' };
                                $priorityCls = match($t['priority']) { 'Low' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300', 'Normal' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300', 'High' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300', 'Urgent' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300', default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' };
                                
                                // Calculate Edit Permission for this row
                                $canEditRow = false;
                                if ($isAdminOrCoord) { 
                                    $canEditRow = true; 
                                } elseif ($u['id'] == $t['user_id'] && !in_array($t['status'], ['completed', 'rejected'])) { 
                                    $canEditRow = true; 
                                }
                            ?>
                            <tr class="bg-white dark:bg-slate-800 border-b dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700">
                                <td data-label="ID" class="px-4 py-3 font-medium text-gray-900 dark:text-white">#<?= (int)$t['id'] ?></td>
                                <td data-label="<?= __t('title') ?>" class="px-4 py-3"><span class="font-semibold"><?= e($t['title']) ?></span><div class="text-xs text-gray-500 dark:text-gray-400"><?= e(mb_strimwidth($t['description'],0,100,'…','UTF-8')) ?></div></td>
                                <td data-label="<?= __t('type') ?>" class="px-4 py-3"><span class="px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300"><?= e($t['type']) ?></span></td>
                                <td data-label="<?= __t('priority') ?>" class="px-4 py-3"><span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $priorityCls ?>"><?= e($t['priority']) ?></span></td>
                                <td data-label="<?= __t('status') ?>" class="px-4 py-3"><span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $statusCls ?>"><?= e(__t($t['status'])) ?></span></td>
                                <td data-label="<?= __t('assigned_to') ?>" class="px-4 py-3"><?= e($t['assignee_name'] ?: '-') ?></td>
                                <td data-label="<?= __t('updated_at') ?>" class="px-4 py-3 whitespace-nowrap"><?= function_exists('time_ago') ? time_ago($t['updated_at']) : e($t['updated_at']) ?></td>
                                <td data-label="<?= __t('actions') ?>" class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        
                                        <a class="text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/30 p-1.5 rounded transition-colors" href="ticket_view.php?id=<?= (int)$t['id'] ?>" title="<?= __t('view') ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                        </a>
                                        
                                        <?php if($canEditRow): ?>
                                        <a class="text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-slate-600 p-1.5 rounded transition-colors" href="ticket_edit.php?id=<?= (int)$t['id'] ?>" title="<?= __t('edit') ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>
                                        <?php endif; ?>

                                        <?php if (strtolower($t['status']) === 'completed'): ?>
                                        <a class="text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-slate-600 p-1.5 rounded transition-colors" href="print_ticket.php?id=<?= (int)$t['id'] ?>" target="_blank" title="<?= __t('print') ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                                        </a>
                                        <?php endif; ?>

                                        <?php if($isAdminOrCoord): ?>
                                            <form method="post" action="api/ticket_assign.php" class="flex items-center" title="<?= __t('assign') ?>">
                                                <?php csrf_field(); ?> <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                                <select class="px-2 py-1 text-xs border border-gray-300 rounded-l-md dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:text-gray-200 w-20" name="assigned_to">
                                                    <option value="">-</option>
                                                    <?php foreach($techs as $tech): ?>
                                                    <option value="<?= (int)$tech['id'] ?>" <?= $t['assigned_to'] == $tech['id'] ? 'selected' : '' ?>><?= e($tech['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="bg-gray-100 dark:bg-slate-700 border border-l-0 border-gray-300 dark:border-slate-600 rounded-r-md p-1 hover:bg-gray-200 dark:hover:bg-slate-600"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg></button>
                                            </form>

                                            <form method="post" action="api/ticket_delete.php" onsubmit="return confirm('<?= __t('are_you_sure_delete') ?>');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                                <button type="submit" class="text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 p-1.5 rounded transition-colors ml-1" title="<?= __t('delete') ?>">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
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
                <?php if ($totalPages > 1): ?>
                    <nav class="flex items-center justify-between pt-4" aria-label="Table navigation">
                        <span class="text-sm font-normal text-gray-500 dark:text-gray-400">Showing <span class="font-semibold text-gray-900 dark:text-white"><?= $offset + 1 ?>-<?= min($offset + $limit, $totalTickets) ?></span> of <span class="font-semibold text-gray-900 dark:text-white"><?= $totalTickets ?></span></span>
                        <ul class="inline-flex items-center -space-x-px">
                            <li><a href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])) ?>" class="block px-3 py-2 ml-0 leading-tight text-gray-500 bg-white border border-gray-300 rounded-l-lg hover:bg-gray-100 hover:text-gray-700 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-white <?= $page <= 1 ? 'opacity-50 cursor-not-allowed' : '' ?>"><span class="sr-only">Previous</span><svg class="w-5 h-5" aria-hidden="true" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg></a></li>
                            <?php $startPage = max(1, $page - 2); $endPage = min($totalPages, $page + 2); if ($startPage > 1) { echo '<li><a href="?'.http_build_query(array_merge($_GET, ['page' => 1])).'" class="px-3 py-2 leading-tight text-gray-500 bg-white border border-gray-300 hover:bg-gray-100 hover:text-gray-700 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-white">1</a></li>'; if ($startPage > 2) echo '<li><span class="px-3 py-2 leading-tight text-gray-500 bg-white border border-gray-300 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400">...</span></li>'; } for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <li><a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>" class="px-3 py-2 leading-tight <?= $p == $page ? 'text-blue-600 bg-blue-50 border border-blue-300 hover:bg-blue-100 hover:text-blue-700 dark:border-slate-700 dark:bg-slate-700 dark:text-white' : 'text-gray-500 bg-white border border-gray-300 hover:bg-gray-100 hover:text-gray-700 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-white' ?>"><?= $p ?></a></li>
                            <?php endfor; if ($endPage < $totalPages) { if ($endPage < $totalPages - 1) echo '<li><span class="px-3 py-2 leading-tight text-gray-500 bg-white border border-gray-300 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400">...</span></li>'; echo '<li><a href="?'.http_build_query(array_merge($_GET, ['page' => $totalPages])).'" class="px-3 py-2 leading-tight text-gray-500 bg-white border border-gray-300 hover:bg-gray-100 hover:text-gray-700 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-white">'.$totalPages.'</a></li>'; } ?>
                            <li><a href="?<?= http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)])) ?>" class="block px-3 py-2 leading-tight text-gray-500 bg-white border border-gray-300 rounded-r-lg hover:bg-gray-100 hover:text-gray-700 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-white <?= $page >= $totalPages ? 'opacity-50 cursor-not-allowed' : '' ?>"><span class="sr-only">Next</span><svg class="w-5 h-5" aria-hidden="true" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg></a></li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </main>
    
        <footer class="bg-white dark:bg-slate-800/80 mt-12 py-4 border-t border-gray-200 dark:border-transparent">
            <div class="container mx-auto px-4 text-center text-gray-500 dark:text-gray-400 text-sm">
                © <?= date('Y') ?> - <?= e($appName) ?> / <?= e(__t('Digital System Management Department')) ?>
            </div>
        </footer>
    </div>

    <script> /* Digital Particle Script */ const canvas = document.getElementById('digital-particles'); const ctx = canvas.getContext('2d'); canvas.width = window.innerWidth; canvas.height = window.innerHeight; let particlesArray = []; const numberOfParticles = (canvas.width * canvas.height) / 9000; const particleColorDark = 'rgba(255, 255, 255, 0.4)'; const particleColorLight = 'rgba(0, 0, 0, 0.2)'; const lineColorDark = 'rgba(100, 180, 255, 0.15)'; const lineColorLight = 'rgba(0, 100, 255, 0.15)'; function getParticleColors() { const isDarkMode = document.documentElement.classList.contains('dark'); return { particle: isDarkMode ? particleColorDark : particleColorLight, line: isDarkMode ? lineColorDark : lineColorLight }; } class Particle { constructor(x, y, dX, dY, s) { this.x=x;this.y=y;this.directionX=dX;this.directionY=dY;this.size=s;} draw() { ctx.beginPath(); ctx.arc(this.x,this.y,this.size,0,Math.PI*2,false); ctx.fillStyle=getParticleColors().particle; ctx.fill(); } update() { if(this.x>canvas.width||this.x<0)this.directionX=-this.directionX; if(this.y>canvas.height||this.y<0)this.directionY=-this.directionY; this.x+=this.directionX; this.y+=this.directionY; this.draw();}} function init() { particlesArray=[]; for(let i=0;i<numberOfParticles;i++) { let s=Math.random()*2+1,x=Math.random()*canvas.width,y=Math.random()*canvas.height,dX=(Math.random()*0.4)-0.2,dY=(Math.random()*0.4)-0.2; particlesArray.push(new Particle(x,y,dX,dY,s));}} function connect() { let opVal=1; const curLineColor=getParticleColors().line; for(let a=0;a<particlesArray.length;a++) { for(let b=a+1;b<particlesArray.length;b++) { let dist=Math.sqrt((particlesArray[a].x-particlesArray[b].x)**2 + (particlesArray[a].y-particlesArray[b].y)**2); if(dist<120) { opVal=1-(dist/120); ctx.strokeStyle=curLineColor.replace('0.15',opVal); ctx.lineWidth=0.5; ctx.beginPath(); ctx.moveTo(particlesArray[a].x,particlesArray[a].y); ctx.lineTo(particlesArray[b].x,particlesArray[b].y); ctx.stroke();}}}} function animate() { ctx.clearRect(0,0,canvas.width,canvas.height); particlesArray.forEach(p => p.update()); connect(); requestAnimationFrame(animate);} window.addEventListener('resize', () => { canvas.width=window.innerWidth; canvas.height=window.innerHeight; init();}); init(); animate(); </script>
    
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

        // ***** Notification Mark as Read Logic *****
        let notificationsMarked = false; 
        function markNotificationsAsRead() {
            const badge = document.getElementById('notification-badge');
            if (badge && !notificationsMarked) {
                notificationsMarked = true; 
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
                }).fail(function() {
                     console.error('AJAX error marking notifications as read.');
                     notificationsMarked = false; 
                });
            }
        }
    </script>
    
</body>
</html>