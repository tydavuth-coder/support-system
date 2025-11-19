<?php
require_once __DIR__ . '/../../includes/functions.php'; require_once __DIR__ . '/../../includes/auth.php'; require_login();
$fmt=$_GET['fmt']??'csv'; $u=current_user(); $isCoordOrAdmin=(is_role('coordinator')||is_role('admin'));
$where="1=1"; $params=[];
if(!$isCoordOrAdmin){ $where="t.user_id=? OR t.assigned_to=?"; $params[]=$u['id']; $params[]=$u['id']; }
if(!empty($_GET['q'])){ $where.=" AND (t.title LIKE ? OR t.description LIKE ?)"; $params[]='%'.$_GET['q'].'%'; $params[]='%'.$_GET['q'].'%'; }
$sql="SELECT t.*, u.name AS user_name, a.name AS assignee_name FROM tickets t LEFT JOIN users u ON u.id=t.user_id LEFT JOIN users a ON a.id=t.assigned_to WHERE $where ORDER BY t.created_at DESC";
$stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
$filename="tickets_".date('Ymd_His');
if($fmt==='xls'){
  header("Content-Type: application/vnd.ms-excel; charset=utf-8");
  header("Content-Disposition: attachment; filename={$filename}.xls");
  echo "<table border='1'><tr><th>ID</th><th>Title</th><th>Type</th><th>Priority</th><th>Status</th><th>Due</th><th>Assigned To</th><th>Created At</th></tr>";
  foreach($rows as $t){
    $due = $t['due_at'] ?? sla_compute_due($t['created_at'],$t['priority']);
    echo "<tr><td>{$t['id']}</td><td>".e($t['title'])."</td><td>".e($t['type'])."</td><td>".e($t['priority'])."</td><td>".e($t['status'])."</td><td>".e($due)."</td><td>".e($t['assignee_name'])."</td><td>".e($t['created_at'])."</td></tr>";
  }
  echo "</table>"; exit;
}
header("Content-Type: text/csv; charset=utf-8"); header("Content-Disposition: attachment; filename={$filename}.csv");
$out=fopen("php://output","w"); fputcsv($out, ['ID','Title','Type','Priority','Status','Due','Assigned To','Created At']);
foreach($rows as $t){ $due = $t['due_at'] ?? sla_compute_due($t['created_at'],$t['priority']); fputcsv($out, [$t['id'],$t['title'],$t['type'],$t['priority'],$t['status'],$due,$t['assignee_name'],$t['created_at']]); }
fclose($out); exit;
