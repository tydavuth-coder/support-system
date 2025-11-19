<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$ticket_id = (int)($_GET['id'] ?? 0);
$u = current_user();
$isAdminOrCoord = is_role('admin') || is_role('coordinator');

if ($ticket_id === 0) die("Invalid Ticket ID.");

$sql = "SELECT t.*, u.name as requester_name, u.phone as requester_phone, u.email as requester_email, a.name as tech_name 
        FROM tickets t
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN users a ON a.id = t.assigned_to
        WHERE t.id = ?";
        
$stmt = $pdo->prepare($sql);
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) die("Ticket not found.");

$isRequester = ($ticket['user_id'] === $u['id']);
$isAssignedTech = ($u['role'] === 'technical' && $ticket['assigned_to'] === $u['id']);
if (!$isAdminOrCoord && !$isRequester && !$isAssignedTech) die("Permission Denied.");

if (empty($ticket['tech_name'])) $ticket['tech_name'] = 'N/A';

$lang = current_lang();
$appName = $lang === 'km' ? $config['app']['name_km'] : $config['app']['name_en'];
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __t('print_ticket') ?> #<?= e($ticket['id']) ?></title>
	<link rel="icon" href="<?= base_url('assets/img/logo_32x32.png') ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;600;700&display=swap');
        body { font-family: 'Kantumruy Pro', sans-serif; background-color: #f3f4f6; }
        @page { size: A4; margin: 0; }
        .print-page {
            width: 210mm; min-height: 297mm; margin: 20px auto; background: white; box-shadow: 0 0 15px rgba(0,0,0,0.1); position: relative; overflow: hidden;
        }
        .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-45deg); font-size: 8rem; color: rgba(0,0,0,0.03); font-weight: bold; white-space: nowrap; pointer-events: none; z-index: 0; }
        @media print {
            body { background: none; margin: 0; }
            .print-page { margin: 0; width: 100%; box-shadow: none; border: none; }
            .no-print { display: none !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body>

    <div class="no-print fixed top-0 left-0 w-full bg-gray-800 text-white p-4 flex justify-between items-center z-50 shadow-md">
        <div class="font-bold text-lg"><?= e($appName) ?></div>
        <div class="flex gap-4">
            <button onclick="window.history.back()" class="px-4 py-2 bg-gray-600 hover:bg-gray-500 rounded text-sm"><?= __t('back') ?></button>
            <button onclick="window.print()" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 rounded text-sm font-bold flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                <?= __t('print') ?>
            </button>
        </div>
    </div>

    <div class="h-16 no-print"></div>

    <div class="print-page flex flex-col">
        <div class="watermark"><?= strtoupper(e($ticket['status'])) ?></div>

        <div class="relative z-10 bg-blue-50 text-gray-800 p-10 pb-16 border-b border-blue-100">
            <div class="flex justify-between items-start">
                <div class="flex items-center gap-4">
                    <img src="<?= base_url(get_setting('site_logo', 'assets/img/logo_128x128.png')) ?>" class="h-20 w-20 bg-white rounded-lg p-1 object-contain shadow-sm">
                    <div>
                        <h1 class="text-2xl font-bold leading-tight mb-1 text-blue-900"><?= e($appName) ?></h1>
                        <p class="text-blue-600 text-sm uppercase tracking-wide"><?= __t('Digital System Management Department') ?></p>
                    </div>
                </div>
                <div class="text-right">
                    <h2 class="text-3xl font-bold text-blue-800 mb-1"><?= __t('Service Report') ?></h2>
                    <p class="text-gray-500 font-mono">ID: #<?= str_pad($ticket['id'], 6, '0', STR_PAD_LEFT) ?></p>
                </div>
            </div>
        </div>

        <div class="relative z-20 px-10 -mt-8 mb-10">
            <div class="grid grid-cols-4 gap-4">
                <div class="bg-white p-4 rounded-lg shadow-md border-l-4 border-blue-500">
                    <span class="text-xs text-gray-500 uppercase font-bold"><?= __t('status') ?></span>
                    <div class="mt-1 text-lg font-bold text-blue-600 capitalize"><?= __t(e($ticket['status'])) ?></div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow-md border-l-4 border-purple-500">
                    <span class="text-xs text-gray-500 uppercase font-bold"><?= __t('priority') ?></span>
                    <div class="mt-1 text-lg font-bold text-purple-600 capitalize"><?= e($ticket['priority']) ?></div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow-md border-l-4 border-green-500">
                    <span class="text-xs text-gray-500 uppercase font-bold"><?= __t('type') ?></span>
                    <div class="mt-1 text-lg font-bold text-green-600 capitalize"><?= e($ticket['type']) ?></div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow-md border-l-4 border-gray-500">
                    <span class="text-xs text-gray-500 uppercase font-bold"><?= __t('date') ?></span>
                    <div class="mt-1 text-base font-bold text-gray-700"><?= date('d/m/Y', strtotime($ticket['created_at'])) ?></div>
                </div>
            </div>
        </div>

        <div class="px-10 flex-grow relative z-10">
            
            <div class="flex mb-10 border-b border-gray-200 pb-8">
                <div class="w-1/2 pr-8 border-r border-gray-200">
                    <h3 class="text-gray-800 font-bold text-lg mb-4 flex items-center gap-2">
                        <span class="w-2 h-6 bg-blue-600 rounded-sm"></span> <?= __t('requester_info') ?>
                    </h3>
                    <table class="w-full text-sm">
                        <tr><td class="text-gray-500 py-1 w-24"><?= __t('name') ?>:</td><td class="font-semibold text-gray-800"><?= e($ticket['requester_name']) ?></td></tr>
                        <tr><td class="text-gray-500 py-1"><?= __t('phone') ?>:</td><td class="text-gray-800"><?= e($ticket['requester_phone'] ?: 'N/A') ?></td></tr>
                        <tr><td class="text-gray-500 py-1"><?= __t('email') ?>:</td><td class="text-gray-800"><?= e($ticket['requester_email'] ?: 'N/A') ?></td></tr>
                    </table>
                </div>
                <div class="w-1/2 pl-8">
                    <h3 class="text-gray-800 font-bold text-lg mb-4 flex items-center gap-2">
                        <span class="w-2 h-6 bg-gray-600 rounded-sm"></span> <?= __t('technician_info') ?>
                    </h3>
                    <table class="w-full text-sm">
                        <tr><td class="text-gray-500 py-1 w-24"><?= __t('technician') ?>:</td><td class="font-semibold text-gray-800"><?= e($ticket['tech_name']) ?></td></tr>
                        <tr><td class="text-gray-500 py-1"><?= __t('completed_at') ?>:</td><td class="text-gray-800"><?= $ticket['status'] === 'completed' ? date('d/m/Y H:i', strtotime($ticket['updated_at'])) : '-' ?></td></tr>
                    </table>
                </div>
            </div>

            <div class="mb-8">
                <h3 class="text-gray-800 font-bold text-lg mb-3"><?= __t('Problem / Request') ?></h3>
                <div class="bg-red-50 border border-red-100 rounded-lg p-5">
                    <h4 class="font-bold text-red-800 mb-2 text-base"><?= e($ticket['title']) ?></h4>
                    <p class="text-gray-700 text-sm leading-relaxed whitespace-pre-wrap"><?= e($ticket['description']) ?></p>
                </div>
            </div>

            <div class="mb-10">
                <h3 class="text-gray-800 font-bold text-lg mb-3"><?= __t('Solution / Action Taken') ?></h3>
                <div class="bg-green-50 border border-green-100 rounded-lg p-5 min-h-[100px]">
                    <?php if (!empty($ticket['solution'])): ?>
                        <p class="text-gray-700 text-sm leading-relaxed whitespace-pre-wrap"><?= e($ticket['solution']) ?></p>
                    <?php else: ?>
                        <p class="text-gray-400 text-sm italic text-center mt-4"><?= __t('no_solution_notes') ?></p>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <div class="px-10 pb-16 mt-auto relative z-10">
            <div class="grid grid-cols-2 gap-20">
                <div class="text-center">
                    <p class="text-sm text-gray-500 mb-16"><?= __t('technician_signature') ?></p>
                    <div class="border-t border-gray-300 pt-2">
                        <p class="font-bold text-sm text-gray-800"><?= e($ticket['tech_name']) ?></p>
                    </div>
                </div>
                <div class="text-center">
                    <p class="text-sm text-gray-500 mb-16"><?= __t('requester_signature') ?></p>
                    <div class="border-t border-gray-300 pt-2">
                        <p class="font-bold text-sm text-gray-800"><?= e($ticket['requester_name']) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-gray-50 border-t border-gray-200 p-4 text-center text-xs text-gray-400">
            <p><?= e($appName) ?> &copy; <?= date('Y') ?>. Generated on <?= date('d/m/Y H:i') ?></p>
        </div>
    </div>

</body>
</html>