<?php
session_start(); // FIX 1: Added session_start()
// --- PHP Logic (Updated Sorting and Limit) ---
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php'; // FIX 1: Added csrf.php
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
$lang=current_lang();
$q=trim($_GET['q']??'');

$isAdminOrCoord = is_role('coordinator');
$where = $isAdminOrCoord ? "1=1" : "is_published=1";
$p=[];

if($q){
    $where.=" AND (title_en LIKE ? OR title_km LIKE ? OR body_en LIKE ? OR body_km LIKE ? OR tags LIKE ?)";
    $p=['%'.$q.'%','%'.$q.'%','%'.$q.'%','%'.$q.'%', '%'.$q.'%'];
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
// ***** FIX 4: Set Limit to 8 *****
$limit = 8; // បង្ហាញ 8 ក្នុងមួយទំព័រ
$offset = ($page - 1) * $limit;

// Count total articles
$countSql = "SELECT COUNT(*) FROM kb_articles WHERE $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($p);
$totalArticles = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalArticles / $limit);

// Fetch articles with pagination
// ***** FIX 5: Added "ORDER BY created_at DESC" *****
$sql = "SELECT id, slug, title_en, title_km, 
               SUBSTRING(IF(? = 'km', body_km, body_en), 1, 100) as excerpt, 
               created_at, tags, is_published, thumbnail_path 
        FROM kb_articles
        WHERE $where
        ORDER BY created_at DESC 
        LIMIT ? OFFSET ?";

$params_with_lang = array_merge([$lang], $p); 

$s=$pdo->prepare($sql);
$s->bindValue(1, $lang, PDO::PARAM_STR);
$param_index = 2;
foreach ($p as $value) {
    $s->bindValue($param_index++, $value, PDO::PARAM_STR);
}
$s->bindValue($param_index++, $limit, PDO::PARAM_INT);
$s->bindValue($param_index, $offset, PDO::PARAM_INT);
$s->execute();
$arts=$s->fetchAll(PDO::FETCH_ASSOC); 

$appName = $lang === 'km' ? $config['app']['name_km'] : $config['app']['name_en'];
$currentUser = current_user();
$defaultAvatar = base_url('assets/img/logo.png'); // FIX: Consistent avatar
?>

<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appName) ?> - <?= __t('knowledge_base') ?></title>
	<link rel="icon" href="<?= base_url('assets/img/logo.png') ?>">
    <script> /* Theme Loader */ if(localStorage.theme==='dark'||(!('theme' in localStorage)&&window.matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark')}else{document.documentElement.classList.remove('dark')} </script>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script> tailwind.config={darkMode:'class'} </script>
    
    <script src="https://unpkg.com/lucide-react@latest/dist/lucide-react.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;700&display=swap');
        body { font-family: 'Kantumruy Pro', sans-serif; }
        /* ***** FIX 1: Added Digital Background Style ***** */
        #digital-particles { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
        ::-webkit-scrollbar { width: 6px; } ::-webkit-scrollbar-track { background: #f1f5f9; } ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 10px; } ::-webkit-scrollbar-thumb:hover { background: #475569; }
        .dark ::-webkit-scrollbar-track { background: #1e293b; } .dark ::-webkit-scrollbar-thumb { background: #334155; } .dark ::-webkit-scrollbar-thumb:hover { background: #475569; }
        .kb-card-thumbnail { aspect-ratio: 16 / 9; object-fit: cover; }
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
                    <a href="?<?= http_build_query(array_merge($_GET, ['setlang' => 'km'])) ?>" title="ភាសាខ្មែរ" class="rounded-full transition-all duration-300 <?= $lang === 'km' ? 'ring-2 ring-blue-500 ring-offset-2 ring-offset-white dark:ring-offset-slate-800' : 'opacity-60 hover:opacity-100' ?>">
                        <img src="<?= base_url('assets/img/flag-km.png') ?>" alt="ភាសាខ្មែរ" class="w-6 h-6 rounded-full object-cover">
                    </a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['setlang' => 'en'])) ?>" title="English" class="rounded-full transition-all duration-300 <?= $lang === 'en' ? 'ring-2 ring-blue-500 ring-offset-2 ring-offset-white dark:ring-offset-slate-800' : 'opacity-60 hover:opacity-100' ?>">
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

            <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 md:p-8 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50">

                <div class="flex flex-col sm:flex-row justify-between items-center mb-6 gap-4">
                    <h5 class="text-xl font-semibold text-gray-700 dark:text-gray-200"><?= __t('knowledge_base') ?></h5>
                    <?php if ($isAdminOrCoord): ?>
                    <a href="<?= base_url('admin/kb_edit.php') ?>"
                       class="w-full sm:w-auto flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <?= __t('add_article') ?>
                    </a>
                    <?php endif; ?>
                </div>

                <form method="get" class="flex items-center gap-2 mb-8 max-w-lg mx-auto">
                    <label for="q" class="sr-only"><?= __t('search') ?></label>
                    <input type="text" name="q" id="q" placeholder="<?= __t('search_kb_placeholder') ?>..." value="<?= e($q) ?>"
                           class="flex-grow px-4 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200">
                    <button type="submit" class="flex-shrink-0 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                        <?= __t('search') ?>
                    </button>
                </form>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <?php if (empty($arts)): ?>
                        <p class="text-center text-gray-500 dark:text-gray-400 md:col-span-2 lg:col-span-4">
                            <?= __t('no_articles_found') ?>
                        </p>
                    <?php endif; ?>

                    <?php
                        $placeholder_base = "https://placehold.co/600x338"; // 16:9 ratio
                        $placeholder_fallback = base_url('assets/img/kb_placeholder.png'); // Your local fallback
                    ?>
                    <?php foreach($arts as $a):
                        $article_title = $lang==='km' && !empty($a['title_km']) ? $a['title_km'] : $a['title_en'];
                        $view_link = base_url("kb_view.php?id=".(int)$a['id'].($a['slug'] ? '&slug='.e($a['slug']) : ''));
                        $edit_link = base_url("admin/kb_edit.php?id=".(int)$a['id']);
                        $tags = !empty($a['tags']) ? explode(',', $a['tags']) : [];

                        $thumbnail_url = (isset($a['thumbnail_path']) && !empty($a['thumbnail_path'])) ? base_url($a['thumbnail_path']) . '?t=' . time() : null;

                        if (!$thumbnail_url) {
                            $placeholder_text = urlencode(mb_substr(strip_tags($article_title), 0, 20));
                            $bgColor = substr(md5((string)$a['id']), 0, 6); 
                            $textColor = 'ffffff'; 
                            $thumbnail_url = "{$placeholder_base}/{$bgColor}/{$textColor}?text={$placeholder_text}&font=kantumruypro";
                        }
                    ?>
                    <div class="bg-white dark:bg-slate-800 rounded-lg shadow-md overflow-hidden border border-gray-200 dark:border-slate-700 flex flex-col transition-shadow duration-300 hover:shadow-xl dark:hover:shadow-blue-900/30">
                        <a href="<?= $view_link ?>" class="block group"> 
                            <img src="<?= e($thumbnail_url) ?>"
                                 onerror="this.onerror=null; this.src='<?= e($placeholder_fallback) ?>';" 
                                 alt="<?= e($article_title) ?>"
                                 loading="lazy" 
                                 class="w-full kb-card-thumbnail bg-gray-200 dark:bg-slate-700 transition-transform duration-300 group-hover:scale-105">
                        </a>
                        <div class="p-4 flex flex-col flex-grow">
                            <h3 class="mb-2">
                                <a class="text-base font-semibold text-blue-600 dark:text-blue-400 hover:underline line-clamp-2" href="<?= $view_link ?>">
                                    <?= e($article_title) ?>
                                    <?php if ($isAdminOrCoord && !$a['is_published']): ?>
                                        <span class="ml-1 text-xs font-medium px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300 align-middle"><?= __t('unpublished') ?></span>
                                    <?php endif; ?>
                                </a>
                            </h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3 line-clamp-3 flex-grow">
                                <?= e(strip_tags($a['excerpt'])) ?>...
                            </p>
                             <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-500 mt-auto pt-2 border-t border-gray-100 dark:border-slate-700">
                               <span><?= time_ago($a['created_at']) ?></span>
                               <?php if ($isAdminOrCoord): ?>
                                    <a href="<?= $edit_link ?>" class="flex items-center gap-1 text-blue-500 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 transition-colors duration-300" title="<?= __t('edit') ?>">
                                         <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($tags)): ?>
                             <div class="mt-2 flex flex-wrap gap-1">
                                <?php foreach($tags as $tag): ?>
                                    <a href="<?= base_url('kb.php?q='.urlencode(trim($tag))) ?>" class="px-1.5 py-0.5 text-xs bg-gray-100 dark:bg-slate-700 rounded text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-slate-600"><?= e(trim($tag)) ?></a>
                                <?php endforeach; ?>
                           </div>
                           <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($totalPages > 1): ?>
                    <nav class="flex items-center justify-between pt-8 mt-8 border-t border-gray-200 dark:border-slate-700" aria-label="Table navigation">
                        <span class="text-sm font-normal text-gray-500 dark:text-gray-400">Showing <span class="font-semibold text-gray-900 dark:text-white"><?= $offset + 1 ?>-<?= min($offset + $limit, $totalArticles) ?></span> of <span class="font-semibold text-gray-900 dark:text-white"><?= $totalArticles ?></span></span>
                        <ul class="inline-flex items-center -space-x-px">
                            <li><a href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])) ?>" class="block px-3 py-2 ml-0 leading-tight text-gray-500 bg-white border border-gray-300 rounded-l-lg hover:bg-gray-100 hover:text-gray-700 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-white <?= $page <= 1 ? 'opacity-50 cursor-not-allowed' : '' ?>"><span class="sr-only">Previous</span><svg class="w-5 h-5" aria-hidden="true" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg></a></li>
                            <?php $startPage = max(1, $page - 2); $endPage = min($totalPages, $page + 2); if ($startPage > 1) { echo '<li><a href="?'.http_build_query(array_merge($_GET, ['page' => 1])).'" class="px-3 py-2 leading-tight text-gray-500 bg-white border border-gray-300 hover:bg-gray-100 hover:text-gray-700 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-white">1</a></li>'; if ($startPage > 2) echo '<li><span class="px-3 py-2 leading-tight text-gray-500 bg-white border border-gray-300 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400">...</span></li>'; } for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <li><a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>" class="px-3 py-2 leading-tight <?= $p == $page ? 'text-blue-600 bg-blue-50 border border-blue-300 hover:bg-blue-100 hover:text-blue-700 dark:border-slate-700 dark:bg-slate-700 dark:text-white' : 'text-gray-500 bg-white border border-gray-300 hover:bg-gray-100 hover:text-gray-700 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-white' ?>"><?= $p ?></a></li>
                            <?php endfor; if ($endPage < $totalPages) { if ($endPage < $totalPages - 1) echo '<li><span class="px-3 py-2 leading-tight text-gray-500 bg-white border border-gray-300 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400">...</span></li>'; echo '<li><a href="?'.http_build_query(array_merge($_GET, ['page' => $totalArticles])).'" class="px-3 py-2 leading-tight text-gray-500 bg-white border border-gray-300 hover:bg-gray-100 hover:text-gray-700 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-white">'.$totalPages.'</a></li>'; } ?>
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

    <script> /* Digital Particle Script */ 
        const canvas = document.getElementById('digital-particles'); const ctx = canvas.getContext('2d'); canvas.width = window.innerWidth; canvas.height = window.innerHeight; let particlesArray = []; const numberOfParticles = (canvas.width * canvas.height) / 9000; const particleColorDark = 'rgba(255, 255, 255, 0.4)'; const particleColorLight = 'rgba(0, 0, 0, 0.2)'; const lineColorDark = 'rgba(100, 180, 255, 0.15)'; const lineColorLight = 'rgba(0, 100, 255, 0.15)'; 
        function getParticleColors() { const isDarkMode = document.documentElement.classList.contains('dark'); return { particle: isDarkMode ? particleColorDark : particleColorLight, line: isDarkMode ? lineColorDark : lineColorLight }; } 
        class Particle { constructor(x, y, dX, dY, s) { this.x=x;this.y=y;this.directionX=dX;this.directionY=dY;this.size=s;} draw() { ctx.beginPath(); ctx.arc(this.x,this.y,this.size,0,Math.PI*2,false); ctx.fillStyle=getParticleColors().particle; ctx.fill(); } update() { if(this.x>canvas.width||this.x<0)this.directionX=-this.directionX; if(this.y>canvas.height||this.y<0)this.directionY=-this.directionY; this.x+=this.directionX; this.y+=this.directionY; this.draw();}} 
        function init() { particlesArray=[]; for(let i=0;i<numberOfParticles;i++) { let s=Math.random()*2+1,x=Math.random()*canvas.width,y=Math.random()*canvas.height,dX=(Math.random()*0.4)-0.2,dY=(Math.random()*0.4)-0.2; particlesArray.push(new Particle(x,y,dX,dY,s));}} 
        function connect() { let opVal=1; const curLineColor=getParticleColors().line; for(let a=0;a<particlesArray.length;a++) { for(let b=a+1;b<particlesArray.length;b++) { let dist=Math.sqrt((particlesArray[a].x-particlesArray[b].x)**2 + (particlesArray[a].y-particlesArray[b].y)**2); if(dist<120) { opVal=1-(dist/120); ctx.strokeStyle=curLineColor.replace('0.15',opVal); ctx.lineWidth=0.5; ctx.beginPath(); ctx.moveTo(particlesArray[a].x,particlesArray[a].y); ctx.lineTo(particlesArray[b].x,particlesArray[b].y); ctx.stroke();}}}} 
        function animate() { ctx.clearRect(0,0,canvas.width,canvas.height); particlesArray.forEach(p => p.update()); connect(); requestAnimationFrame(animate);} 
        window.addEventListener('resize', () => { canvas.width=window.innerWidth; canvas.height=window.innerHeight; init();}); init(); animate(); 
    </script>
    <script> /* Dropdown & Theme Toggle Script */ 
        function toggleDropdown(id) { document.getElementById(id).classList.toggle('hidden'); } 
        window.addEventListener('click', function(e) { 
            const pM=document.getElementById('profile-dropdown-menu'); 
            if(pM&&!pM.contains(e.target))document.getElementById('profile-dropdown').classList.add('hidden'); 

            // FIX: Added Notification click-outside logic
            const notifMenu = document.getElementById('notification-dropdown-menu');
            if (notifMenu && !notifMenu.contains(e.target)) {
                document.getElementById('notification-dropdown').classList.add('hidden');
            }
            
            const mN=document.getElementById('mobile-menu'), mB=document.querySelector('button[onclick*="mobile-menu"]'); 
            if(mN&&!mN.contains(e.target)&&!mB.contains(e.target))mN.classList.add('hidden');
        }); 
        
        // ***** FIX 2: Added Theme Toggle Logic *****
        const themeToggle=document.getElementById('theme-toggle'), sunIcon=document.getElementById('theme-icon-sun'), moonIcon=document.getElementById('theme-icon-moon'); 
        function updateThemeIcon() { 
            // Read from localStorage first
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
        updateThemeIcon(); // Run on load
        
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
                notificationsMarked = false; // Allow retry on failure
            });
        } else {
            // Fallback to fetch (if jQuery failed to load)
            console.error('jQuery not loaded. Using fallback fetch for notifications.');
            fetch('<?= base_url('api/mark_notifications_read.php') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: '_csrf=<?= e(csrf_token()) ?>'
            }).then(r => r.json()).then(function(response){
                try { if (response.success) { badge.classList.add('animate-ping', 'opacity-0'); setTimeout(() => badge.remove(), 500); } } catch(e){}
            }).catch(function(){ 
                console.error('Failed to mark notifications as read.'); 
                notificationsMarked = false; // Allow retry on failure
            });
        }
    }
}
// ***** END Notification Logic *****

</script>
</body>
</html>