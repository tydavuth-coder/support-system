<?php
// --- PHP LOGIC របស់អ្នក (រក្សាទុកដដែល) ---
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config.php'; // <-- ធានាថា $pdo មាន

if (current_user()) {
    header('Location: ' . base_url('dashboard.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // --- START: 2FA LOGIN FIX ---
    
    // 1. ស្វែងរក User តាម Email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $loginSuccess = false;
    // 2. ផ្ទៀងផ្ទាត់ Password
    if ($user && password_verify($password, $user['password_hash'])) {
        $loginSuccess = true;
    }

    if ($loginSuccess) {
        // 3. ពិនិត្យមើល 2FA
        if ($user['two_fa_enabled']) {
            // 3a. User បើក 2FA: បញ្ជូនទៅទំព័រផ្ទៀងផ្ទាត់
            session_regenerate_id(true); // ការពារ Session Fixation
            $_SESSION['2fa_pending_user_id'] = $user['id']; // <-- Session បណ្តោះអាសន្ន
            header('Location: ' . base_url('login_verify.php'));
            exit;
        } else {
            // 3b. User បិទ 2FA: បញ្ជូនចូលដោយផ្ទាល់
            session_regenerate_id(true);
            
            // ***** START: FIX (កែបន្ទាត់នេះ) *****
            $_SESSION['user'] = $user; // កំណត់ Session ផ្ទុកព័ត៌មាន User ទាំងមូល
            // ***** END: FIX *****

            header('Location: '.base_url('dashboard.php'));
            exit;
        }
    } else {
        // 4. Login មិនជោគជ័យ (ខុស Email ឬ Password)
        $error = __t('invalid_login'); 
    }
    
    // --- END: 2FA LOGIN FIX ---
}

$lang = current_lang();
$appName = $lang === 'km' ? $config['app']['name_km'] : $config['app']['name_en'];
// --- ចប់ PHP LOGIC ---
?>

<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appName) ?> - <?= e(__t('login')) ?></title>
	<link rel="icon" href="<?= base_url('assets/img/logo.png') ?>">    
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;700&display=swap');
        body {
            font-family: 'Kantumruy Pro', 'Helvetica Neue', Arial, sans-serif;
        }
        
        /* 1. Canvas នឹងនៅខាងក្រោយគេ */
        #digital-particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0; /* ដាក់នៅខាងក្រោម Card */
        }
        
        /* 2. Card ត្រូវតែនៅខាងលើ Canvas */
        .login-card {
            position: relative;
            z-index: 10;
        }

        .animated-gradient {
            background: linear-gradient(-45deg, #0f172a, #1e3a8a, #312e81, #0c4a6e);
            animation: fadeIn 0.8s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-slate-900 min-h-screen flex items-center justify-center p-4">

    <canvas id="digital-particles"></canvas>

    <div class="bg-white w-full max-w-md p-8 md:p-10 rounded-2xl shadow-2xl form-fade-in login-card">
        
        <img src="<?= base_url('assets/img/logo.png') ?>" alt="Logo" class="w-20 h-20 mx-auto mb-4">
        
        <h1 class="text-3xl font-bold text-center text-gray-800 mb-2">
            <?= e($appName) ?>
        </h1>
        <p class="text-center text-gray-500 mb-6">
            <?= e(__t('welcome')) ?>
        </p>

        <div class="flex justify-center gap-4 mb-6">
            <a href="?setlang=km" 
               title="ភាសាខ្មែរ"
               class="rounded-full shadow-md transition-all duration-300 <?= $lang === 'km' ? 'ring-2 ring-blue-500 ring-offset-2' : 'opacity-60 hover:opacity-100 hover:scale-110' ?>">
                <img src="<?= base_url('assets/img/flag-km.png') ?>" 
                     alt="ភាសាខ្មែរ" 
                     class="w-10 h-10 rounded-full object-cover"> 
            </a>
            <a href="?setlang=en" 
               title="English"
               class="rounded-full shadow-md transition-all duration-300 <?= $lang === 'en' ? 'ring-2 ring-blue-500 ring-offset-2' : 'opacity-60 hover:opacity-100 hover:scale-110' ?>">
                <img src="<?= base_url('assets/img/flag-en.png') ?>" 
                     alt="English" 
                     class="w-10 h-10 rounded-full object-cover">
            </a>
        </div>

        <?php if($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-4" role="alert">
                <span class="block sm:inline"><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form id="loginForm" method="post" action="" class="space-y-6">
            <?php csrf_field(); ?>

            <div class="relative">
                <input type="email" id="email" name="email" 
                       class="block w-full px-4 py-3 text-gray-900 border border-gray-300 rounded-lg appearance-none focus:outline-none focus:ring-2 focus:ring-blue-600 focus:border-transparent peer" 
                       placeholder=" " required />
                <label for="email" 
                       class="absolute text-gray-500 duration-300 transform -translate-y-4 scale-75 top-3 z-10 origin-[0] bg-white px-2 left-4
                              peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 
                              peer-focus:scale-75 peer-focus:-translate-y-4 peer-focus:text-blue-600">
                    <?= e(__t('email')) ?>
                </label>
            </div>

            <div class="relative">
                <input type="password" id="password" name="password" 
                       class="block w-full px-4 py-3 text-gray-900 border border-gray-300 rounded-lg appearance-none focus:outline-none focus:ring-2 focus:ring-blue-600 focus:border-transparent peer" 
                       placeholder=" " required />
                <label for="password" 
                       class="absolute text-gray-500 duration-300 transform -translate-y-4 scale-75 top-3 z-10 origin-[0] bg-white px-2 left-4
                              peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 
                              peer-focus:scale-75 peer-focus:-translate-y-4 peer-focus:text-blue-600">
                    <?= e(__t('password')) ?>
                </label>
            </div>

            <div class="text-right">
                <a href="forgot-password.php" class="text-sm font-medium text-blue-600 hover:underline">
                    <?= e(__t('forgot password')) ?>
                </a>
            </div>

            <div>
                <button type="submit" id="loginButton" 
                        class="flex items-center justify-center w-full px-4 py-3 text-lg font-bold text-white bg-gradient-to-r from-blue-600 to-indigo-700 rounded-lg shadow-lg
                               hover:from-blue-700 hover:to-indigo-800 focus:outline-none focus:ring-4 focus:ring-blue-300
                               transform transition-all duration-300 ease-in-out hover:scale-[1.02]">
                    <span id="buttonText"><?= e(__t('login')) ?></span>
                    <svg id="buttonSpinner" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </button>
            </div>
        </form>


    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function(event) {
            const loginButton = document.getElementById('loginButton');
            const buttonText = document.getElementById('buttonText');
            const buttonSpinner = document.getElementById('buttonSpinner');
            loginButton.disabled = true;
            buttonText.textContent = '<?= e(__t('logging_in')) ?>';
            buttonSpinner.classList.remove('hidden');
        });
    </script>

    <script>
        const canvas = document.getElementById('digital-particles');
        const ctx = canvas.getContext('2d');
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;

        let particlesArray = [];
        const numberOfParticles = (canvas.width * canvas.height) / 9000; // លៃតម្រូវដង់ស៊ីតេ
        const particleColor = 'rgba(255, 255, 255, 0.6)';
        const lineColor = 'rgba(100, 180, 255, 0.25)'; // ពណ៌ Digital ខៀវ

        class Particle {
            constructor(x, y, directionX, directionY, size) {
                this.x = x;
                this.y = y;
                this.directionX = directionX;
                this.directionY = directionY;
                this.size = size;
            }

            draw() {
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2, false);
                ctx.fillStyle = particleColor;
                ctx.fill();
            }

            update() {
                if (this.x > canvas.width || this.x < 0) {
                    this.directionX = -this.directionX;
                }
                if (this.y > canvas.height || this.y < 0) {
                    this.directionY = -this.directionY;
                }
                this.x += this.directionX;
                this.y += this.directionY;
                this.draw();
            }
        }

        function init() {
            particlesArray = [];
            for (let i = 0; i < numberOfParticles; i++) {
                let size = Math.random() * 2 + 1;
                let x = Math.random() * canvas.width;
                let y = Math.random() * canvas.height;
                let directionX = (Math.random() * 0.4) - 0.2; // ល្បឿនយឺតៗ
                let directionY = (Math.random() * 0.4) - 0.2;
                particlesArray.push(new Particle(x, y, directionX, directionY, size));
            }
        }

        function connect() {
            let opacityValue = 1;
            for (let a = 0; a < particlesArray.length; a++) {
                for (let b = a + 1; b < particlesArray.length; b++) { // ចាប់ផ្តើមពី a + 1
                    let distance = Math.sqrt(
                        (particlesArray[a].x - particlesArray[b].x) * (particlesArray[a].x - particlesArray[b].x) +
                        (particlesArray[a].y - particlesArray[b].y) * (particlesArray[a].y - particlesArray[b].y)
                    );
                    
                    if (distance < 120) { // រកចម្ងាយ
                        opacityValue = 1 - (distance / 120);
                        ctx.strokeStyle = lineColor.replace('0.25', opacityValue); // លៃតម្រូវ Opacity
                        ctx.lineWidth = 0.5;
                        ctx.beginPath();
                        ctx.moveTo(particlesArray[a].x, particlesArray[a].y);
                        ctx.lineTo(particlesArray[b].x, particlesArray[b].y);
                        ctx.stroke();
                    }
                }
            }
        }

        function animate() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            for (let particle of particlesArray) {
                particle.update();
            }
            connect();
            requestAnimationFrame(animate);
        }

        window.addEventListener('resize', () => {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
            init(); // បង្កើត Particles ឡើងវិញពេល Resize
        });

        init();
        animate();
    </script>
</body>
</html>