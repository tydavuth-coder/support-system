<?php
use Shuchkin\SimpleXLSXGen;
require_once __DIR__ . '/../includes/functions.php'; require_once __DIR__ . '/../includes/auth.php'; require_login();
$entity = $_GET['entity'] ?? 'tickets'; $format = strtolower($_GET['format'] ?? 'csv');
if($entity==='tickets'){
  $where="1=1"; $params=[];
  if(!empty($_GET['q'])){ $where.=" AND (t.title LIKE ? OR t.description LIKE ?)"; $params[]='%'.$_GET['q'].'%'; $params[]='%'.$_GET['q'].'%'; }
  if(!empty($_GET['date_from'])){ $where.=" AND DATE(t.created_at)>=?"; $params[]=$_GET['date_from']; }
  if(!empty($_GET['date_to'])){ $where.=" AND DATE(t.created_at)<=?"; $params[]=$_GET['date_to']; }
  if(!empty($_GET['status'])){ $where.=" AND t.status=?"; $params[]=$_GET['status']; }
  if(!empty($_GET['staff'])){ $where.=" AND (t.assigned_to=? OR t.user_id=?)"; $params[]=$_GET['staff']; $params[]=$_GET['staff']; }
  $sql="SELECT t.*, u.name AS user_name, a.name AS assignee_name FROM tickets t LEFT JOIN users u ON u.id=t.user_id LEFT JOIN users a ON a.id=t.assigned_to WHERE $where ORDER BY t.created_at DESC";
  $stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
  $filename='tickets-'.date('Y-m-d');

  if($format==='xls'){
    header('Content-Type: application/vnd.ms-excel'); header("Content-Disposition: attachment; filename={$filename}.xls");
    echo "<table border='1'><tr><th>ID</th><th>Title</th><th>Type</th><th>Priority</th><th>Status</th><th>User</th><th>Assignee</th><th>SLA Due</th><th>Created</th><th>Updated</th></tr>";
    foreach($rows as $r){ echo "<tr><td>{$r['id']}</td><td>".e($r['title'])."</td><td>".e($r['type'])."</td><td>".e($r['priority'])."</td><td>".e($r['status'])."</td><td>".e($r['user_name'])."</td><td>".e($r['assignee_name'])."</td><td>".e($r['sla_due_at'])."</td><td>{$r['created_at']}</td><td>{$r['updated_at']}</td></tr>"; }
    echo "</table>"; exit;
  } elseif($format==='xlsx'){
    $data=[['ID','Title','Type','Priority','Status','User','Assignee','SLA Due','Created','Updated']];
    foreach($rows as $r){ $data[]=[(int)$r['id'],$r['title'],$r['type'],$r['priority'],$r['status'],$r['user_name'],$r['assignee_name'],$r['sla_due_at'],$r['created_at'],$r['updated_at']]; }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header("Content-Disposition: attachment; filename={$filename}.xlsx");
    $xlsx = SimpleXLSXGen::fromArray($data);
    echo $xlsx->downloadAs($filename.'.xlsx');
    exit;
  } else {
    header('Content-Type: text/csv'); header("Content-Disposition: attachment; filename={$filename}.csv");
    $out=fopen('php://output','w'); fputcsv($out, ['ID','Title','Type','Priority','Status','User','Assignee','SLA Due','Created','Updated']);
    foreach($rows as $r){ fputcsv($out, [$r['id'],$r['title'],$r['type'],$r['priority'],$r['status'],$r['user_name'],$r['assignee_name'],$r['sla_due_at'],$r['created_at'],$r['updated_at']]); }
    fclose($out); exit;
  }
}
http_response_code(404); echo 'Not found';
