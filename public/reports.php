<?php 
session_start(); 

// --- PHP Logic (Updated Data Table Queries) ---
require_once __DIR__ . '/../includes/functions.php'; 
require_once __DIR__ . '/../includes/auth.php'; 
require_once __DIR__ . '/../includes/csrf.php'; 
require_login(); 


// ***** Notification Logic (copied from dashboard.php) *****
$u = current_user(); // FIX 1: Get user info early
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$notifStmt->execute([$u['id']]);
$notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
$unreadCountStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadCountStmt->execute([$u['id']]);
$unreadCount = (int)$unreadCountStmt->fetchColumn();
// ***** END Notification Logic *****

// FIX 2: Check user's role (FIXED to include 'admin')
$isAdminOrCoord = is_role('admin') || is_role('coordinator');

// Get current filter values
$report_type = $_GET['report'] ?? 'tickets';
$limit = (int)($_GET['limit'] ?? 10);
if ($limit === 0) $limit = 999999; // Handle "All"
$page = (int)($_GET['page'] ?? 1);
$offset = ($page - 1) * $limit;
$date_from = trim($_GET['date_from'] ?? ''); // New date filter
$date_to = trim($_GET['date_to'] ?? ''); // New date filter
$filter_rating = trim($_GET['rating'] ?? ''); // <-- FEATURE 2: Added Rating Filter

// ***** FIX 3: Force non-admins to only see their own reports *****
if (!$isAdminOrCoord && !in_array($report_type, ['tickets', 'feedback'])) {
    $report_type = 'tickets'; // Default non-admins to 'tickets' report
}
// ***** END FIX *****


// --- Excel Export Logic (Updated with Date Filters & Staff Performance) ---
if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    $data_for_excel = [];
    $filename = "report_{$report_type}_" . date('Ymd_His') . ".xlsx";
    
    // Build WHERE clause for dates
    $where_sql = ""; 
    $where_params = [];
    $date_col_alias = 't.created_at'; // Default for tickets

    if ($report_type === 'kb') $date_col_alias = 'kb_articles.created_at';
    elseif ($report_type === 'users') $date_col_alias = 'users.created_at';
    elseif ($report_type === 'feedback') $date_col_alias = 'f.created_at';
    elseif ($report_type === 'staff_performance') $date_col_alias = 't.updated_at'; // Use updated_at for performance
    
    // ***** FIX 4: Add user filter to WHERE clause for Excel *****
    if (!$isAdminOrCoord) {
        // ===== START: TECHNICAL USER FIX (Excel) =====
        if ($report_type === 'tickets') {
            if ($u['role'] === 'technical') {
                $where_sql .= " AND t.assigned_to = ?"; // Techs see assigned tickets
            } else {
                $where_sql .= " AND t.user_id = ?"; // Users see created tickets
            }
            $where_params[] = $u['id'];
        } elseif ($report_type === 'feedback') {
            // ===== TECHNICAL USER FIX (Feedback Excel) =====
            if ($u['role'] === 'technical') {
                 // Techs see feedback on their assigned tickets
                $where_sql .= " AND f.ticket_id IN (SELECT id FROM tickets WHERE assigned_to = ?)";
            } else {
                // Users see their own feedback
                $where_sql .= " AND f.user_id = ?";
            }
            $where_params[] = $u['id'];
            // ===== END: TECHNICAL USER FIX (Feedback Excel) =====
        }
        // ===== END: TECHNICAL USER FIX (Excel) =====
    }
    // ***** END FIX *****
    
    if (!empty($date_from)) {
        $where_sql .= " AND DATE($date_col_alias) >= ?";
        $where_params[] = $date_from;
    }
    if (!empty($date_to)) {
        $where_sql .= " AND DATE($date_col_alias) <= ?";
        $where_params[] = $date_to;
    }

    // Set headers and query based on report type
    if ($report_type === 'tickets') {
        $data_for_excel[] = ['ID', 'Title', 'Status', 'Priority', 'Requester', 'Assigned To', 'Created At', 'Updated At'];
        $sql = "SELECT t.id, t.title, t.status, t.priority, u.name as requester_name, a.name as assignee_name, t.created_at, t.updated_at 
                FROM tickets t
                LEFT JOIN users u ON u.id = t.user_id
                LEFT JOIN users a ON a.id = t.assigned_to
                WHERE 1=1 $where_sql
                ORDER BY t.id DESC";
    } elseif ($report_type === 'kb' && $isAdminOrCoord) { // Check role
        $data_for_excel[] = ['ID', 'Title (EN)', 'Title (KM)', 'Tags', 'Published', 'Created At'];
        $sql = "SELECT id, title_en, title_km, tags, IF(is_published, 'Yes', 'No') as published_status, created_at 
                FROM kb_articles 
                WHERE 1=1 $where_sql
                ORDER BY id DESC";
    } elseif ($report_type === 'users' && $isAdminOrCoord) { // Check role
        $data_for_excel[] = ['ID', 'Name', 'Email', 'Phone', 'Role', 'Created At'];
        $sql = "SELECT id, name, email, phone, role, created_at 
                FROM users 
                WHERE 1=1 $where_sql
                ORDER BY id DESC";
    } elseif ($report_type === 'feedback') {
        $data_for_excel[] = ['ID', 'Ticket ID', 'User', 'Rating', 'Comment', 'Created At'];
        
        // FEATURE 2: Add rating filter to Excel export
        if (!empty($filter_rating)) {
            $where_sql .= " AND f.rating = ?";
            $where_params[] = (int)$filter_rating;
        }

        $sql = "SELECT f.id, f.ticket_id, u.name as user_name, f.rating, f.comment, f.created_at
                FROM feedback f
                LEFT JOIN users u ON u.id = f.user_id
                WHERE 1=1 $where_sql
                ORDER BY f.id DESC";
    } elseif ($report_type === 'staff_performance' && $isAdminOrCoord) { // Check role
        $data_for_excel[] = ['Name', 'Avg Resolution Time (Seconds)', 'Tickets Completed'];
         $sql = "SELECT a.name, AVG(TIMESTAMPDIFF(SECOND, t.created_at, t.updated_at)) avg_seconds, SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) completed 
                 FROM tickets t 
                 LEFT JOIN users a ON a.id=t.assigned_to 
                 WHERE t.assigned_to IS NOT NULL AND t.status IN ('completed', 'rejected') $where_sql 
                 GROUP BY a.id, a.name 
                 ORDER BY completed DESC, avg_seconds ASC";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($where_params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add data rows to excel array
    foreach ($data as $row) {
        $data_for_excel[] = array_values($row);
    }

    // Use SimpleXLSXGen
    if (class_exists('Shuchkin\SimpleXLSXGen')) {
        Shuchkin\SimpleXLSXGen::fromArray($data_for_excel)->downloadAs($filename);
    } else {
        die("SimpleXLSXGen library not found. Please run 'composer install'.");
    }
    exit;
}
// --- End Excel Export Logic ---


// --- Chart & Stat Logic (Filtered for Users) ---
$user_ticket_filter_sql = "1=1";
$user_ticket_filter_params = [];

// ===== START: TECHNICAL USER FIX (Charts) =====
if (!$isAdminOrCoord) {
    if ($u['role'] === 'technical') {
        $user_ticket_filter_sql = "assigned_to = ?"; // Check tickets ASSIGNED to tech
        $user_ticket_filter_params = [$u['id']];
    } else {
        $user_ticket_filter_sql = "user_id = ?"; // Check tickets CREATED by user
        $user_ticket_filter_params = [$u['id']];
    }
}
// ===== END: TECHNICAL USER FIX (Charts) =====

$typesStmt = $pdo->prepare("SELECT type, COUNT(*) c FROM tickets WHERE $user_ticket_filter_sql GROUP BY type ORDER BY c DESC");
$typesStmt->execute($user_ticket_filter_params);
$types = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

$typeLabels = array_map(function($item) { return __t(strtolower(e($item['type']))); }, $types); // <-- បន្ថែម e()
$typeData = array_column($types, 'c');

// Staff performance query (Only run for admins)
$perf = [];
if ($isAdminOrCoord) {
    $perf=$pdo->query("SELECT a.name, AVG(TIMESTAMPDIFF(SECOND, t.created_at, t.updated_at)) avg_seconds, SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) completed FROM tickets t LEFT JOIN users a ON a.id=t.assigned_to WHERE t.assigned_to IS NOT NULL AND t.status IN ('completed', 'rejected') GROUP BY a.id,a.name ORDER BY completed DESC, avg_seconds ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// ===== START: FEATURE 1 (My Performance Box) =====
$myStats = [
    'avg_seconds' => null,
    'completed' => 0,
    'total' => 0
];
if (!$isAdminOrCoord) {
    if ($u['role'] === 'technical') {
        $myStatsStmt = $pdo->prepare("SELECT 
                                        AVG(CASE WHEN t.status IN ('completed', 'rejected') THEN TIMESTAMPDIFF(SECOND, t.created_at, t.updated_at) ELSE NULL END) as avg_seconds, 
                                        SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed,
                                        COUNT(t.id) as total
                                    FROM tickets t 
                                    WHERE t.assigned_to = ?");
        $myStatsStmt->execute([$u['id']]);
        $myStats = $myStatsStmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Stats for a regular user
        $myStatsStmt = $pdo->prepare("SELECT 
                                        COUNT(t.id) as total,
                                        SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed
                                    FROM tickets t 
                                    WHERE t.user_id = ?");
        $myStatsStmt->execute([$u['id']]);
        $myStats = $myStatsStmt->fetch(PDO::FETCH_ASSOC);
    }
}
// ===== END: FEATURE 1 (My Performance Box) =====


// ===== START: TECHNICAL USER FIX (Feedback Query 1) =====
$feedbackStatsStmt_sql = "SELECT AVG(rating) as avg_rating, COUNT(*) as total_ratings FROM feedback";
$feedbackStatsStmt_params = [];
if (!$isAdminOrCoord) {
    if ($u['role'] === 'technical') {
        // Techs see feedback on their assigned tickets
        $feedbackStatsStmt_sql = "SELECT AVG(f.rating) as avg_rating, COUNT(f.id) as total_ratings 
                                  FROM feedback f 
                                  JOIN tickets t ON f.ticket_id = t.id 
                                  WHERE t.assigned_to = ?";
        $feedbackStatsStmt_params = [$u['id']];
    } else {
        // Users see their own feedback
        $feedbackStatsStmt_sql = "SELECT AVG(rating) as avg_rating, COUNT(*) as total_ratings 
                                  FROM feedback 
                                  WHERE user_id = ?";
        $feedbackStatsStmt_params = [$u['id']];
    }
}
$feedbackStatsStmt = $pdo->prepare($feedbackStatsStmt_sql);
$feedbackStatsStmt->execute($feedbackStatsStmt_params);
$feedbackStats = $feedbackStatsStmt->fetch(PDO::FETCH_ASSOC);
// ===== END: TECHNICAL USER FIX (Feedback Query 1) =====


$avgRating = $feedbackStats['avg_rating'] ? round($feedbackStats['avg_rating'], 2) : 0;
$totalRatings = (int)$feedbackStats['total_ratings'];


// ===== START: TECHNICAL USER FIX (Feedback Query 2) =====
$ratingDistStmt_sql = "SELECT rating, COUNT(*) as count FROM feedback GROUP BY rating ORDER BY rating DESC";
$ratingDistStmt_params = [];
if (!$isAdminOrCoord) {
    if ($u['role'] === 'technical') {
        // Techs see feedback on their assigned tickets
        $ratingDistStmt_sql = "SELECT f.rating, COUNT(f.id) as count 
                               FROM feedback f 
                               JOIN tickets t ON f.ticket_id = t.id 
                               WHERE t.assigned_to = ? 
                               GROUP BY f.rating 
                               ORDER BY f.rating DESC";
        $ratingDistStmt_params = [$u['id']];
    } else {
        // Users see their own feedback
        $ratingDistStmt_sql = "SELECT rating, COUNT(*) as count 
                               FROM feedback 
                               WHERE user_id = ? 
                               GROUP BY rating 
                               ORDER BY rating DESC";
        $ratingDistStmt_params = [$u['id']];
    }
}
$ratingDistStmt = $pdo->prepare($ratingDistStmt_sql);
$ratingDistStmt->execute($ratingDistStmt_params);
$ratingDist = $ratingDistStmt->fetchAll(PDO::FETCH_ASSOC);
// ===== END: TECHNICAL USER FIX (Feedback Query 2) =====

$ratingLabels = array_map(function($item) { return $item['rating'] . ' ' . __t('stars'); }, $ratingDist);
$ratingCounts = array_column($ratingDist, 'count');
$ratingColors = ['5' => 'rgba(34, 197, 94, 0.7)', '4' => 'rgba(163, 230, 53, 0.7)', '3' => 'rgba(234, 179, 8, 0.7)', '2' => 'rgba(249, 115, 22, 0.7)', '1' => 'rgba(239, 68, 68, 0.7)'];
$ratingChartColors = array_map(function($item) use ($ratingColors) { return $ratingColors[(string)$item['rating']] ?? '#9ca3af'; }, $ratingDist);
if (!function_exists('format_seconds_to_readable')) { function format_seconds_to_readable($seconds) { if ($seconds < 0) $seconds = 0; if ($seconds < 60) return round($seconds) . 's'; $minutes = floor($seconds / 60); if ($minutes < 60) return $minutes . 'm ' . ($seconds % 60) . 's'; $hours = floor($minutes / 60); $remainingMinutes = $minutes % 60; if ($hours < 24) return $hours . 'h ' . $remainingMinutes . 'm'; $days = floor($hours / 24); $remainingHours = $hours % 24; return $days . 'd ' . $remainingHours . 'h'; } }
// --- End Chart & Stat Logic ---


// --- Updated Data Table Logic (With Date Filters) ---
$table_data = [];
$table_headers = [];
$total_rows = 0;
$table_sql = "";
$count_sql = "";
$table_params = []; // Params for WHERE clause only
$base_where_sql = ""; 
$date_col_alias = 't.created_at'; // Default for tickets

// ============= FIX START (LIMIT/OFFSET) =============
// Create a dynamic string for the LIMIT clause
$limit_sql = ($limit === 999999) ? "" : "LIMIT $limit OFFSET $offset";
// ============= FIX END =============

// Determine alias for date filtering
if ($report_type === 'kb') $date_col_alias = 'kb_articles.created_at';
elseif ($report_type === 'users') $date_col_alias = 'users.created_at';
elseif ($report_type === 'feedback') $date_col_alias = 'f.created_at';
elseif ($report_type === 'staff_performance') $date_col_alias = 't.updated_at';

// ===== START: TECHNICAL USER FIX (Table) =====
if (!$isAdminOrCoord) {
    if ($report_type === 'tickets') {
        if ($u['role'] === 'technical') {
             $base_where_sql .= " AND t.assigned_to = ?"; // Check tickets ASSIGNED to tech
        } else {
             $base_where_sql .= " AND t.user_id = ?"; // Check tickets CREATED by user
        }
        $table_params[] = $u['id'];

    } elseif ($report_type === 'feedback') {
        // ===== TECHNICAL USER FIX (Feedback Table) =====
        if ($u['role'] === 'technical') {
             // Techs see feedback on their assigned tickets
            $base_where_sql .= " AND f.ticket_id IN (SELECT id FROM tickets WHERE assigned_to = ?)";
        } else {
            // Users see their own feedback
            $base_where_sql .= " AND f.user_id = ?";
        }
        $table_params[] = $u['id'];
        // ===== END: TECHNICAL USER FIX (Feedback Table) =====
    }
}
// ===== END: TECHNICAL USER FIX (Table) =====

// Add date filters to params
if (!empty($date_from)) {
    $base_where_sql .= " AND DATE($date_col_alias) >= ?";
    $table_params[] = $date_from;
}
if (!empty($date_to)) {
    $base_where_sql .= " AND DATE($date_col_alias) <= ?";
    $table_params[] = $date_to;
}

// ===== START: FEATURE 2 (Add Rating Filter to SQL) =====
if ($report_type === 'feedback' && !empty($filter_rating)) {
    $base_where_sql .= " AND f.rating = ?";
    $table_params[] = (int)$filter_rating;
}
// ===== END: FEATURE 2 =====


// ***** BUG FIX: Switched all queries to use Positional Placeholders (?) *****
switch ($report_type) {
    case 'kb':
        if (!$isAdminOrCoord) break; 
        $table_headers = ['ID', __t('title_en'), __t('title_km'), __t('tags'), __t('status'), __t('actions')]; // ADDED ACTIONS
        $base_sql = "FROM kb_articles WHERE 1=1 $base_where_sql"; 
        $count_sql = "SELECT COUNT(*) $base_sql";
        $table_sql = "SELECT id, title_en, title_km, tags, IF(is_published, 'Published', 'Draft') as status $base_sql ORDER BY id DESC $limit_sql";
        break;
    case 'users':
        if (!$isAdminOrCoord) break; 
        $table_headers = ['ID', __t('name'), __t('email'), __t('phone'), __t('role'), __t('created_at'), __t('actions')]; // ADDED ACTIONS
        $base_sql = "FROM users WHERE 1=1 $base_where_sql"; 
        $count_sql = "SELECT COUNT(*) $base_sql";
        $table_sql = "SELECT id, name, email, phone, role, created_at $base_sql ORDER BY id DESC $limit_sql";
        break;
    case 'feedback': 
        $table_headers = ['ID', __t('ticket_id'), __t('user'), __t('rating'), __t('comment'), __t('created_at')]; // No actions for feedback
        $base_sql = "FROM feedback f LEFT JOIN users u ON u.id = f.user_id WHERE 1=1 $base_where_sql"; 
        $count_sql = "SELECT COUNT(*) $base_sql";
        $table_sql = "SELECT f.id, f.ticket_id, u.name as user_name, f.rating, f.comment, f.created_at 
                      $base_sql 
                      ORDER BY f.id DESC $limit_sql";
        break;
    case 'staff_performance': 
        if (!$isAdminOrCoord) break; 
        $table_headers = ['ID', __t('name'), __t('avg_resolution_time'), __t('tickets_completed')]; // No actions 
        $base_sql = "FROM tickets t LEFT JOIN users a ON a.id=t.assigned_to 
                     WHERE t.assigned_to IS NOT NULL AND t.status IN ('completed', 'rejected') $base_where_sql";
        $count_sql = "SELECT COUNT(DISTINCT a.id) $base_sql";
        $table_sql = "SELECT a.id, a.name, AVG(TIMESTAMPDIFF(SECOND, t.created_at, t.updated_at)) avg_seconds, SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) completed 
                      $base_sql 
                      GROUP BY a.id, a.name 
                      ORDER BY completed DESC, avg_seconds ASC 
                      $limit_sql";
        break;
    case 'tickets':
    default:
        $table_headers = ['ID', __t('title'), __t('status'), __t('priority'), __t('requester'), __t('assigned_to'), __t('updated_at'), __t('actions')]; // ADDED ACTIONS
        $base_sql = "FROM tickets t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN users a ON a.id = t.assigned_to WHERE 1=1 $base_where_sql"; 
        $count_sql = "SELECT COUNT(*) $base_sql";
        $table_sql = "SELECT t.id, t.title, t.status, t.priority, u.name as requester_name, a.name as assignee_name, t.updated_at 
                      $base_sql 
                      ORDER BY t.id DESC $limit_sql";
        break;
}

// Fetch total rows for pagination
$countStmt = $pdo->prepare($count_sql);
$countStmt->execute($table_params); 
$total_rows = (int)$countStmt->fetchColumn();
$total_pages = ($limit === 999999) ? 1 : ceil($total_rows / $limit);

// Fetch table data
$stmt = $pdo->prepare($table_sql);

// ***** BUG FIX: Corrected parameter binding (REMOVED limit/offset params) *****
// $table_params already contains WHERE params. $limit_sql is now part of the SQL string.
// ***** END BUG FIX *****

// 2. Execute with the full array
try {
    $stmt->execute($table_params);
    $table_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Report Table Query Error: " . $e->getMessage());
    $table_data = []; 
}
// ***** END BUG FIX *****
// --- End Data Table Logic ---

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
    <title><?= e($appName) ?> - <?= __t('reports') ?></title>
	<link rel="icon" href="<?= base_url('assets/img/logo_32x32.png') ?>">
    
    <script> /* Theme Loader */ if(localStorage.theme==='dark'||(!('theme' in localStorage)&&window.matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark')}else{document.documentElement.classList.remove('dark')} </script>
    
    <script src="<?= base_url('assets/js/tailwind.js') ?>"></script>
    <script> tailwind.config={darkMode:'class'} </script>
    
    <script src="https://unpkg.com/lucide-react@latest/dist/lucide-react.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script> 
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script> 
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;700&display=swap');
        body { font-family: 'Kantumruy Pro', sans-serif; }
        /* Digital Background Style */
        #digital-particles { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
        ::-webkit-scrollbar { width: 6px; } ::-webkit-scrollbar-track { background: #f1f5f9; } ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 10px; } ::-webkit-scrollbar-thumb:hover { background: #475569; }
        .dark ::-webkit-scrollbar-track { background: #1e293b; } .dark ::-webkit-scrollbar-thumb { background: #334155; } .dark ::-webkit-scrollbar-thumb:hover { background: #475569; }
        /* Responsive Table Styles */
        @media (max-width: 768px) {
            .table-responsive thead { display: none; }
            .table-responsive tr { display: block; margin-bottom: 1rem; border-bottom: 2px solid #e5e7eb; padding-bottom: 0.5rem; }
            .dark .table-responsive tr { border-bottom-color: #374151; }
            .table-responsive td { display: block; text-align: right; padding-left: 50%; position: relative; padding-top: 0.5rem; padding-bottom: 0.5rem; }
            .table-responsive td::before { content: attr(data-label); position: absolute; left: 0.5rem; width: calc(50% - 1rem); padding-right: 0.5rem; white-space: nowrap; text-align: left; font-weight: bold; color: #4b5563; }
             .dark .table-responsive td::before { color: #9ca3af; }
        }
        /* Star Rating Display CSS */
        .star-rating-display { display: inline-flex; color: #d1d5db; position: relative; } 
        .dark .star-rating-display { color: #4b5563; }
        .star-rating-display .star-fill { color: #f59e0b; display: block; overflow: hidden; white-space: nowrap; position: absolute; top: 0; left: 0; height: 100%; z-index: 1; }
         .dark .star-rating-display .star-fill { color: #facc15; }
         .star-rating-display .star-base { position: relative; z-index: 0; }
         /* FEATURE 2: Make Chart Clickable */
         #chartRatings { cursor: pointer; }
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
                        <a href="<?= base_url('reports.php') ?>" class="text-white font-semibold bg-blue-600 px-3 py-1.5 rounded-lg text-sm"><?= __t('reports') ?></a>
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
                <a href="<?= base_url('kb.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('knowledge_base') ?></a>
                <a href="<?= base_url('reports.php') ?>" class="block text-white font-semibold bg-blue-600 px-3 py-2 rounded-lg text-sm"><?= __t('reports') ?></a>
                <?php if (is_role('admin')): ?>
                <a href="<?= base_url('admin/users.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('users') ?></a>
                <a href="<?= base_url('admin/settings.php') ?>" class="block text-gray-600 hover:text-black dark:text-gray-300 dark:hover:text-white px-3 py-2 rounded-lg transition-colors duration-200 text-sm"><?= __t('settings') ?></a>
                <?php endif; ?>
            </div>
        </header>

        <main class="flex-grow container mx-auto px-4 py-8 mt-8">
            <h1 class="text-2xl font-semibold text-gray-800 dark:text-white mb-6"><?= __t('reports') ?></h1>
            
            <?php if ($isAdminOrCoord): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mb-8">
			    <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 min-h-[300px]">
                    <h5 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-4"><?= __t('reports') ?> - <?= __t('by_type') ?></h5>
                    <div class="h-64"> 
                        <canvas id="chartType"></canvas>
                    </div>
                </div>

                 <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 min-h-[300px]">
                     <h5 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-4"><?= __t('rating_distribution') ?></h5>
                     <div class="h-64 flex justify-center"> 
                        <canvas id="chartRatings"></canvas>
                    </div>
                 </div>
                  <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 flex flex-col items-center justify-center min-h-[300px]">
                    <h5 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-2"><?= __t('overall_satisfaction') ?></h5>
                    <?php if ($totalRatings > 0): ?>
                        <div class="text-5xl font-bold text-gray-900 dark:text-white"><?= e($avgRating) ?> <span class="text-3xl text-gray-500 dark:text-gray-400">/ 5</span></div>
                        <div class="star-rating-display text-4xl mt-2 relative" title="<?= e($avgRating) ?>/5">
                             <div class="star-base">★★★★★</div>
                             <div class="star-fill" style="width: <?= ($avgRating / 5) * 100 ?>%;">★★★★★</div>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">(<?= e($totalRatings) ?> <?= __t('ratings') ?>)</p>
                    <?php else: ?>
                        <p class="text-lg text-gray-500 dark:text-gray-400 mt-4"><?= __t('no_feedback_yet') ?></p>
                    <?php endif; ?>
                 </div>    
            </div>
            <?php else: ?>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mb-8">
            <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 min-h-[300px]">
                    <h5 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-4"><?= __t('My Request Types') ?></h5>
                    <div class="h-64"> 
                        <canvas id="chartType"></canvas>
                    </div>
                </div>
                 <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 min-h-[300px]">
                     <h5 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-4"><?= __t('My Feedback') ?></h5>
                     <?php if ($totalRatings > 0): ?>
                     <div class="h-64 flex justify-center"> 
                        <canvas id="chartRatings"></canvas>
                    </div>
                    <?php else: ?>
                        <p class="text-lg text-gray-500 dark:text-gray-400 mt-4 h-64 flex items-center justify-center"><?= __t('no_feedback_yet') ?></p>
                    <?php endif; ?>
                 </div>
                 
                 <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50 min-h-[300px] flex flex-col justify-center">
                     <h5 class="text-lg font-semibold text-gray-700 dark:text-gray-200 mb-4"><?= __t('my_performance') ?></h5>
                     
                     <?php if ($u['role'] === 'technical'): ?>
                         <div class="space-y-4">
                            <div>
                                <div class="text-3xl font-bold text-gray-900 dark:text-white"><?= (int)$myStats['completed'] ?></div>
                                <p class="text-sm text-gray-500 dark:text-gray-400"><?= __t('total_completed_tickets') ?></p>
                            </div>
                            <hr class="dark:border-slate-700">
                            <div>
                                <div class="text-3xl font-bold text-gray-900 dark:text-white">
                                    <?= $myStats['avg_seconds'] ? format_seconds_to_readable($myStats['avg_seconds']) : 'N/A' ?>
                                </div>
                                <p class="text-sm text-gray-500 dark:text-gray-400"><?= __t('avg_resolution_time') ?></p>
                            </div>
                         </div>
                     <?php else: ?>
                         <div class="space-y-4">
                            <div>
                                <div class="text-3xl font-bold text-gray-900 dark:text-white"><?= (int)$myStats['total'] ?></div>
                                <p class="text-sm text-gray-500 dark:text-gray-400"><?= __t('total_requests') ?></p>
                            </div>
                            <hr class="dark:border-slate-700">
                            <div>
                                <div class="text-3xl font-bold text-gray-900 dark:text-white"><?= (int)$myStats['completed'] ?></div>
                                <p class="text-sm text-gray-500 dark:text-gray-400"><?= __t('completed_requests') ?></p>
                            </div>
                         </div>
                     <?php endif; ?>
                 </div>
                 </div>
            <?php endif; ?>
            
            <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-6 md:p-8 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50">
                 <h5 class="text-xl font-semibold text-gray-700 dark:text-gray-200 mb-6"><?= __t('data_export') ?></h5>
                
                <form method="get" class="flex flex-wrap items-end gap-4 mb-6" id="reportForm">
                    <input type="hidden" name="rating" id="filter_rating" value="<?= e($filter_rating) ?>">
                    <div>
                        <label for="report" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('report_type') ?></label>
                        <select name="report" id="report" class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                            <option value="tickets" <?= $report_type === 'tickets' ? 'selected' : '' ?>><?= __t('tickets') ?></option>
                            <option value="feedback" <?= $report_type === 'feedback' ? 'selected' : '' ?>><?= __t('feedback') ?></option>
                            <?php if ($isAdminOrCoord): ?>
                            <option value="kb" <?= $report_type === 'kb' ? 'selected' : '' ?>><?= __t('knowledge_base') ?></option>
                            <option value="users" <?= $report_type === 'users' ? 'selected' : '' ?>><?= __t('users') ?></option>
                            <option value="staff_performance" <?= $report_type === 'staff_performance' ? 'selected' : '' ?>><?= __t('staff_performance') ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <label for="date_from" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('date_from') ?></label>
                        <input type="date" name="date_from" id="date_from" value="<?= e($date_from) ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                    </div>
                    <div>
                        <label for="date_to" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('date_to') ?></label>
                        <input type="date" name="date_to" id="date_to" value="<?= e($date_to) ?>" class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                    </div>
                     <div>
                        <label for="limit" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('show_entries') ?></label>
                        <select name="limit" id="limit" class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-gray-200 text-sm">
                            <option value="10" <?= $limit === 10 ? 'selected' : '' ?>>10</option>
                            <option value="20" <?= $limit === 20 ? 'selected' : '' ?>>20</option>
                            <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                            <option value="0" <?= $limit === 999999 ? 'selected' : '' ?>><?= __t('all') ?></option>
                        </select>
                    </div>
                    <div class="">
                        <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                            <?= __t('filter') ?>
                        </button>
                    </div>
                    <div class="ml-auto">
                         <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'xlsx'])) ?>" 
                           class="flex items-center gap-2 px-4 py-2 text-sm font-medium text-green-700 bg-green-100 rounded-lg shadow-sm hover:bg-green-200 dark:bg-green-900/50 dark:text-green-300 dark:hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            <?= __t('export_xlsx') ?>
                        </a>
                    </div>
                </form>

                <?php if ($report_type === 'feedback' && !empty($filter_rating)): ?>
                    <div class="mb-4 -mt-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300"><?= __t('filtering_by_rating') ?>: <strong><?= e($filter_rating) ?> <?= __t('stars') ?></strong></span>
                        <a href="?<?= http_build_query(array_merge($_GET, ['rating' => '', 'report' => 'feedback'])) ?>" class="ml-2 px-3 py-1 text-xs font-medium text-red-700 bg-red-100 rounded-full hover:bg-red-200 dark:bg-red-900 dark:text-red-300"><?= __t('clear_filter') ?></a>
                    </div>
                <?php endif; ?>
                <div class="flex flex-wrap items-center gap-2 mb-6">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300"><?= __t('quick_filters') ?>:</span>
                    <button type="button" id="filterThisMonth" class="px-3 py-1 text-xs font-medium text-blue-700 bg-blue-100 rounded-full hover:bg-blue-200 dark:bg-blue-900 dark:text-blue-300"><?= __t('this_month') ?></button>
                    <button type="button" id="filterLastMonth" class="px-3 py-1 text-xs font-medium text-gray-700 bg-gray-100 rounded-full hover:bg-gray-200 dark:bg-slate-700 dark:text-gray-300"><?= __t('last_month') ?></button>
                    <button type="button" id="filterThisYear" class="px-3 py-1 text-xs font-medium text-gray-700 bg-gray-100 rounded-full hover:bg-gray-200 dark:bg-slate-700 dark:text-gray-300"><?= __t('this_year') ?></button>
                </div>
                <div class="overflow-x-auto table-responsive">
                     <table class="w-full text-sm text-left text-gray-600 dark:text-gray-400">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-slate-700 dark:text-gray-300">
                            <tr>
                                <?php foreach ($table_headers as $header): ?>
                                <th scope="col" class="px-4 py-3"><?= e($header) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                             <?php if (empty($table_data)): ?>
                                <tr class="border-b dark:border-slate-700">
                                    <td colspan="<?= count($table_headers) ?>" class="px-4 py-4 text-center text-gray-500 dark:text-gray-400">
                                        <?= __t('no_data_found') ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach($table_data as $row): ?>
                            <tr class="bg-white dark:bg-slate-800 border-b dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700">
                                <?php $i = 0; foreach ($row as $key => $value): ?>
                                <td data-label="<?= e($table_headers[$i] ?? $key) ?>" class="px-4 py-3 <?= $i === 0 ? 'font-medium text-gray-900 dark:text-white' : '' ?>">
                                    <?php 
                                        // Special formatting for Rating
                                        if ($report_type === 'feedback' && $key === 'rating') {
                                            $rating_val = (int)$value;
                                            echo '<div class="star-rating-display text-lg" title="'.$rating_val.'/5">';
                                            echo '<div class="star-base">★★★★★</div>';
                                            echo '<div class="star-fill" style="width: '.($rating_val / 5 * 100).'%">★★★★★</div>';
                                            echo '</div>';
                                        }
                                        // Special formatting for Staff Performance Avg Time
                                        elseif ($report_type === 'staff_performance' && $key === 'avg_seconds') {
                                            echo format_seconds_to_readable($value) . 
                                                 ' <span class="text-xs text-gray-500 dark:text-gray-400">(~'. number_format($value / 3600, 1) .' hrs)</span>';
                                        }
                                        // Simple formatting for others
                                        elseif (is_string($value) && strlen($value) > 50) echo e(mb_strimwidth($value, 0, 50, '…'));
                                        elseif (is_null($value)) echo '-';
                                        else echo e($value);
                                    ?>
                                </td>
                                <?php $i++; endforeach; ?>
                                
                                <?php // --- START: ADDED ACTIONS TD --- ?>
                                <?php if ($report_type === 'tickets' || $report_type === 'kb' || $report_type === 'users'): ?>
                                <td data-label="<?= __t('actions') ?>" class="px-4 py-3">
                                    <?php if ($report_type === 'tickets'): ?>
                                        <a href="ticket_view.php?id=<?= e($row['id']) ?>" target="_blank" class="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 mb-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                            <?= __t('view') ?>
                                        </a>
                                        <?php if (strtolower($row['status']) === 'completed'): ?>
                                        <a href="print_ticket.php?id=<?= e($row['id']) ?>" target="_blank" class="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                                            <?= __t('print') ?>
                                        </a>
                                        <?php endif; ?>
                                    <?php elseif ($report_type === 'kb'): ?>
                                        <a href="kb_view.php?id=<?= e($row['id']) ?>" target="_blank" class="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                            <?= __t('view') ?>
                                        </a>
                                    <?php elseif ($report_type === 'users'): ?>
                                        <a href="admin/user_edit.php?id=<?= e($row['id']) ?>" target="_blank" class="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>
                                            <?= __t('edit') ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <?php // --- END: ADDED ACTIONS TD --- ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1 && $limit !== 999999): ?>
                    <nav class="flex items-center justify-between pt-4" aria-label="Table navigation">
                        <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                            Showing <span class="font-semibold text-gray-900 dark:text-white"><?= $offset + 1 ?>-<?= min($offset + $limit, $total_rows) ?></span> 
                            of <span class="font-semibold text-gray-900 dark:text-white"><?= $total_rows ?></span>
                        </span>
                        <ul class="inline-flex items-center -space-x-px">
                            <?php 
                            $prev_params = array_merge($_GET, ['page' => max(1, $page - 1)]);
                            echo '<li><a href="?'.http_build_query($prev_params).'" class="block px-3 py-2 ml-0 leading-tight text-gray-500 bg-white border border-gray-300 rounded-l-lg hover:bg-gray-100 hover:text-gray-700 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-white '.($page <= 1 ? 'opacity-50 cursor-not-allowed' : '').'"><span class="sr-only">Previous</span><svg class="w-5 h-5" aria-hidden="true" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg></a></li>';
                            $startPage = max(1, $page - 2); $endPage = min($total_pages, $page + 2);
                            if ($startPage > 1) { echo '<li><a href="?'.http_build_query(array_merge($_GET, ['page' => 1])).'" class="px-3 py-2 leading-tight text-gray-500 bg-white border border-gray-300 hover:bg-gray-100 hover:text-gray-700 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-white">1</a></li>'; if ($startPage > 2) echo '<li><span class="px-3 py-2 leading-tight text-gray-500 bg-white border border-gray-300 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400">...</span></li>'; }
                            for ($p = $startPage; $p <= $endPage; $p++):
                                $page_params = array_merge($_GET, ['page' => $p]);
                                echo '<li><a href="?'.http_build_query($page_params).'" class="px-3 py-2 leading-tight '.($p == $page ? 'text-blue-600 bg-blue-50 border-blue-300 hover:bg-blue-100 hover:text-blue-700 dark:border-slate-700 dark:bg-slate-700 dark:text-white' : 'text-gray-500 bg-white border border-gray-300 hover:bg-gray-100 hover:text-gray-700 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-white').'">'.$p.'</a></li>';
                            endfor;
                            if ($endPage < $total_pages) { if ($endPage < $total_pages - 1) echo '<li><span class="px-3 py-2 leading-tight text-gray-500 bg-white border border-gray-300 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400">...</span></li>'; echo '<li><a href="?'.http_build_query(array_merge($_GET, ['page' => $total_pages])).'" class="px-3 py-2 leading-tight text-gray-500 bg-white border border-gray-300 hover:bg-gray-100 hover:text-gray-700 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-white">'.$total_pages.'</a></li>'; }
                            $next_params = array_merge($_GET, ['page' => min($total_pages, $page + 1)]);
                            echo '<li><a href="?'.http_build_query($next_params).'" class="block px-3 py-2 leading-tight text-gray-500 bg-white border border-gray-300 rounded-r-lg hover:bg-gray-100 hover:text-gray-700 dark:bg-slate-800 dark:border-slate-700 dark:text-gray-400 dark:hover:bg-slate-700 dark:hover:text-white '.($page >= $total_pages ? 'opacity-50 cursor-not-allowed' : '').'"><span class="sr-only">Next</span><svg class="w-5 h-5" aria-hidden="true" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path></svg></a></li>';
                            ?>
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
        
        // ===== START: FEATURE 2 (Clickable Chart) =====
        function filterTableByRating(rating) {
            if (!rating) return;
            $('#report').val('feedback'); // Set report type to 'feedback'
            $('#filter_rating').val(rating); // Set hidden rating input
            $('#reportForm').submit(); // Submit the form
        }
        // ===== END: FEATURE 2 =====

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
        let typeChartInstance = null; 
        let ratingChartInstance = null; 

        function getChartThemeColors(isDark) {
             return {
                 gridColor: isDark ? '#334155' : '#e2e8f0', 
                 textColor: isDark ? '#cbd5e1' : '#4b5563', 
                 tooltipBg: isDark ? '#1e293b' : '#ffffff', 
                 tooltipText: isDark ? '#e2e8f0' : '#1f2937', 
                 borderColor: isDark ? '#1e293b' : '#ffffff' 
             };
        }
        function updateChartTheme(chart, isDark) {
             if (!chart) return;
             const colors = getChartThemeColors(isDark);
             if (chart.options.scales.x) { chart.options.scales.x.ticks.color = colors.textColor; chart.options.scales.x.grid.color = colors.gridColor; }
             if (chart.options.scales.y) { chart.options.scales.y.ticks.color = colors.textColor; chart.options.scales.y.grid.color = colors.gridColor; }
             if (chart.options.plugins.legend) { chart.options.plugins.legend.labels.color = colors.textColor; }
             if (chart.options.plugins.tooltip) { chart.options.plugins.tooltip.backgroundColor = colors.tooltipBg; chart.options.plugins.tooltip.titleColor = colors.tooltipText; chart.options.plugins.tooltip.bodyColor = colors.tooltipText; chart.options.plugins.tooltip.borderColor = colors.gridColor; }
             if (chart.config.type === 'doughnut' || chart.config.type === 'pie') { chart.data.datasets.forEach(dataset => { dataset.borderColor = colors.borderColor; }); }
             chart.update();
        }
        function updateAllChartThemes(isDark) {
            updateChartTheme(typeChartInstance, isDark);
            updateChartTheme(ratingChartInstance, isDark); 
        }
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
            updateAllChartThemes(isDark); 
        } 
        updateThemeIcon(); 
        
        themeToggle.addEventListener('click', () => { 
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.theme = isDark ? 'dark' : 'light'; 
            updateThemeIcon(); 
        }); 
        
         window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', event => {
            if (localStorage.theme === 'system') {
                updateThemeIcon();
                updateAllChartThemes(event.matches);
            }
        });

        // Chart.js Initialization
        document.addEventListener('DOMContentLoaded', () => {
            const isInitiallyDark = document.documentElement.classList.contains('dark');
            const initialColors = getChartThemeColors(isInitiallyDark);

            // Chart 1: Tickets by Type (Bar Chart)
            const ctxType = document.getElementById('chartType')?.getContext('2d');
            if (ctxType && <?= count($typeData) ?> > 0) {
                const backgroundColors = [ 'rgba(59, 130, 246, 0.6)', 'rgba(239, 68, 68, 0.6)', 'rgba(245, 158, 11, 0.6)', 'rgba(16, 185, 129, 0.6)', 'rgba(139, 92, 246, 0.6)', 'rgba(236, 72, 153, 0.6)', 'rgba(100, 116, 139, 0.6)' ];
                const borderColors = [ 'rgba(59, 130, 246, 1)', 'rgba(239, 68, 68, 1)', 'rgba(245, 158, 11, 1)', 'rgba(16, 185, 129, 1)', 'rgba(139, 92, 246, 1)', 'rgba(236, 72, 153, 1)', 'rgba(100, 116, 139, 1)' ];
                const numTypes = <?= count($typeLabels) ?>;
                const typeBgColors = Array.from({ length: numTypes }, (_, i) => backgroundColors[i % backgroundColors.length]);
                const typeBdColors = Array.from({ length: numTypes }, (_, i) => borderColors[i % borderColors.length]);

                typeChartInstance = new Chart(ctxType,{
                    type:'bar', 
                    data:{
                        labels: <?= json_encode($typeLabels) ?>,
                        datasets:[{
                            label: '<?= __t('tickets') ?>', 
                            data: <?= json_encode($typeData) ?>,
                            backgroundColor: typeBgColors,
                            borderColor: typeBdColors,
                            borderWidth: 1
                        }]
                    },
                    options:{
                        responsive: true, maintainAspectRatio: false, 
                        indexAxis: 'y', 
                        scales:{
                            y:{ beginAtZero: true, ticks: { color: initialColors.textColor }, grid: { color: 'transparent' } },
                            x:{ beginAtZero: true, ticks: { color: initialColors.textColor, precision: 0 }, grid: { color: initialColors.gridColor } }
                        },
                        plugins: {
                            legend: { display: false }, 
                            tooltip: { backgroundColor: initialColors.tooltipBg, titleColor: initialColors.tooltipText, bodyColor: initialColors.tooltipText, borderColor: initialColors.gridColor, borderWidth: 1 }
                        }
                    }
                });
            } else if (ctxType) {
                 const ctx = ctxType.canvas.getContext("2d");
                 ctx.font = "14px 'Kantumruy Pro'";
                 ctx.fillStyle = initialColors.textColor;
                 ctx.textAlign = "center";
                 ctx.fillText("<?= __t('no_data_yet') ?>", ctxType.canvas.width / 2, ctxType.canvas.height / 2);
            }

            // Chart 2: Rating Distribution (Doughnut Chart)
            const ctxRatings = document.getElementById('chartRatings')?.getContext('2d');
             if (ctxRatings && <?= count($ratingCounts) ?> > 0) { // Only render if data exists
                ratingChartInstance = new Chart(ctxRatings, {
                    type: 'doughnut',
                    data: {
                        labels: <?= json_encode($ratingLabels) ?>,
                        datasets: [{
                            label: '<?= __t('ratings') ?>',
                            data: <?= json_encode($ratingCounts) ?>,
                            backgroundColor: <?= json_encode($ratingChartColors) ?>,
                            borderColor: initialColors.borderColor,
                            borderWidth: 2,
                            hoverOffset: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        
                        // ===== START: FEATURE 2 (Clickable Chart) =====
                        onClick: (event, elements) => {
                            if (elements.length > 0) {
                                const chart = elements[0].chart;
                                const elementIndex = elements[0].index;
                                const label = chart.data.labels[elementIndex] || ''; // e.g., "5 stars"
                                const rating = label.split(' ')[0]; // Get "5"
                                if(rating) {
                                    filterTableByRating(rating);
                                }
                            }
                        },
                        // ===== END: FEATURE 2 =====

                        plugins: {
                            legend: {
                                position: 'right', 
                                labels: { color: initialColors.textColor, boxWidth: 12, padding: 15 }
                            },
                            tooltip: {
                                 backgroundColor: initialColors.tooltipBg,
                                 titleColor: initialColors.tooltipText,
                                 bodyColor: initialColors.tooltipText,
                                 borderColor: initialColors.gridColor,
                                 borderWidth: 1,
                                 callbacks: {
                                     label: function(context) {
                                         let label = context.label || '';
                                         let value = context.raw || 0;
                                         let sum = context.chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
                                         let percentage = (value / sum * 100).toFixed(1) + '%';
                                         return `${label}: ${value} (${percentage})`;
                                     }
                                 }
                            }
                        }
                    }
                });
            } else if (ctxRatings) {
                 const ctx = ctxRatings.canvas.getContext("2d");
                 ctx.font = "14px 'Kantumruy Pro'";
                 ctx.fillStyle = initialColors.textColor;
                 ctx.textAlign = "center";
                 ctx.fillText("<?= __t('no_feedback_yet') ?>", ctxRatings.canvas.width / 2, ctxRatings.canvas.height / 2);
            }

             // Add input-style class to selects
             document.querySelectorAll('#reportForm select, #reportForm input').forEach(el => {
                 el.classList.add('px-3', 'py-2', 'border', 'border-gray-300', 'rounded-lg', 'dark:bg-slate-700', 'dark:border-slate-600', 'focus:outline-none', 'focus:ring-2', 'focus:ring-blue-500', 'dark:text-gray-200', 'text-sm');
             });

             
            // ===== START: FEATURE 3 (Quick Date Filter JS) =====
            function getISODate(date) {
                return date.toISOString().split('T')[0];
            }

            const today = new Date();
            const y = today.getFullYear();
            const m = today.getMonth();

            const firstDayThisMonth = getISODate(new Date(y, m, 1));
            const lastDayThisMonth = getISODate(new Date(y, m + 1, 0));
            
            const firstDayLastMonth = getISODate(new Date(y, m - 1, 1));
            const lastDayLastMonth = getISODate(new Date(y, m, 0));

            const firstDayThisYear = getISODate(new Date(y, 0, 1));
            const lastDayThisYear = getISODate(new Date(y, 11, 31));

            $('#filterThisMonth').on('click', function() {
                $('#date_from').val(firstDayThisMonth);
                $('#date_to').val(lastDayThisMonth);
                $('#reportForm').submit();
            });

            $('#filterLastMonth').on('click', function() {
                $('#date_from').val(firstDayLastMonth);
                $('#date_to').val(lastDayLastMonth);
                $('#reportForm').submit();
            });

            $('#filterThisYear').on('click', function() {
                $('#date_from').val(firstDayThisYear);
                $('#date_to').val(lastDayThisYear);
                $('#reportForm').submit();
            });
            // ===== END: FEATURE 3 =====

        });
    </script>
    

<script>
// ***** FIX 6: Added Notification Mark as Read Logic *****
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
            }).fail(function(){ console.error('Failed to mark notifications as read.'); });
        } else {
            // Fallback to fetch
            fetch('<?= base_url('api/mark_notifications_read.php') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: '_csrf=<?= e(csrf_token()) ?>'
            }).then(r => r.json()).then(function(response){
                try { if (response.success) { badge.classList.add('animate-ping', 'opacity-0'); setTimeout(() => badge.remove(), 500); } } catch(e){}
            }).catch(function(){ console.error('Failed to mark notifications as read.'); });
        }
    }
}
// ***** END Notification Logic *****
</script>
</body>
</html>