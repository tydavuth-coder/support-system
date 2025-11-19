<?php 
session_start(); // FIX 1: បន្ថែម session_start() នៅខាងលើគេបង្អស់

// FIX 2: បន្ថែម auth.php 
require_once __DIR__ . '/../../includes/functions.php'; 
require_once __DIR__ . '/../../includes/auth.php'; // ត្រូវតែ include ឯកសារនេះ

require_login(); 
$u = current_user(); 

// Set content type to HTML
header('Content-Type: text/html; charset=utf-8');

$s = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20'); 
$s->execute([$u['id']]); 
$ns = $s->fetchAll(); 

if(!$ns){ 
    echo '<div class="empty">'.__t('no_notifications').'</div>'; 
    exit; 
} 

foreach($ns as $n){ 
    // ប្រើ e($n['link']) គឺត្រឹមត្រូវ ព្រោះ Link គឺជា URL ពេញ
    $link = $n['link'] ? '<a href="'.e($n['link']).'" target="_blank">'.__t('view').'</a>' : ''; 
    
    // FIX 3: ប្រើ time_ago() ជំនួស e($n['created_at'])
    echo '<div class="item">'.e($n['message']).' <div class="small">'.time_ago($n['created_at'])." $link</div></div>'; 
}
?>