<?php
session_start();

// ===== START: 2FA FIX (រក្សាទុកដដែល) =====

// 1. ពិនិត្យមើល 2FA Session ជាមុនសិន!
if (isset($_SESSION['2fa_pending_user_id'])) {
    
    // 2. បើ 2FA Session មាន, ពេលនេះ យើងអាចហៅឯកសារផ្សេងៗបាន
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/functions.php';
    // require_once __DIR__ . '/../includes/auth.php'; // <-- FIX: បានលុបបន្ទាត់នេះចោល
    require_once __DIR__ . '/../includes/csrf.php';
    require_once __DIR__ . '/../vendor/autoload.php';

} else {
    
    // 3. បើ 2FA Session មិនមាន, ពិនិត្យមើលថាតើ User បាន Log in ពេញលេញហើយឬនៅ?
    // (នេះជាករណី User ដែល Log in រួច ចុច Back មកទំព័រនេះ)
    require_once __DIR__ . '/../includes/functions.php'; // ត្រូវតែហៅ functions.php មុន auth.php
    require_once __DIR__ . '/../includes/auth.php';
    
    if (current_user()) {
        header('Location: ' . base_url('dashboard.php'));
        exit;
    }
    
    // 4. បើមិនមាន Session ទាំងពីរ, បញ្ជូនទៅ Login
    header('Location: ' . base_url('index.php'));
    exit;
}
// ===== END: 2FA FIX =====


use PragmaRX\Google2FA\Google2FA;

$google2fa = new Google2FA();
$errorMsg = null;
$showRecovery = isset($_GET['recovery']); // ពិនិត្យមើល បើ User ចុច "Use Recovery Code"

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $userId = $_SESSION['2fa_pending_user_id'];
    
    try {
        // 1. ទាញយក User ពី Database
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND two_fa_enabled = 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception(__t('user_not_found'));
        }

        $isValid = false;

        // 2. ពិនិត្យមើលថាតើ User ប្រើ Recovery Code ឬ 6-digit Code
        if (isset($_POST['recovery_code'])) {
            // ----- ផ្ទៀងផ្ទាត់ Recovery Code -----
            $recovery_code = trim($_POST['recovery_code']);
            
            $stmt = $pdo->prepare("SELECT * FROM user_recovery_codes WHERE user_id = ? AND used = 0");
            $stmt->execute([$userId]);
            $codes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($codes as $db_code) {
                if (password_verify($recovery_code, $db_code['code'])) {
                    $isValid = true;
                    // សម្គាល់ Code នេះ ថាបានប្រើแล้ว
                    $pdo->prepare("UPDATE user_recovery_codes SET used = 1 WHERE id = ?")->execute([$db_code['id']]);
                    break;
                }
            }
            if (!$isValid) {
                throw new Exception(__t('2fa_invalid_recovery_code'));
            }

        } else {
            // ----- ផ្ទៀងផ្ទាត់ 6-digit Code (TOTP) -----
            $codeFromUser = trim($_POST['code'] ?? '');
            
            // Decrypt Secret Key ពី Database
            $decryptedSecret = decrypt_2fa_secret($user['google2fa_secret']); // ប្រើ Function ពី functions.php
            
            if (empty($decryptedSecret)) {
                throw new Exception("Could not decrypt 2FA secret. Contact admin.");
            }

            $isValid = $google2fa->verifyKey($decryptedSecret, $codeFromUser);
            
            if (!$isValid) {
                 throw new Exception(__t('2fa_invalid_code'));
            }
        }

        // 3. ជោគជ័យ! បញ្ជូន User ចូល
        if ($isValid) {
            session_regenerate_id(true); // ការពារ Session Fixation
            unset($_SESSION['2fa_pending_user_id']); // លុប Session បណ្តោះអាសន្ន
            
            // ***** START: FIX (កែបន្ទាត់នេះ) *****
            $_SESSION['user'] = $user; // កំណត់ Session ផ្ទុកព័ត៌មាន User ទាំងមូល
            // ***** END: FIX *****
            
            header('Location: ' . base_url('dashboard.php')); // បញ្ជូនទៅផ្ទាំងគ្រប់គ្រង
            exit;
        }

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

$lang = current_lang();
$appName = $lang === 'km' ? $config['app']['name_km'] : $config['app']['name_en'];
?>

<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <title><?= e($appName) ?> - <?= __t('verify_login') ?></title>
	<link rel="icon" href="<?= base_url('assets/img/logo_32x32.png') ?>">
    <script> /* Theme Loader */ if(localStorage.theme==='dark'||(!('theme' in localStorage)&&window.matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark')}else{document.documentElement.classList.remove('dark')} </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script> tailwind.config={darkMode:'class'} </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;700&display=swap');
        body { font-family: 'Kantumruy Pro', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 dark:bg-slate-900 dark:text-gray-300 transition-colors duration-300 flex items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-md">
        <div class="bg-white dark:bg-slate-800/80 backdrop-blur-sm p-8 rounded-2xl shadow-lg border border-gray-200 dark:border-slate-700/50">
            <h1 class="text-2xl font-bold text-center text-gray-800 dark:text-white mb-2">
                <?= $showRecovery ? __t('use_recovery_code') : __t('verify_your_identity') ?>
            </h1>
            <p class="text-center text-gray-500 dark:text-gray-400 mb-6">
                <?= $showRecovery ? __t('2fa_recovery_prompt') : __t('2fa_code_prompt') ?>
            </p>

            <?php if ($errorMsg): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
                    <span class="block sm:inline"><?= e($errorMsg) ?></span>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <?php csrf_field(); ?>
                
                <?php if ($showRecovery): ?>
                    <div>
                        <label for="recovery_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('recovery_code') ?></label>
                        <input type="text" name="recovery_code" id="recovery_code" required autofocus
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 text-lg text-center font-mono tracking-widest">
                    </div>
                <?php else: ?>
                    <div>
                        <label for="code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"><?= __t('6_digit_code') ?></label>
                        <input type="text" name="code" id="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-slate-700 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 text-lg text-center font-mono tracking-widest">
                    </div>
                <?php endif; ?>

                <div class="mt-6">
                    <button type="submit" class="w-full px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800">
                        <?= __t('verify') ?>
                    </button>
                </div>
            </form>

            <div class="text-center mt-4">
                <?php if ($showRecovery): ?>
                    <a href="login_verify.php" class="text-sm text-blue-600 hover:underline dark:text-blue-400"><?= __t('use_authenticator_app') ?></a>
                <?php else: ?>
                    <a href="login_verify.php?recovery=1" class="text-sm text-blue-600 hover:underline dark:text-blue-400"><?= __t('use_recovery_code') ?></a>
                <?php endif; ?>
            </div>
            
        </div>
        
        <div class="text-center text-sm text-gray-500 dark:text-gray-400 mt-6">
            <a href="index.php" class="hover:underline"><?= __t('back_to_login') ?></a>
        </div>
    </div>
</body>
</html>