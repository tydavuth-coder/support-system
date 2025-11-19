<?php
if (session_status()===PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php'; require_once __DIR__ . '/functions.php';
function attempt_login($email,$password){
  global $pdo;
  $s=$pdo->prepare("SELECT * FROM users WHERE email=? LIMIT 1"); $s->execute([$email]); $u=$s->fetch();
  if(!$u) return false;
  $ok=false;
  if(strlen($u['password_hash'])<55){ $ok=($password===$u['password_hash']); if($ok){ $hash=password_hash($password,PASSWORD_DEFAULT); $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash,$u['id']]); $u['password_hash']=$hash; } }
  else { $ok=password_verify($password,$u['password_hash']); }
  if(!$ok) return false;
  $_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'phone'=>$u['phone'],'role'=>$u['role'],'avatar'=>$u['avatar']??null];
  return true;
}
function logout(){ $_SESSION=[]; session_destroy(); }