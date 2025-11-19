<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
$vendor = __DIR__ . '/../vendor/autoload.php'; if (file_exists($vendor)) require_once $vendor;
$config = require __DIR__ . '/../config.php';

// --- Existing Functions (រក្សាទុកដដែល) ---

function current_lang(){
  global $config,$pdo;
  if(isset($_GET['setlang'])) $_SESSION['lang']=$_GET['setlang']=='en'?'en':'km';
  if(!empty($_SESSION['lang'])) return $_SESSION['lang'];
  $stmt=$pdo->prepare("SELECT value FROM settings WHERE `key`='default_lang'");
  if($stmt->execute() && ($row=$stmt->fetch())){ $_SESSION['lang']=in_array($row['value'],['en','km'])?$row['value']:$config['app']['default_lang']; }
  else $_SESSION['lang']=$config['app']['default_lang'];
  return $_SESSION['lang'];
}

// FIX: Corrected path back to i18n and kept placeholder support
function __t($k, $params = []){ 
    static $T=null; 
    $lang=current_lang(); 
    if($T===null||($T['_lang']??null)!==$lang){ 
        $p=__DIR__."/i18n/{$lang}.php"; // <-- Path ត្រឹមត្រូវរបស់អ្នក
        if(!file_exists($p)) $p=__DIR__."/i18n/km.php"; // <-- Path ត្រឹមត្រូវរបស់អ្នក
        $T=require $p; 
        $T['_lang']=$lang; 
    } 
    $text = $T[$k]??$k; 
    if (!empty($params) && is_array($params)) { 
        foreach ($params as $key => $value) { 
            $text = str_replace('%' . $key . '%', $value, $text); 
        } 
    } 
    return $text; 
}
function e($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }
function base_url($p=''){ global $config; $b=rtrim($config['app']['base_url'],'/'); return $b.($p?'/'.ltrim($p,'/'):''); }
function current_user(){ return $_SESSION['user']??null; }
function is_role($role){ $u=current_user(); if(!$u) return false; $order=['user'=>1,'technical'=>2,'coordinator'=>3,'admin'=>4]; return isset($order[$u['role']],$order[$role]) && $order[$u['role']]>= $order[$role]; }
function require_login(){ if(!current_user()){ header('Location: '.base_url('index.php')); exit; } }
function require_role($role){ if(!is_role($role)){ http_response_code(403); die('Forbidden'); } }
function get_setting($k,$d=''){ global $pdo; $s=$pdo->prepare("SELECT value FROM settings WHERE `key`=?"); $s->execute([$k]); $v=$s->fetchColumn(); return $v!==false?$v:$d; }
function set_setting($k,$v){ global $pdo; $s=$pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)"); $s->execute([$k,$v]); }
function notify_inapp($uid,$msg,$link=null){ global $pdo; $pdo->prepare("INSERT INTO notifications(user_id,message,link,is_read,created_at) VALUES(?,?,?,0,NOW())")->execute([$uid,$msg,$link]); }
function notify_email($to,$name,$sub,$html){ if(!class_exists('PHPMailer\PHPMailer\PHPMailer')) return false; $mail=new PHPMailer\PHPMailer\PHPMailer(true); $mail->isSMTP(); $mail->Host=get_setting('smtp_host',''); $mail->Port=(int)get_setting('smtp_port','587'); $mail->SMTPAuth=!empty(get_setting('smtp_user','')); if($mail->SMTPAuth){ $mail->Username=get_setting('smtp_user',''); $mail->Password=get_setting('smtp_pass',''); } $enc=get_setting('smtp_encryption','tls'); if(in_array($enc,['ssl','tls'])) $mail->SMTPSecure=$enc; $mail->setFrom(get_setting('smtp_user','no-reply@example.com'),'Support System'); $mail->addAddress($to,$name?:$to); $mail->isHTML(true); $mail->CharSet = 'UTF-8'; // Ensure UTF-8 for Khmer characters
$mail->Subject=$sub; $mail->Body=$html; $mail->AltBody=strip_tags($html); try{$mail->send();return true;}catch(Exception $e){/* Log error: error_log("Mailer Error: " . $mail->ErrorInfo);*/ return false;} }
function notify_sms($to,$msg){ if(!class_exists('Twilio\Rest\Client')) return false; $sid=get_setting('twilio_sid',''); $token=get_setting('twilio_token',''); $from=get_setting('twilio_from',''); if(!$sid||!$token||!$from) return false; try{$client=new Twilio\Rest\Client($sid,$token); $client->messages->create($to,['from'=>$from,'body'=>$msg]); return true;}catch(Exception $e){/* Log error: error_log("Twilio Error: " . $e->getMessage());*/ return false;} }
function log_activity($ticket_id,$action,$meta=[]){ global $pdo; $uid=current_user()['id']??null; $m=$meta?json_encode($meta,JSON_UNESCAPED_UNICODE):null; $pdo->prepare("INSERT INTO ticket_activity(ticket_id,action,meta,created_by,created_at) VALUES(?,?,?,?,NOW())")->execute([$ticket_id,$action,$m,$uid]); }
function get_logo_url(){ $p=get_setting('site_logo',''); if($p && !preg_match('~^https?://~',$p)) return base_url($p); return $p; }
function get_font_file(){ $p=get_setting('font_file',''); if($p && !preg_match('~^https?://~',$p)) return base_url($p); return $p; }
function random_filename($ext=''){ $n=bin2hex(random_bytes(8)); if($ext) $n.='.'.ltrim($ext,'.'); return $n; }


/**
 * Encrypts the 2FA secret key.
 */
function encrypt_2fa_secret($plain_secret) {
    if (!defined('APP_2FA_ENCRYPTION_KEY')) {
        throw new Exception('Encryption key is not defined.');
    }
    $key = APP_2FA_ENCRYPTION_KEY;
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($plain_secret, 'aes-256-cbc', $key, 0, $iv);
    // Return IV + Encrypted data, Base64 encoded
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypts the 2FA secret key.
 */
function decrypt_2fa_secret($encrypted_secret) {
    if (!defined('APP_2FA_ENCRYPTION_KEY')) {
        throw new Exception('Encryption key is not defined.');
    }
    $key = APP_2FA_ENCRYPTION_KEY;
    $data = base64_decode($encrypted_secret);
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $iv_length);
    $encrypted = substr($data, $iv_length);
    return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
}

// --- ***** time_ago() FUNCTION (UPDATED) ***** ---
function time_ago($time, $suffix = ' ago') {
    if (!is_numeric($time)) {
        $time = strtotime($time);
        if ($time === false) {
            return 'invalid date'; 
        }
    }

    $current_time = time();
    $time_difference = $current_time - $time;
    $seconds = $time_difference;

    // Add translation keys for time units
    $trans = [
        'just_now' => __t('just_now'),
        'second' => __t('second'), 
        'seconds' => __t('seconds'), 
        'minute' => __t('minute'),
        'minutes' => __t('minutes'),
        'hour' => __t('hour'),
        'hours' => __t('hours'),
        'day' => __t('day'),
        'days' => __t('days'),
        'week' => __t('week'),
        'weeks' => __t('weeks'),
        'month' => __t('month'),
        'months' => __t('months'),
        'year' => __t('year'),
        'years' => __t('years'),
        'yesterday' => __t('yesterday'),
        'ago' => __t('ago'), 
    ];
    
    $actual_suffix = ($suffix === ' ago') ? (' ' . $trans['ago']) : $suffix;
    
    // Make sure $seconds is an integer
    $seconds = (int)$seconds;

    $minutes      = round($seconds / 60);           
    $hours        = round($seconds / 3600);           
    $days         = round($seconds / 86400);          
    $weeks        = round($seconds / 604800);         
    $months       = round($seconds / 2629440);        
    $years        = round($seconds / 31553280);        

    // ***** UPDATED LOGIC (Shows "seconds") *****
    if ($seconds < 10) { 
        return $trans['just_now'];
    } else if ($seconds <= 60) {
        // នេះគឺជា Logic ថ្មី
        return $seconds . ' ' . $trans['seconds'] . $actual_suffix;
    } else if ($minutes <= 60) {
        return ($minutes == 1) ? '1 ' . $trans['minute'] . $actual_suffix : $minutes . ' ' . $trans['minutes'] . $actual_suffix;
    } else if ($hours <= 24) {
        return ($hours == 1) ? '1 ' . $trans['hour'] . $actual_suffix : $hours . ' ' . $trans['hours'] . $actual_suffix;
    } else if ($days <= 7) {
        return ($days == 1) ? $trans['yesterday'] : $days . ' ' . $trans['days'] . $actual_suffix;
    } else if ($weeks <= 4.3) { 
        return ($weeks == 1) ? '1 ' . $trans['week'] . $actual_suffix : $weeks . ' ' . $trans['weeks'] . $actual_suffix;
    } else if ($months <= 12) {
        return ($months == 1) ? '1 ' . $trans['month'] . $actual_suffix : $months . ' ' . $trans['months'] . $actual_suffix;
    } else {
        return ($years == 1) ? '1 ' . $trans['year'] . $actual_suffix : $years . ' ' . $trans['years'] . $actual_suffix;
    }
    // ***** END UPDATED LOGIC *****
}
// --- ចប់ Function ថ្មី ---

?>