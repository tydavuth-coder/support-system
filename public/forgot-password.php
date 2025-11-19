<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
// យើងត្រូវការព័ត៌មាន PHPMailer ពី composer
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$lang = current_lang();
$appName = $lang === 'km' ? $config['app']['name_km'] : $config['app']['name_en'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');

    // 1. ស្វែងរកអ្នកប្រើប្រាស់
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        try {
            // 2. បង្កើត Token
            $token = bin2hex(random_bytes(16)); // 32-character token
            // $expires = date('Y-m-d H:i:s', time() + 3600); // We will let MySQL handle this

            // 3. រក្សាទុក Token ក្នុង Database
            // ***** FIX: Use MySQL's NOW() + INTERVAL 1 HOUR *****
            // This is more reliable than PHP's date() function
            $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = NOW() + INTERVAL 1 HOUR WHERE id = ?")
                ->execute([$token, $user['id']]); // Removed $expires
            // ***** END FIX *****

            // 4. បង្កើតតំណ (Link)
            $resetLink = base_url('reset-password.php?token=' . $token);

            // 5. ផ្ញើ Email (SMTP Settings របស់អ្នក)
            $mail = new PHPMailer(true);
            
            // --- SMTP Configuration ---
            // $mail->SMTPDebug = 2; 
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com'; 
            $mail->SMTPAuth   = true;
            $mail->Username   = 'ty.davuth2026@gmail.com'; 
            $mail->Password   = 'dife jaee fzxb elfn'; // (ខ្ញុំបានឃើញ App Password របស់អ្នកហើយ)
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
            $mail->CharSet    = 'UTF-8';
            // --- ចប់ការកំណត់ ---

            $mail->setFrom('no-reply@yourdomain.com', $appName);
            $mail->addAddress($email, $user['name']);

            $mail->isHTML(true);
            $mail->Subject = __t('password_reset_subject');
            
            // Build HTML Body
            $linkText = __t('password_reset_link_text');
            $resetLinkHtml = '<a href="' . e($resetLink) . '" style="background-color: #2563eb; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; font-weight: bold;">' . e($linkText) . '</a>';
            $greeting = str_replace('%name%', e($user['name']), __t('password_reset_greeting'));

            $mail->Body    = 
                '<div style="font-family: Arial, sans-serif; line-height: 1.6;">' .
                '<p>' . $greeting . '</p>' .
                '<p>' . __t('password_reset_line1') . '</p>' .
                '<p style="margin: 25px 0;">' . $resetLinkHtml . '</p>' .
                '<p>' . __t('password_reset_line2') . '</p>' .
                '</div>';
            
            $mail->AltBody = $greeting . "\n\n" .
                             __t('password_reset_line1') . "\n" .
                             $resetLink . "\n\n" .
                             __t('password_reset_line2');

            $mail->send();
            $message = __t('reset_link_sent');

        } catch (Exception $e) {
            $error = __t('email_send_error') . ': ' . $mail->ErrorInfo;
            error_log("PHPMailer Error: " . $mail->ErrorInfo); 
        }
    } else {
        $message = __t('reset_link_sent');
    }
}
?>

<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($appName) ?> - <?= e(__t('forgot_password')) ?></title>
	<link rel="icon" href="<?= base_url('assets/img/logo.png') ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;700&display=swap');
        body { 
            font-family: 'Kantumruy Pro', 'Helvetica Neue', Arial, sans-serif;
        }
        #digital-particles {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 0;
        }
        .login-card {
            position: relative; z-index: 10;
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

    <canvas id="digital-particles"></canvas>

    <div class="bg-white w-full max-w-md p-8 md:p-10 rounded-2xl shadow-2xl form-fade-in login-card">
        
        <h1 class="text-3xl font-bold text-center text-gray-800 mb-2">
            <?= e(__t('ភ្លេចពាក្យសម្ងាត់')) ?>
        </h1>
        <p class="text-center text-gray-500 mb-6">
            <?= e(__t('forgot_password')) ?>
        </p>

        <?php if($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-4" role="alert">
                <span class="block sm:inline"><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if($message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative mb-4" role="alert">
                <span class="block sm:inline"><?= e($message) ?></span>
            </div>
            <a href="index.php" class="text-sm font-medium text-blue-600 hover:underline">&larr; <?= e(__t('Back to login')) ?></a>
        <?php else: ?>
            <form method="post" action="" class="space-y-6">
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
                <div>
                    <button type="submit" 
                            class="flex items-center justify-center w-full px-4 py-3 text-lg font-bold text-white bg-gradient-to-r from-blue-600 to-indigo-700 rounded-lg shadow-lg
                                   hover:from-blue-700 hover:to-indigo-800 focus:outline-none focus:ring-4 focus:ring-blue-300
                                   transform transition-all duration-300 ease-in-out hover:scale-[1.02]">
                        <?= e(__t('Send reset link')) ?>
                    </button>
                </div>
            </form>
            <div class="text-center mt-4">
                <a href="index.php" class="text-sm font-medium text-blue-600 hover:underline">&larr; <?= e(__t('Back to login')) ?></a>
            </div>
        <?php endif; ?>
    </div>

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