<?php 
session_start(); 
require_once __DIR__ . '/../config.php'; 
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

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); die('Invalid Ticket ID'); } 

// Fetch Ticket
$stmt = $pdo->prepare("SELECT t.*, u.name AS user_name, u.avatar AS user_avatar, a.name AS assignee_name, a.avatar AS assignee_avatar, u.email AS user_email, u.phone as user_phone 
                     FROM tickets t 
                     LEFT JOIN users u ON u.id=t.user_id 
                     LEFT JOIN users a ON a.id=t.assigned_to 
                     WHERE t.id=?"); 
$stmt->execute([$id]); 
$ticket = $stmt->fetch(PDO::FETCH_ASSOC); 

if (!$ticket) { http_response_code(404); die('Ticket Not found'); }

// Permission Check (View)
$canView = false;
if (is_role('coordinator')) { $canView = true; } 
elseif ($u['id'] == $ticket['user_id']) { $canView = true; } 
elseif ($u['role'] === 'technical' && ($u['id'] == $ticket['assigned_to'] || $ticket['assigned_to'] === null) ) { $canView = true; }
if (!$canView) { http_response_code(403); die('Forbidden: You do not have permission to view this ticket.'); }

// Permission Check (Edit Ticket Details)
$canEditTicket = false;
if (is_role('admin') || is_role('coordinator')) { 
    $canEditTicket = true; 
} elseif ($u['id'] == $ticket['user_id'] && !in_array($ticket['status'], ['completed', 'rejected'])) { 
    $canEditTicket = true; 
}

// Fetch Messages
$messages = $pdo->prepare("SELECT m.*, us.name, us.avatar FROM messages m LEFT JOIN users us ON us.id=m.sender_id WHERE ticket_id=? ORDER BY m.created_at ASC"); 
$messages->execute([$id]); 
$messages = $messages->fetchAll(PDO::FETCH_ASSOC); 

// Fetch Attachments
$att = $pdo->prepare("SELECT * FROM attachments WHERE ticket_id=?"); 
$att->execute([$id]); 
$attachments = $att->fetchAll(PDO::FETCH_ASSOC); 

// Fetch Activity Log
$act = $pdo->prepare("SELECT a.*, u.name AS actor, u.avatar AS actor_avatar FROM ticket_activity a LEFT JOIN users u ON u.id=a.created_by WHERE a.ticket_id=? ORDER BY a.created_at DESC"); 
$act->execute([$id]); 
$activity = $act->fetchAll(PDO::FETCH_ASSOC); 

// Fetch Feedback
$feedbackStmt = $pdo->prepare("SELECT * FROM feedback WHERE ticket_id = ? AND user_id = ?");
$feedbackStmt->execute([$id, $u['id']]);
$existingFeedback = $feedbackStmt->fetch(PDO::FETCH_ASSOC);

// Permission Check (Update Status)
$canEditStatus = false; 
if (is_role('admin') || is_role('coordinator')) $canEditStatus = true; 
elseif ($u['role'] === 'technical' && (int)$ticket['assigned_to'] === (int)$u['id']) $canEditStatus = true;

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
    <title><?= e($appName) ?> - Ticket #<?= (int)$ticket['id'] ?></title>
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
        
        /* Chat Styles */
        .chat-box { max-height: 400px; overflow-y: auto; scroll-behavior: smooth; } 
        .chat-message { display: flex; margin-bottom: 1rem; }
        .chat-message.sent { flex-direction: row-reverse; }
        .chat-message .avatar { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; margin: 0 0.5rem; background-color: #e2e8f0; flex-shrink: 0; } 
        .dark .chat-message .avatar { background-color: #475569; }
        .chat-bubble { padding: 0.75rem 1rem; border-radius: 1rem; max-width: 75%; word-wrap: break-word; } 
        .chat-message.received .chat-bubble { background-color: #e2e8f0; color: #1e293b; border-bottom-left-radius: 0.25rem; }
        .chat-message.sent .chat-bubble { background-color: #2563eb; color: white; border-bottom-right-radius: 0.25rem; }
        .dark .chat-message.received .chat-bubble { background-color: #334155; color: #e2e8f0; }
        .dark .chat-message.sent .chat-bubble { background-color: #3b82f6; }
        
        /* Timeline Styles */
        .timeline { position: relative; padding-left: 1.5rem; }
        .timeline::before { content: ''; position: absolute; left: 6px; top: 0; bottom: 0; width: 2px; background-color: #cbd5e1; }
        .dark .timeline::before { background-color: #475569; }
        .timeline-item { position: relative; margin-bottom: 1.5rem; }
        .timeline-item::before { content: ''; position: absolute; left: -21px; top: 4px; width: 14px; height: 14px; background-color: #3b82f6; border-radius: 50%; border: 2px solid #fff; }
        .dark .timeline-item::before { border-color: #1e293b; background-color: #60a5fa; }
        .timeline-item-content { margin-left: 0.5rem; }

        /* Lightbox CSS */
        #lightbox { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.9); align-items: center; justify-content: center; }
        #lightbox img { max-width: 90%; max-height: 90%; border-radius: 4px; box-shadow: 0 0 20px rgba(0,0,0,0.5); animation: zoomIn 0.3s ease; }
        #lightbox-close { position: absolute; top: 20px; right: 30px; color: white; font-size: 40px; font-weight: bold; cursor: pointer; transition: 0.3s; }
        #lightbox-close:hover { color: #ccc; }
        @keyframes zoomIn { from {transform:scale(0.8); opacity:0;} to {transform:scale(1); opacity:1;} }

        /* Rating Stars */
        .rating-stars { display: inline-flex; flex-direction: row-reverse; }
        .rating-stars input[type="radio"] { display: none; }
        .rating-stars label { font-size: 2rem; color: #d1d5db; cursor: pointer; transition: color 0.2s; }
        .dark .rating-stars label { color: #4b5563; }
        .rating-stars input[type="radio"]:checked ~ label, .rating-stars label:hover, .rating-stars label:hover ~ label { color: #f59e0b; }
        .dark .rating-stars input[type="radio"]:checked ~ label, .dark .rating-stars label:hover, .dark .rating-stars label:hover ~ label { color: #facc15; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 dark:bg-slate-900 dark:text-gray-300 transition-colors duration-300">

    <canvas id="digital-particles"></canvas>
    
    <div id="lightbox" onclick="closeLightbox()">
        <span id="lightbox-close">&times;</span>
        <img id="lightbox-img" src="" alt="Fullscreen Image">
    </div>

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

            <div class="hidden bg-blue-500 bg-yellow-500 bg-green-500 bg-red-500"></div>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 space-y-8">
                
                    <?php if (($ticket['status'] === 'completed' || $ticket['status'] === 'rejected') && $u['id'] == $ticket['user_id'] && !$existingFeedback): ?>
                        <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-blue-500 dark:border-blue-700/50">
                            <h5 class="text-xl font-semibold text-gray-700 dark:text-gray-200 mb-2"><?= __t('rate_your_experience') ?></h5>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4"><?= __t('rate_experience_prompt') ?></p>
                            <form method="post" action="<?= base_url('api/submit_feedback.php') ?>" class="space-y-4">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
                                <div class="text-center"><div class="rating-stars">
                                    <input type="radio" id="star5" name="rating" value="5" required><label for="star5" title="5 stars">&#9733;</label>
                                    <input type="radio" id="star4" name="rating" value="4"><label for="star4" title="4 stars">&#9733;</label>
                                    <input type="radio" id="star3" name="rating" value="3"><label for="star3" title="3 stars">&#9733;</label>
                                    <input type="radio" id="star2" name="rating" value="2"><label for="star2" title="2 stars">&#9733;</label>
                                    <input type="radio" id="star1" name="rating" value="1"><label for="star1" title="1 star">&#9733;</label>
                                </div></div>
                                <div>
                                    <label for="comment" class="sr-only"><?= __t('comments') ?></label>
                                    <textarea id="comment" name="comment" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm" placeholder="<?= __t('leave_a_comment_optional') ?>..."></textarea>
                                </div>
                                <div class="flex justify-end">
                                     <button type="submit" class="px-5 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                                        <?= __t('submit_feedback') ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php elseif ($existingFeedback): ?>
                        <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-green-500 dark:border-green-700/50">
                            <h5 class="text-xl font-semibold text-green-700 dark:text-green-400 mb-2"><?= __t('feedback_received') ?></h5>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3"><?= __t('thank_you_for_your_feedback') ?></p>
                            <div class="rating-stars mb-2" title="<?= e($existingFeedback['rating']) ?> stars">
                                <?php for ($i=5; $i >= 1; $i--): ?>
                                    <input type="radio" id="star_disp_<?= $i ?>" disabled <?= (int)$existingFeedback['rating'] == $i ? 'checked' : '' ?>><label for="star_disp_<?= $i ?>">&#9733;</label>
                                <?php endfor; ?>
                            </div>
                             <?php if(!empty($existingFeedback['comment'])): ?>
                             <blockquote class="border-l-4 border-gray-200 dark:border-slate-600 pl-4 text-sm italic text-gray-600 dark:text-gray-300">
                                 <?= e($existingFeedback['comment']) ?>
                             </blockquote>
                             <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50">
                        
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4">
                            <div class="flex items-center gap-3 mb-2 sm:mb-0">
                                <h5 class="text-xl font-semibold text-gray-700 dark:text-gray-200">#<?= (int)$ticket['id'] ?> - <?= e($ticket['title']) ?></h5>
                                
                                <?php if($canEditTicket): ?>
                                    <a href="ticket_edit.php?id=<?= (int)$ticket['id'] ?>" class="text-gray-400 hover:text-blue-600 transition-colors" title="<?= __t('edit') ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                            
                            <?php $statusCls = match($ticket['status']) { 'received' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300', 'in_progress' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300', 'completed' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300', 'rejected' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300', default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' }; $priorityCls = match($ticket['priority']) { 'Low' => 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400', 'Normal' => 'border-blue-300 dark:border-blue-700 text-blue-600 dark:text-blue-400', 'High' => 'border-yellow-300 dark:border-yellow-700 text-yellow-600 dark:text-yellow-400', 'Urgent' => 'border-red-300 dark:border-red-700 text-red-600 dark:text-red-400', default => 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400' }; ?>
                            <span class="px-3 py-1 text-sm font-medium rounded-full <?= $statusCls ?>"><?= e(__t($ticket['status'])) ?></span>
                        </div>

                        <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-500 dark:text-gray-400 mb-4">
                            <span><?= __t('type') ?>: <strong class="text-gray-700 dark:text-gray-300"><?= e($ticket['type']) ?></strong></span>
                            <span><?= __t('priority') ?>: <strong class="border px-1.5 rounded <?= $priorityCls ?>"><?= e($ticket['priority']) ?></strong></span>
                            <span><?= __t('created_at') ?>: <strong class="text-gray-700 dark:text-gray-300"><?= e(date('d M Y, H:i', strtotime($ticket['created_at']))) ?></strong></span>
                        </div>
                        <p class="text-gray-700 dark:text-gray-300 whitespace-pre-wrap"><?= nl2br(e($ticket['description'])) ?></p>
                        
                        <?php if ($attachments): ?>
                        <div class="mt-6 border-t border-gray-200 dark:border-slate-700 pt-4">
                            <h6 class="text-sm font-semibold text-gray-600 dark:text-gray-300 mb-3"><?= __t('attachments') ?></h6>
                            
                            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                                <?php foreach($attachments as $a): 
                                    $downloadUrl = base_url('download.php?id=' . $a['id']);
                                    $ext = strtolower(pathinfo($a['filename'], PATHINFO_EXTENSION));
                                    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                                ?>
                                    <?php if ($isImage): ?>
                                        <div class="group relative aspect-square bg-gray-100 dark:bg-slate-700 rounded-lg overflow-hidden border border-gray-200 dark:border-slate-600 cursor-pointer hover:shadow-md transition-all"
                                             onclick="openLightbox('<?= $downloadUrl ?>', '<?= e($a['filename']) ?>')">
                                            <img src="<?= $downloadUrl ?>" alt="<?= e($a['filename']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                                            <div class="absolute bottom-0 left-0 right-0 bg-black/60 p-1 text-center opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                                                <span class="text-xs text-white block truncate"><?= e($a['filename']) ?></span>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <a href="<?= $downloadUrl ?>" target="_blank" title="<?= e($a['filename']) ?>" class="flex flex-col items-center justify-center aspect-square bg-gray-50 dark:bg-slate-700 rounded-lg border border-gray-200 dark:border-slate-600 hover:bg-blue-50 dark:hover:bg-slate-600 transition-colors p-2 text-center group">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-gray-400 group-hover:text-blue-500 transition-colors mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                                            <span class="text-xs text-gray-600 dark:text-gray-300 w-full truncate px-1"><?= e($a['filename']) ?></span>
                                            <span class="text-[10px] text-gray-400 mt-1 uppercase"><?= e($ext) ?></span>
                                        </a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50">
                        <h6 class="text-base font-semibold text-gray-600 dark:text-gray-300 mb-4"><?= __t('chat') ?></h6>
                         <div class="chat-box mb-4 border-t border-b border-gray-200 dark:border-slate-700 py-4 px-2" id="chatBox">
                            <?php if(empty($messages)): ?><p class="text-center text-gray-500 dark:text-gray-400 text-sm"><?= __t('no_messages_yet') ?></p><?php endif; ?>
                            <?php $lastSenderId = null; ?> 
                            <?php foreach($messages as $m): ?>
                                <?php $isSent = $m['sender_id'] == $currentUser['id']; $showAvatar = $m['sender_id'] != $lastSenderId; $avatarUrl = $m['avatar'] ? base_url($m['avatar']) : $defaultAvatar; ?>
                                <div class="chat-message <?= $isSent ? 'sent' : 'received' ?>" data-message-id="<?= (int)$m['id'] ?>"> 
                                    <?php if ($showAvatar): ?><img src="<?= e($avatarUrl) ?>?t=<?= time() ?>" onerror="this.onerror=null; this.src='<?= e($defaultAvatar) ?>';" alt="<?= e($m['name'] ?: 'User') ?>" class="avatar"><?php else: ?><div class="avatar invisible"></div><?php endif; ?>
                                    <div>
                                        <?php if ($showAvatar): ?><div class="text-xs mb-1 <?= $isSent ? 'text-right' : 'text-left' ?>"><strong class="text-gray-700 dark:text-gray-300"><?= e($m['name'] ?: 'User') ?></strong> <span class="text-gray-400 dark:text-gray-500"><?= time_ago($m['created_at'], '') ?></span></div><?php endif; ?>
                                        <div class="chat-bubble"><?= nl2br(e($m['body'])) ?></div>
                                    </div>
                                </div>
                                <?php $lastSenderId = $m['sender_id']; ?>
                            <?php endforeach; ?>
                            <div id="chat-poll-marker" data-last-id="<?= end($messages)['id'] ?? 0 ?>"></div>
                        </div>
                         <div class="flex items-center gap-2">
                            <input type="text" id="chatMsg" placeholder="<?= __t('type_your_message') ?>..." class="flex-grow px-4 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200">
                            <button class="flex-shrink-0 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800" id="chatSend"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg><span class="ml-1 hidden sm:inline"><?= __t('send') ?></span></button>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-1 space-y-8">
                    <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50">
                         <h6 class="text-base font-semibold text-gray-600 dark:text-gray-300 mb-3"><?= __t('ticket_information') ?></h6>
                         <div class="mb-4">
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1"><?= __t('requester') ?></label>
                            <div class="flex items-center gap-2">
                                <img src="<?= e($ticket['user_avatar'] ? base_url($ticket['user_avatar']) : $defaultAvatar) ?>?t=<?= time() ?>" 
                                     onerror="this.onerror=null; this.src='<?= e($defaultAvatar) ?>';"
                                     alt="<?= e($ticket['user_name']) ?>" 
                                     class="w-8 h-8 rounded-full object-cover bg-gray-200 dark:bg-slate-700">
                                <span class="text-sm text-gray-800 dark:text-gray-200 font-medium"><?= e($ticket['user_name']) ?></span>
                            </div>
                            <span class="text-xs text-gray-500 dark:text-gray-400 block mt-1"><?= e($ticket['user_email']) ?> <?= $ticket['user_phone'] ? '| '.e($ticket['user_phone']) : '' ?></span>
                         </div>
                         <div class="mb-4">
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1"><?= __t('assigned_to') ?></label>
                            <?php if ($ticket['assigned_to']): ?>
                            <div class="flex items-center gap-2">
                                <img src="<?= e($ticket['assignee_avatar'] ? base_url($ticket['assignee_avatar']) : $defaultAvatar) ?>?t=<?= time() ?>" 
                                     onerror="this.onerror=null; this.src='<?= e($defaultAvatar) ?>';"
                                     alt="<?= e($ticket['assignee_name']) ?>" 
                                     class="w-8 h-8 rounded-full object-cover bg-gray-200 dark:bg-slate-700">
                                <span class="text-sm text-gray-800 dark:text-gray-200 font-medium"><?= e($ticket['assignee_name']) ?></span>
                            </div>
                            <?php else: ?>
                                <span class="text-sm text-gray-500 dark:text-gray-400 italic">- <?= __t('unassigned') ?> -</span>
                            <?php endif; ?>
                         </div>
                         <hr class="border-gray-200 dark:border-slate-700 my-4">
                         <h6 class="text-base font-semibold text-gray-600 dark:text-gray-300 mb-2"><?= __t('update_status') ?></h6>
                        <?php if ($canEditStatus): ?>

                            <form method="post" action="<?= base_url('api/ticket_status.php') ?>" class="space-y-3">
                                <?php csrf_field(); ?> <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">

                                <div>
                                    <label for="solution" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1"><?= __t('solution_notes') ?></label>
                                    <textarea name="solution" id="solution" rows="4" 
                                              class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:text-gray-200" 
                                              placeholder="<?= __t('enter_solution_notes_here') ?>..."><?= e($ticket['solution'] ?? '') ?></textarea>
                                </div>

                                <div class="flex items-center gap-2">
                                    <select name="status" class="flex-grow px-3 py-2 text-sm border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:text-gray-200">
                                        <option value="received" <?= $ticket['status']==='received'?'selected':'' ?>><?= __t('received') ?></option><option value="in_progress" <?= $ticket['status']==='in_progress'?'selected':'' ?>><?= __t('in_progress') ?></option><option value="completed" <?= $ticket['status']==='completed'?'selected':'' ?>><?= __t('completed') ?></option><option value="rejected" <?= $ticket['status']==='rejected'?'selected':'' ?>><?= __t('rejected') ?></option>
                                    </select>
                                    <button type="submit" class="flex-shrink-0 px-3 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800"> <?= __t('save') ?> </button>
                                </div>
                            </form>

                        <?php else: ?>
                            <div class="text-sm text-gray-500 dark:text-gray-400 italic"> <?= __t('status_update_permission_info') ?> </div>
                            <?php if(($u['role'] ?? '')==='technical' && !$ticket['assigned_to']): ?>
                                <form method="post" action="<?= base_url('api/ticket_assign_self.php') ?>" class="mt-2">
                                    <?php csrf_field(); ?> <input type="hidden" name="id" value="<?= (int)$ticket['id'] ?>">
                                    <button class="w-full px-3 py-2 text-sm font-medium text-blue-600 border border-blue-600 rounded-lg hover:bg-blue-50 dark:text-blue-400 dark:border-blue-400 dark:hover:bg-blue-900/50 focus:outline-none focus:ring-1 focus:ring-blue-500"> <?= __t('assign_to_me') ?> </button>
                                </form>
                            <?php endif; ?>
                         <?php endif; ?>
                    </div>

                    <?php
                        // Explicitly define prog and color
                        $current_status = trim($ticket['status'] ?? 'received'); 
                        $prog = 10; $progColor = 'bg-blue-500'; 

                        if ($current_status === 'in_progress') { $prog = 50; $progColor = 'bg-yellow-500'; } 
                        elseif ($current_status === 'completed') { $prog = 100; $progColor = 'bg-green-500'; } 
                        elseif ($current_status === 'rejected') { $prog = 100; $progColor = 'bg-red-500'; }
                    ?>
                    <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50">
                        <h6 class="text-base font-semibold text-gray-600 dark:text-gray-300 mb-3"><?= __t('progress') ?></h6>
                        <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-slate-700">
                            <div class="<?= $progColor ?> h-2.5 rounded-full transition-all duration-500" style="width: <?= (int)$prog ?>%"></div>
                        </div>
                         <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400 mt-1"><span><?= __t('received') ?></span><span><?= __t('completed') ?></span></div>
                    </div>

                    <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50">
                        <h6 class="text-base font-semibold text-gray-600 dark:text-gray-300 mb-4"><?= __t('activity_log') ?></h6>
                        <div class="timeline">
                            <?php if(empty($activity)): ?><p class="text-gray-500 dark:text-gray-400 text-sm"><?= __t('no_activity_yet') ?></p><?php endif; ?>
                            <?php foreach($activity as $a): 
                                $actor_avatar_url = $a['actor_avatar'] ? base_url($a['actor_avatar']) : $defaultAvatar;
                            ?>
                            <div class="timeline-item">
                                <div class="timeline-item-content">
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200"><?= e(ucfirst(str_replace('_', ' ', $a['action']))) ?></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1 flex items-center">
                                        <img src="<?= e($actor_avatar_url) ?>?t=<?= time() ?>" 
                                             onerror="this.onerror=null; this.src='<?= e($defaultAvatar) ?>';"
                                             alt="<?= e($a['actor'] ?: 'System') ?>" 
                                             class="w-6 h-6 rounded-full object-cover bg-gray-200 dark:bg-slate-700 inline-block align-middle mr-2">
                                        <span>
                                            <?= time_ago($a['created_at']) ?> <?= __t('by') ?> 
                                            <strong><?= e($a['actor'] ?: 'System') ?></strong>
                                        </span>
                                    </p>
                                    <?php if(!empty($a['meta'])){ $meta=json_decode($a['meta'],true); if($meta && is_array($meta)){ echo '<div class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-slate-700 p-2 rounded">'; foreach ($meta as $key => $value) { if ($key === 'status') $value = __t($value); echo '<strong>' . e(ucfirst($key)) . ':</strong> ' . e(is_array($value) ? json_encode($value) : $value) . '<br>'; } echo '</div>'; }} ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    
        <footer class="bg-white dark:bg-slate-800/80 mt-12 py-4 border-t border-gray-200 dark:border-transparent">
             <div class="container mx-auto px-4 text-center text-gray-500 dark:text-gray-400 text-sm">
                 © <?= date('Y') ?> - <?= e($appName) ?> / <?= e(__t('Digital System Management Department')) ?>
             </div>
         </footer>
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
    
    <script>
        // --- Lightbox Script ---
        function openLightbox(src, alt) {
            const lightbox = document.getElementById('lightbox');
            const lightboxImg = document.getElementById('lightbox-img');
            lightboxImg.src = src;
            lightboxImg.alt = alt || 'Attachment';
            lightbox.style.display = "flex";
        }

        function closeLightbox() {
            document.getElementById('lightbox').style.display = "none";
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === "Escape") {
                closeLightbox();
            }
        });

        $(document).ready(function() {
            const chatBox = $('#chatBox');
            chatBox.scrollTop(chatBox[0].scrollHeight);
            
            function addMessageToChat(htmlContent) {
                const noMessagesP = chatBox.find('p:contains("<?= __t('no_messages_yet') ?>")'); 
                if (noMessagesP.length) { noMessagesP.remove(); }
                chatBox.append(htmlContent);
                if (chatBox[0].scrollHeight - chatBox.scrollTop() - chatBox.outerHeight() < 100) {
                     chatBox.animate({ scrollTop: chatBox[0].scrollHeight }, 300); 
                }
            }

            $('#chatSend').on('click', function(){ 
                const body = $('#chatMsg').val().trim(); 
                if(!body) return; 
                $(this).prop('disabled', true); $('#chatMsg').prop('disabled', true);
                $.post('<?= base_url('api/chat_send.php') ?>',{ _csrf: $('input[name="_csrf"]').val(), id: <?= (int)$ticket['id'] ?>, body: body }, function(response){ 
                    if(response && response.trim() !== '') {
                        $('#chatMsg').val(''); 
                        const tempDiv = $('<div>').html(response); 
                        const lastSentMsg = tempDiv.children().last();
                        if (lastSentMsg.data('message-id')) {
                            $('#chat-poll-marker').data('last-id', lastSentMsg.data('message-id'));
                        }
                        tempDiv.remove(); 
                        pollMessages(true); 
                    } else { console.error("Send Error"); alert("<?= __t('error_sending_message') ?>"); }
                }).fail(function() { console.error("AJAX Error"); alert("<?= __t('error_sending_message') ?>"); 
                }).always(function() { $('#chatSend').prop('disabled', false); $('#chatMsg').prop('disabled', false); $('#chatMsg').focus(); }); 
            });

            $('#chatMsg').on('keypress', function(e) { if (e.which === 13 && !e.shiftKey) { e.preventDefault(); $('#chatSend').click(); } });

            let pollTimeout; 
            let isPolling = false; 
            function pollMessages(force = false) { 
                clearTimeout(pollTimeout); 
                if (isPolling && !force) return; 
                isPolling = true;

                const lastId = $('#chat-poll-marker').data('last-id') || 0;
                let ajaxData = { id: <?= (int)$ticket['id'] ?> };
                
                if (lastId > 0) {
                    ajaxData.since = lastId;
                }
                
                $.get('<?= base_url('api/chat_poll.php') ?>', ajaxData) 
                 .done(function(response){ 
                    if(response && response.trim() !== ''){ 
                        addMessageToChat(response);
                        const tempDiv = $('<div>').html(response); 
                        const lastNewMsg = tempDiv.children().last(); 
                        if (lastNewMsg.length && lastNewMsg.data('message-id')) { 
                            $('#chat-poll-marker').data('last-id', lastNewMsg.data('message-id'));
                        }
                        tempDiv.remove(); 
                    } 
                 })
                 .fail(function() { console.error("Polling request failed."); })
                 .always(function() {
                     isPolling = false; 
                     pollTimeout = setTimeout(pollMessages, 5000); 
                 });
            }
            pollTimeout = setTimeout(pollMessages, 5000); 
        });
    </script>
    
    <script>
    // ***** Notification Mark as Read Logic *****
    let notificationsMarked = false; // prevent duplicate calls
    function markNotificationsAsRead() {
        const badge = document.getElementById('notification-badge');
        if (badge && !notificationsMarked) {
            notificationsMarked = true;
            if (window.$ && $.post) {
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
                fetch('<?= base_url('api/mark_notifications_read.php') ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: '_csrf=<?= e(csrf_token()) ?>'
                }).then(r => r.json()).then(function(response){
                    try { if (response.success) { badge.classList.add('animate-ping', 'opacity-0'); setTimeout(() => badge.remove(), 500); } } catch(e){}
                }).catch(function(){ 
                    console.error('Failed to mark notifications as read.'); 
                    notificationsMarked = false; // Allow retry
                });
            }
        }
    }
    // ***** END Notification Logic *****
    </script>
</body>
</html>