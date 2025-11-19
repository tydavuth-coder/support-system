<?php
require_once __DIR__ . '/../../includes/functions.php'; require_once __DIR__ . '/../../includes/csrf.php'; require_login(); if(!(is_role('coordinator')||is_role('admin'))) die('Forbidden'); csrf_check();
$id=(int)($_POST['id']??0); if(!$id) die('Bad request');
$pdo->prepare("UPDATE tickets SET escalation_level = escalation_level + 1, last_escalated_at = NOW() WHERE id=?")->execute([$id]);
log_activity($id,'escalated',['by'=>'manual']);
$admins=$pdo->query("SELECT id,email,name,phone FROM users WHERE role IN ('admin','coordinator')")->fetchAll();
foreach($admins as $a){ notify_inapp($a['id'],"[SLA] Ticket #$id manual escalation", base_url("ticket_view.php?id=$id")); @notify_email($a['email'],$a['name'],"[SLA] Ticket #$id manual escalation","<p>Please review.</p>"); }
header('Location: ../ticket_view.php?id='.$id);
