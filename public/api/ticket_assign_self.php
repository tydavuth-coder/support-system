<?php require_once __DIR__ . '/../../includes/functions.php'; require_once __DIR__ . '/../../includes/csrf.php'; require_login(); csrf_check();
$u=current_user(); if(($u['role']??'')!=='technical' && !is_role('coordinator') && !is_role('admin')){ http_response_code(403); die('Forbidden'); }
$id=(int)($_POST['id']??0); if(!$id){ http_response_code(400); die('Bad request'); }
$s=$pdo->prepare("SELECT assigned_to FROM tickets WHERE id=?"); $s->execute([$id]); $cur=$s->fetchColumn();
if(!$cur){ $pdo->prepare("UPDATE tickets SET assigned_to=?, updated_at=NOW() WHERE id=?")->execute([$u['id'],$id]); log_activity($id,'assigned',['assigned_to'=>$u['id'],'assigned_self'=>true]); }
header('Location: ../ticket_view.php?id='.$id);