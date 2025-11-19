<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

$lang = current_lang();
$appName = $lang === 'km' ? $config['app']['name_km'] : $config['app']['name_en'];
$token = $_GET['token'] ?? '';
$error = '';
$message = '';

// 1. ពិនិត្យមើល Token
$stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    // ប្រសិនបើ Token មិនត្រឹមត្រូវ ឬ ផុតកំណត់
    $error = __t('invalid_or_expired_token');
}

// 2. ពិនិត្យមើលការ Submit Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    csrf_check();
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (empty($password) || $password !== $password_confirm) {
        $error = __t('passwords_do_not_match');
    } elseif (strlen($password) < 6) {
        $error = __t('password_too_short');
    } else {
        // 3. ដំណើរការជោគជ័យ
        $hash = password_hash($password, PASSWORD_DEFAULT); // Hash ពាក្យសម្ងាត់ថ្មី
        
        // Update ពាក្យសម្ងាត់ ហើយលុប Token ចោល
        $pdo->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?")
            ->execute([$hash, $user['id']]);

        $message = __t('password_reset_success');
    }
}
?>

<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appName) ?> - <?= e(__t('reset_password')) ?></title>
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

        .form-fade-in { 
            animation: fadeIn 0.8s ease-in-out; 
        }
        @keyframes fadeIn { 
            from { opacity: 0; transform: translateY(20px); } 
            to { opacity: 1; transform: translateY(0); } 
        }
    </style>
</head>
<body class="bg-slate-900 min-h-screen flex items-center justify-center p-4">

    <!-- Canvas សម្រាប់ចលនា -->
    <canvas id="digital-particles"></canvas>

    <div class="bg-white w-full max-w-md p-8 md:p-10 rounded-2xl shadow-2xl form-fade-in login-card">
        
        <h1 class="text-3xl font-bold text-center text-gray-800 mb-6">
            <?= e(__t('reset_password')) ?>
        </h1>

        <?php if($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-4" role="alert">
                <span class="block sm:inline"><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if($message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative mb-4" role="alert">
                <span class="block sm:inline"><?= e($message) ?></span>
            </div>
            <a href="index.php" 
               class="flex items-center justify-center w-full px-4 py-3 text-lg font-bold text-white bg-gradient-to-r from-blue-600 to-indigo-700 rounded-lg shadow-lg
                      hover:from-blue-700 hover:to-indigo-800 focus:outline-none focus:ring-4 focus:ring-blue-300
                      transform transition-all duration-300 ease-in-out hover:scale-[1.02]">
                <?= e(__t('login')) ?>
            </a>
        <?php endif; ?>

        <?php if($user && !$message && !$error): ?>
            <form method="post" action="" class="space-y-6">
                <?php csrf_field(); ?>
                <div class="relative">
                    <input type="password" id="password" name="password" 
                           class="block w-full px-4 py-3 text-gray-900 border border-gray-300 rounded-lg appearance-none focus:outline-none focus:ring-2 focus:ring-blue-600 focus:border-transparent peer" 
                           placeholder=" " required />
                    <label for="password" 
                           class="absolute text-gray-500 duration-300 transform -translate-y-4 scale-75 top-3 z-10 origin-[0] bg-white px-2 left-4
                                  peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 
                                  peer-focus:scale-75 peer-focus:-translate-y-4 peer-focus:text-blue-600">
                        <?= e(__t('new_password')) ?>
                    </label>
                </div>
                
                <div class="relative">
                    <input type="password" id="password_confirm" name="password_confirm" 
                           class="block w-full px-4 py-3 text-gray-900 border border-gray-300 rounded-lg appearance-none focus:outline-none focus:ring-2 focus:ring-blue-600 focus:border-transparent peer" 
                           placeholder=" " required />
                    <label for="password_confirm" 
                           class="absolute text-gray-500 duration-300 transform -translate-y-4 scale-75 top-3 z-10 origin-[0] bg-white px-2 left-4
                                  peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 
                                  peer-focus:scale-75 peer-focus:-translate-y-4 peer-focus:text-blue-600">
                        <?= e(__t('confirm_password')) ?>
                    </label>
                </div>

                <div>
                    <button type="submit" 
                            class="flex items-center justify-center w-full px-4 py-3 text-lg font-bold text-white bg-gradient-to-r from-blue-600 to-indigo-700 rounded-lg shadow-lg
                                   hover:from-blue-700 hover:to-indigo-800 focus:outline-none focus:ring-4 focus:ring-blue-300
                                   transform transition-all duration-300 ease-in-out hover:scale-[1.02]">
                        <?= e(__t('save_password')) ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>

    </div>

    <!-- Script សម្រាប់ចលនា Digital Background -->
    <script>
        const canvas = document.getElementById('digital-particles');
        const ctx = canvas.getContext('2d');
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;

        let particlesArray = [];
        const numberOfParticles = (canvas.width * canvas.height) / 9000;
        const particleColor = 'rgba(255, 255, 255, 0.6)';
        const lineColor = 'rgba(100, 180, 255, 0.25)';

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
                if (this.x > canvas.width || this.x < 0) { this.directionX = -this.directionX; }
                if (this.y > canvas.height || this.y < 0) { this.directionY = -this.directionY; }
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
                let directionX = (Math.random() * 0.4) - 0.2;
                let directionY = (Math.random() * 0.4) - 0.2;
                particlesArray.push(new Particle(x, y, directionX, directionY, size));
            }
        }

        function connect() {
            let opacityValue = 1;
            for (let a = 0; a < particlesArray.length; a++) {
                for (let b = a + 1; b < particlesArray.length; b++) {
                    let distance = Math.sqrt(
                        (particlesArray[a].x - particlesArray[b].x) * (particlesArray[a].x - particlesArray[b].x) +
                        (particlesArray[a].y - particlesArray[b].y) * (particlesArray[a].y - particlesArray[b].y)
                    );
                    if (distance < 120) {
                        opacityValue = 1 - (distance / 120);
                        ctx.strokeStyle = lineColor.replace('0.25', opacityValue);
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
            init();
        });

        init();
        animate();
    </script>
</body>
</html>
